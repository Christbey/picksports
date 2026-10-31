<?php

namespace App\Services\CFB;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Prediction;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Predictions\CanonicalShadowInputs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CfbPreseasonReadinessForecastSource
{
    public function name(?string $requested = null): string
    {
        $source = $requested ?? ((config('prediction_lifecycle.canonical_pipeline.cfb') || config('prediction_lifecycle.canonical_reads.cfb')) ? 'canonical' : 'legacy');
        if (! in_array($source, ['canonical', 'legacy'], true)) {
            throw new \InvalidArgumentException('Forecast source must be canonical or legacy.');
        }

        return $source;
    }

    /** @return Collection<int, object> */
    public function forSeasons(int $fromSeason, int $toSeason, string $source): Collection
    {
        $rows = collect();
        if ($this->name($source) === 'legacy') {
            Prediction::query()->select(['id', 'game_id', 'predicted_spread', 'win_probability', 'home_elo', 'away_elo',
                'home_fpi', 'away_fpi', 'spread_error', 'winner_correct', 'created_at'])
                ->with(['game:id,season,week,status,home_score,away_score,home_team_id,away_team_id', 'game.homeTeam', 'game.awayTeam'])
                ->whereHas('game', fn ($query) => $query->whereBetween('season', [$fromSeason, $toSeason]))
                ->orderByDesc('created_at')->orderByDesc('id')->chunk(100, function (Collection $predictions) use ($rows): void {
                    foreach ($predictions as $prediction) {
                        $rows->push($prediction);
                    }
                });

            return $rows;
        }

        app(CanonicalSportPredictionQuery::class)->queryForSport('cfb')
            ->whereBetween('cfb_games.season', [$fromSeason, $toSeason])
            ->where('predictions.generated_at', '<=', now())->where('predictions.published_at', '<=', now())
            ->withOnly(['markets', 'calculationRun:id,event_input_snapshot_id,calculation_release_id,status',
                'calculationRun.inputSnapshot', 'calculationRun.release:id,status', 'sportEvent:id,starts_at',
                'sportEvent.cfbGame:id,sport_event_id,season,week,status,home_score,away_score,home_team_id,away_team_id',
                'sportEvent.cfbGame.homeTeam', 'sportEvent.cfbGame.awayTeam'])
            ->chunk(25, function (Collection $predictions) use ($rows): void {
                foreach ($predictions as $prediction) {
                    $row = $this->canonical($prediction);
                    if ($row !== null && ! $rows->has($row->game->id)) {
                        $rows->put($row->game->id, $row);
                    }
                }
            });

        return $rows->sortByDesc(fn (object $row): array => [$row->created_at->timestamp, $row->id])->values();
    }

    private function canonical(CanonicalPrediction $prediction): ?object
    {
        $snapshot = app(CanonicalShadowInputs::class)->fromPrediction($prediction);
        $input = $prediction->calculationRun?->inputSnapshot;
        $inputs = (array) $input?->inputs;
        $start = data_get($inputs, 'event.starts_at');
        try {
            $matches = is_string($start) && CarbonImmutable::parse($start)->eq($prediction->sportEvent?->starts_at);
        } catch (\Throwable) {
            $matches = false;
        }
        $spread = $this->number($snapshot?->outputs['predicted_spread'] ?? null);
        $probability = $this->number($snapshot?->outputs['win_probability'] ?? null);
        if (! $snapshot || ! $matches || $spread === null
            || $prediction->published_at->gte($snapshot->game_start_at)
            || ($input->latest_source_available_at && $input->latest_source_available_at->gt($prediction->generated_at))) {
            return null;
        }

        return (object) [
            'id' => $prediction->id, 'game' => $prediction->sportEvent->cfbGame,
            'predicted_spread' => $spread, 'win_probability' => $probability !== null && $probability >= 0 && $probability <= 1 ? $probability : null,
            'home_elo' => $this->number(data_get($inputs, 'home.elo')), 'away_elo' => $this->number(data_get($inputs, 'away.elo')),
            'home_fpi' => $this->number(data_get($inputs, 'home.fpi') ?? data_get($inputs, 'home.metrics.fpi')),
            'away_fpi' => $this->number(data_get($inputs, 'away.fpi') ?? data_get($inputs, 'away.metrics.fpi')),
            'spread_error' => null, 'winner_correct' => null, 'created_at' => $prediction->generated_at,
            'canonical_lineage' => $snapshot->lineage,
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
