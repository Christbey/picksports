<?php

namespace App\Services\MLB;

use App\Application\Sports\ReadModels\CanonicalShadowSnapshot;
use App\Models\MLB\Game;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Predictions\CanonicalShadowInputs;
use App\Services\Predictions\PredictionDeliverySource;
use App\Support\MLB\MlbGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MlbPeriodForecastSource
{
    public function usesCanonical(): bool
    {
        return app(PredictionDeliverySource::class)->usesCanonical('mlb');
    }

    /** @param Collection<int, Game> $games @return Collection<int, CanonicalShadowSnapshot> */
    public function forGames(Collection $games): Collection
    {
        $sources = collect();
        if ($games->isEmpty()) {
            return $sources;
        }
        $games = $games->keyBy('id');
        app(CanonicalSportPredictionQuery::class)->queryForSport('mlb')
            ->whereIn('mlb_games.id', $games->pluck('id'))
            ->where('predictions.generated_at', '<=', now())->where('predictions.published_at', '<=', now())
            ->withOnly(['markets', 'calculationRun:id,event_input_snapshot_id,calculation_release_id,status',
                'calculationRun.inputSnapshot', 'calculationRun.release:id,status',
                'sportEvent:id,starts_at', 'sportEvent.mlbGame:id,sport_event_id'])
            ->chunk(25, function (Collection $predictions) use ($sources, $games): void {
                foreach ($predictions as $prediction) {
                    $source = app(CanonicalShadowInputs::class)->fromPrediction($prediction);
                    $input = $prediction->calculationRun?->inputSnapshot;
                    $frozen = data_get($input?->inputs, 'event.starts_at');
                    try {
                        $matches = is_string($frozen) && CarbonImmutable::parse($frozen)->eq($prediction->sportEvent?->starts_at);
                    } catch (\Throwable) {
                        $matches = false;
                    }
                    $localStart = $source ? MlbGameStart::for($games->get($source->game_id)) : null;
                    if ($source && $matches && $localStart !== null && $source->game_start_at->eq($localStart)
                        && $prediction->published_at->lt($source->game_start_at)
                        && ($input->latest_source_available_at === null || $input->latest_source_available_at->lte($prediction->generated_at))
                        && ! $sources->has($source->game_id)) {
                        $sources->put($source->game_id, $source);
                    }
                }
            });

        return $sources;
    }
}
