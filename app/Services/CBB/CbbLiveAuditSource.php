<?php

namespace App\Services\CBB;

use App\Models\CBB\Game;
use App\Models\LivePredictionSnapshot;
use App\Services\Predictions\CanonicalShadowInputs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CbbLiveAuditSource
{
    private const ACTIVE = ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD', 'STATUS_SUSPENDED'];

    /** @return array{final:array<string,mixed>,current:Collection} */
    public function report(?int $season = null): array
    {
        $final = ['final_predictions' => 0, 'final_rows_with_live_fields' => 0];
        $samples = array_fill_keys(['brier_score', 'live_spread_mae', 'live_total_mae'], ['sum' => 0.0, 'count' => 0]);
        $current = collect();
        $query = Game::query()->with(['homeTeam:id,abbreviation', 'awayTeam:id,abbreviation'])
            ->whereIn('status', [...self::ACTIVE, 'STATUS_FINAL'])
            ->when($season !== null, fn ($query) => $query->where('season', $season));
        foreach ($query->lazyById(250)->chunk(25) as $batch) {
            $games = $batch->collect();
            foreach ([true, false] as $completed) {
                $scope = $games->filter(fn (Game $game) => ($game->status === 'STATUS_FINAL') === $completed
                    && (! $completed || (is_numeric($game->home_score) && is_numeric($game->away_score))));
                if ($scope->isEmpty()) {
                    continue;
                }
                $snapshots = LivePredictionSnapshot::query()->where('sport', 'cbb')->where('game_table', 'cbb_games')
                    ->whereIn('game_id', $scope->pluck('id'))
                    ->where('id', function ($query) use ($completed): void {
                        $query->select('recent.id')->from('live_prediction_snapshots as recent')
                            ->whereColumn('recent.game_id', 'live_prediction_snapshots.game_id')
                            ->whereColumn('recent.game_table', 'live_prediction_snapshots.game_table')
                            ->whereColumn('recent.sport', 'live_prediction_snapshots.sport')
                            ->where('recent.observed_at', '<=', now())
                            ->when($completed, fn ($query) => $query->where('recent.status', 'live'))
                            ->orderByDesc('recent.observed_at')->orderByDesc('recent.id')->limit(1);
                    })->with(['canonicalPrediction.markets', 'canonicalPrediction.calculationRun.inputSnapshot',
                        'canonicalPrediction.calculationRun.release', 'canonicalPrediction.sportEvent.cbbGame'])->get()->keyBy('game_id');
                foreach ($scope as $game) {
                    $snapshot = $snapshots->get($game->id);
                    $fields = $this->fields($snapshot, $game);
                    $hasFields = collect($fields)->only(['live_win_probability', 'live_predicted_spread', 'live_predicted_total'])->contains(fn ($value) => $value !== null);
                    if ($completed) {
                        $final['final_predictions']++;
                        $final['final_rows_with_live_fields'] += (int) $hasFields;
                        $margin = (float) $game->home_score - (float) $game->away_score;
                        if ($fields['live_win_probability'] !== null) {
                            $samples['brier_score']['count']++;
                            $samples['brier_score']['sum'] += ($fields['live_win_probability'] - ($margin > 0 ? 1 : 0)) ** 2;
                        }
                        if ($fields['live_predicted_spread'] !== null) {
                            $samples['live_spread_mae']['count']++;
                            $samples['live_spread_mae']['sum'] += abs($fields['live_predicted_spread'] - $margin);
                        }
                        if ($fields['live_predicted_total'] !== null) {
                            $samples['live_total_mae']['count']++;
                            $samples['live_total_mae']['sum'] += abs($fields['live_predicted_total'] - ((float) $game->home_score + (float) $game->away_score));
                        }

                        continue;
                    }
                    $current->push([...$fields, 'game' => ($game->awayTeam?->abbreviation ?? '?').' @ '.($game->homeTeam?->abbreviation ?? '?'),
                        'status' => $game->status, 'score' => ($game->home_score ?? 0).'-'.($game->away_score ?? 0),
                        'clock' => trim(($game->period ? 'P'.$game->period.' ' : '').($game->game_clock ?? '')),
                        'has_live_fields' => $hasFields]);
                }
            }
        }
        foreach ($samples as $metric => $values) {
            $final[$metric] = $values['count'] === 0 ? null : $values['sum'] / $values['count'];
            $final[$metric.'_sample'] = $values['count'];
        }

        return ['final' => $final, 'current' => $current->sortByDesc('live_updated_at')->values()];
    }

    private function fields(?LivePredictionSnapshot $snapshot, Game $game): array
    {
        $empty = ['live_win_probability' => null, 'live_predicted_spread' => null, 'live_predicted_total' => null,
            'live_seconds_remaining' => null, 'live_updated_at' => null];
        $prediction = $snapshot?->canonicalPrediction;
        if (! $snapshot || $snapshot->status !== 'live' || ! $prediction || $prediction->sport !== 'cbb'
            || ($snapshot->baseline['prediction_source'] ?? null) !== 'canonical'
            || ($snapshot->baseline['pinned'] ?? false) !== true
            || (int) ($snapshot->baseline['canonical_prediction_id'] ?? 0) !== (int) $prediction->id
            || ($snapshot->baseline['calculation_run_id'] ?? null) !== $prediction->calculation_run_id
            || ! in_array($snapshot->state['status'] ?? null, self::ACTIVE, true)
            || $snapshot->observed_at->gt($game->updated_at)) {
            return $empty;
        }
        $safe = app(CanonicalShadowInputs::class)->fromPrediction($prediction);
        if (! $safe || $safe->game_id !== $game->id || $snapshot->observed_at->lt($safe->game_start_at)
            || ! $prediction->published_at->lt($safe->game_start_at)) {
            return $empty;
        }
        $input = $prediction->calculationRun->inputSnapshot;
        $frozenStart = data_get($input->inputs, 'event.starts_at');
        try {
            $matches = is_string($frozenStart) && CarbonImmutable::parse($frozenStart)->eq($safe->game_start_at);
        } catch (\Throwable) {
            $matches = false;
        }
        if (! $matches || $input->latest_source_available_at?->gt($prediction->generated_at)) {
            return $empty;
        }
        foreach (['live_win_probability', 'live_predicted_spread', 'live_predicted_total', 'live_seconds_remaining'] as $key) {
            $value = $snapshot->projection[$key] ?? null;
            $empty[$key] = is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
        }
        if ($empty['live_win_probability'] !== null && ($empty['live_win_probability'] < 0 || $empty['live_win_probability'] > 1)) {
            $empty['live_win_probability'] = null;
        }
        $empty['live_updated_at'] = $snapshot->observed_at->toDateTimeString();

        return $empty;
    }
}
