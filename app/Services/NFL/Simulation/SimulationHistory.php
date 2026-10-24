<?php

namespace App\Services\NFL\Simulation;

use App\Models\NFL\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\SnapshotProvenanceResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class SimulationHistory
{
    public function source(Game $game, CarbonInterface $asOf): ?PredictionFeatureSnapshot
    {
        $asOf = $asOf->copy()->setTimezone(config('app.timezone'));
        $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($game)?->setTimezone(config('app.timezone'));
        if (! $kickoff) {
            return null;
        }

        return $this->observed()->where('game_id', $game->id)
            ->where('generated_at', '<=', $asOf)->where('features_available_at', '<=', $asOf)
            ->where('generated_at', '>=', $asOf->copy()->subHours((int) config('nfl_game_simulation.forecast_max_age_hours', 36)))
            ->where('generated_at', '<', $kickoff)->where('features_available_at', '<', $kickoff)
            ->latest('id')->first();
    }

    public function fit(Game $target, PredictionFeatureSnapshot $source, string $basis, CarbonInterface $asOf): array
    {
        $asOf = $asOf->copy()->setTimezone(config('app.timezone'));
        $mature = $asOf->copy()->subHours((int) config('nfl_game_simulation.result_maturity_hours', 24));
        $limit = min(1024, max(1, (int) config('nfl_game_simulation.history_limit', 512)));
        $games = Game::where('id', '!=', $target->id)->where('status', 'STATUS_FINAL')->whereIn('season_type', ['2', 'regular'])
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->where('result_updated_at', '<=', $mature)
            ->where('game_date', '<=', $mature->copy()->utc()->toDateString())->with('sportEvent')
            ->orderByDesc('game_date')->orderByDesc('id')->limit($limit)->get()->keyBy('id');
        $eligible = $this->observed()->whereIn('game_id', $games->keys())->where('model_version', $source->model_version)
            ->where('feature_version', $source->feature_version)->where('blend_version', $source->blend_version)
            ->where('generated_at', '<=', $asOf)->where('features_available_at', '<=', $asOf);
        $latest = (clone $eligible)->selectRaw('MAX(id)')->groupBy('game_id');
        $snapshots = PredictionFeatureSnapshot::whereIn('id', $latest)->latest('id')->limit($limit)->get();
        $pairs = [];
        $ids = [];
        $seen = [];
        $resultVersions = [];
        foreach ($snapshots as $snapshot) {
            $g = $games->get($snapshot->game_id);
            $kickoff = $g ? app(SnapshotProvenanceResolver::class)->gameStartAt($g)?->setTimezone(config('app.timezone')) : null;
            $means = $this->means($snapshot, $basis);
            if (! $g || isset($seen[$g->id]) || ! $kickoff || ! $kickoff->lt($mature)
                || $snapshot->generated_at->gte($kickoff)
                || $snapshot->features_available_at->gte($kickoff) || ! $means
                || $g->home_score < 0 || $g->away_score < 0 || $g->home_score > 200 || $g->away_score > 200) {
                continue;
            }
            $pairs[] = [$g->home_score - ($means['total'] + $means['margin']) / 2,
                $g->away_score - ($means['total'] - $means['margin']) / 2];
            $ids[] = $snapshot->id;
            $resultVersions[] = $g->result_updated_at->toIso8601String();
            $seen[$g->id] = true;
        }

        return ['pairs' => $pairs, 'snapshot_ids' => $ids, 'game_ids' => array_keys($seen), 'result_versions' => $resultVersions, 'cutoff' => $asOf->toIso8601String(),
            'mature_before' => $mature->toIso8601String(), 'model_version' => $source->model_version, 'feature_version' => $source->feature_version, 'blend_version' => $source->blend_version, 'basis' => $basis];
    }

    public function means(PredictionFeatureSnapshot $snapshot, string $basis): ?array
    {
        $values = $basis === 'pre_market' ? data_get($snapshot->model_metadata, 'pre_market_outputs', []) : $snapshot->outputs;
        $m = $values['predicted_spread'] ?? null;
        $t = $values['predicted_total'] ?? null;
        if (! is_numeric($m) || ! is_numeric($t) || ! is_finite((float) $m) || ! is_finite((float) $t) || $t < abs($m) || $t > 200) {
            return null;
        }

        return ['margin' => (float) $m, 'total' => (float) $t];
    }

    private function observed(): Builder
    {
        return PredictionFeatureSnapshot::where('sport', 'nfl')->where('prediction_table', 'nfl_predictions')
            ->where(fn ($q) => $q->whereNull('blend_version')->orWhere('blend_version', '!=', GameSimulationService::POLICY))
            ->where('pregame_safe', true)->where('availability_status', 'observed_pregame')
            ->whereNotNull('generated_at')->whereNotNull('features_available_at')->whereNotNull('game_start_at')
            ->whereColumn('features_available_at', '<=', 'generated_at')
            ->where(fn ($q) => $q->whereNull('lineage_metadata->run_type')->orWhere('lineage_metadata->run_type', '!=', 'historical_reconstruction'));
    }
}
