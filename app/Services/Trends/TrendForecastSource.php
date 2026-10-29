<?php

namespace App\Services\Trends;

use App\Application\Sports\ReadModels\BettingForecast;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Predictions\CanonicalShadowInputs;
use App\Services\Predictions\PredictionDeliverySource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TrendForecastSource
{
    public function __construct(
        private readonly PredictionDeliverySource $delivery,
        private readonly CanonicalSportPredictionQuery $canonical,
        private readonly CanonicalShadowInputs $inputs,
    ) {}

    /** @param Collection<int, Model> $games */
    public function preload(Collection $games, string $sport, ?CarbonInterface $before = null, bool $strictLegacy = false): void
    {
        $canonical = $this->delivery->usesCanonical($sport);
        $mode = $canonical ? 'canonical' : 'legacy';
        $pending = $games->filter(fn (Model $game): bool => $before !== null || $strictLegacy
            || ! $game->relationLoaded('trendForecast') || ($game->getRelations()['trendForecastSource'] ?? null) !== $mode);
        if (! $canonical) {
            foreach ($pending as $game) {
                $forecast = $this->legacy($game, $sport);
                if ($strictLegacy && $forecast !== null) {
                    $kickoff = $game->game_time ? CarbonImmutable::parse($game->game_date->toDateString().' '.$game->game_time, 'UTC') : null;
                    $updatedAt = $forecast->source->updated_at;
                    if (! $kickoff || ! $updatedAt || $updatedAt->gte($kickoff) || ($before && $updatedAt->gte($before))) {
                        $forecast = null;
                    }
                }
                $game->setRelation('trendForecast', $forecast)->setRelation('trendForecastSource', $mode);
            }

            return;
        }
        if ($pending->isEmpty()) {
            return;
        }
        $byId = $pending->keyBy(fn (Model $game): int => (int) $game->getKey());
        foreach ($pending as $game) {
            $game->setRelation('trendForecast', null)->setRelation('trendForecastSource', $mode);
        }

        $this->canonical->queryForSport($sport)->whereIn("{$sport}_games.id", $byId->keys()->all())
            ->select(['predictions.id', 'predictions.public_id', 'predictions.sport', 'predictions.sport_event_id',
                'predictions.calculation_run_id', 'predictions.phase', 'predictions.publication_state',
                'predictions.generated_at', 'predictions.published_at', 'predictions.model_version'])
            ->withOnly(['markets', 'calculationRun:id,event_input_snapshot_id,calculation_release_id,status',
                'calculationRun.inputSnapshot', 'calculationRun.release', 'sportEvent.'.$sport.'Game'])
            ->chunk(25, function (Collection $predictions) use ($byId, $before): void {
                foreach ($predictions as $prediction) {
                    $snapshot = $this->inputs->fromPrediction($prediction);
                    if ($snapshot === null || $prediction->published_at->gte($snapshot->game_start_at)
                        || ($before && $prediction->published_at->gte($before))) {
                        continue;
                    }
                    $game = $byId->get($snapshot->game_id);
                    if ($game === null || $game->getRelation('trendForecast') !== null) {
                        continue;
                    }
                    // Retain scalar lineage, without keeping large frozen input graphs in every collector.
                    $prediction->unsetRelations();
                    $game->setRelation('trendForecast', new BettingForecast(
                        source: $prediction, game: $game,
                        predictedSpread: $this->number($snapshot->outputs['predicted_spread']),
                        predictedTotal: $this->number($snapshot->outputs['predicted_total']),
                        homeTeamElo: $this->number($snapshot->features['home_team_elo']),
                        awayTeamElo: $this->number($snapshot->features['away_team_elo']),
                        modelMetadata: ['canonical_lineage' => $snapshot->lineage],
                    ));
                }
            });
    }

    public function forGame(Model $game, string $sport): ?BettingForecast
    {
        if ($game->relationLoaded('trendForecast')) {
            $forecast = $game->getRelation('trendForecast');

            return $forecast !== null && $forecast->isCanonical() !== $this->delivery->usesCanonical($sport) ? null : $forecast;
        }

        return $this->delivery->usesCanonical($sport) ? null : $this->legacy($game, $sport);
    }

    private function legacy(Model $game, string $sport): ?BettingForecast
    {
        $prediction = $game->relationLoaded('prediction') ? $game->getRelation('prediction') : null;
        if (! $prediction instanceof Model) {
            return null;
        }
        $spread = $this->number($prediction->predicted_spread);

        return new BettingForecast(
            source: $prediction, game: $game,
            predictedSpread: $spread !== null ? ($sport === 'nfl' ? $spread : -$spread) : null,
            predictedTotal: $this->number($prediction->predicted_total),
            homeTeamElo: $this->number($prediction->home_elo ?? $prediction->home_team_elo),
            awayTeamElo: $this->number($prediction->away_elo ?? $prediction->away_team_elo),
        );
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
