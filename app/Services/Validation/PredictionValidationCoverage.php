<?php

namespace App\Services\Validation;

use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Support\SportCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PredictionValidationCoverage
{
    public function usesCanonical(string $sport): bool
    {
        return (bool) config("prediction_lifecycle.canonical_pipeline.{$sport}", false)
            || (bool) config("prediction_lifecycle.canonical_reads.{$sport}", false);
    }

    /** Both readiness checks must inspect the same configured prediction lifecycle. */
    public function forGames(string $sport, Collection $games): Collection
    {
        if (! in_array($sport, SportCatalog::ALL, true)) {
            throw new InvalidArgumentException('Unsupported validation sport.');
        }
        if (! $this->usesCanonical($sport)) {
            return DB::table($sport.'_predictions')->whereIn('game_id', $games->pluck('id'))->get(['game_id'])->keyBy('game_id');
        }
        if ($sport === 'cfb') {
            return app(CfbValidationCoverage::class)->publishedForGames($games);
        }

        return app(CanonicalSportPredictionQuery::class)->queryForSport($sport)
            ->whereIn("{$sport}_games.id", $games->pluck('id'))
            ->where('predictions.generated_at', '<=', now())->where('predictions.published_at', '<=', now())
            ->select(['predictions.id', 'predictions.sport_event_id', 'predictions.generated_at', 'predictions.published_at', 'predictions.revision'])
            ->withOnly([])->get()->unique('sport_event_id')->keyBy('sport_event_id');
    }

    public function key(string $sport, object $game): int|string|null
    {
        return $this->usesCanonical($sport) ? $game->sport_event_id : $game->id;
    }
}
