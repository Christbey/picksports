<?php

namespace App\Services\WCBB\Predictions;

use App\Actions\WCBB\GeneratePrediction;
use App\Models\CalculationRelease;
use App\Models\WCBB\Game;
use App\Models\WCBB\TeamMetric;
use App\Services\Predictions\EstablishedModelTransport;
use App\Services\WCBB\WcbbMetricSourceEvidence;
use Illuminate\Support\Collection;

class WcbbPregameInputReadiness
{
    /** @return array<int, array{holds: array, metrics: array{home: ?TeamMetric, away: ?TeamMetric}}> */
    public function forGames(Collection $games, ?CalculationRelease $release = null): array
    {
        return app(EstablishedModelTransport::class)->usingConfiguration(
            (array) data_get($release?->configuration, 'forecast.configuration', []),
            function () use ($games, $release): array {
                $evidence = new WcbbMetricSourceEvidence;
                $evidence->prime($games, now()->toImmutable());
                $generator = app(GeneratePrediction::class);

                return $generator->usingSourceEvidence($evidence, function () use ($games, $release, $generator): array {
                    $results = [];
                    foreach ($games as $game) {
                        $metrics = $generator->selectedMetricsForGame($game);
                        $holds = $release !== null && data_get($release->configuration, 'forecast.source') !== WcbbFullModelReleaseDefinition::FORECAST_SOURCE
                            ? [] : $generator->metricInputHolds($game, $metrics);
                        $results[$game->id] = ['holds' => $holds, 'metrics' => $metrics];
                    }

                    return $results;
                });
            },
        );
    }

    /** @return array{home: ?TeamMetric, away: ?TeamMetric} */
    public function metrics(Game $game, ?CalculationRelease $release = null): array
    {
        return app(EstablishedModelTransport::class)->usingConfiguration(
            (array) data_get($release?->configuration, 'forecast.configuration', []),
            fn () => app(GeneratePrediction::class)->selectedMetricsForGame($game),
        );
    }

    /** @return list<array{side: string, metric_id: ?int, reason: string}> */
    public function holds(Game $game, ?CalculationRelease $release = null): array
    {
        if ($release !== null && data_get($release->configuration, 'forecast.source') !== WcbbFullModelReleaseDefinition::FORECAST_SOURCE) {
            return [];
        }

        return app(EstablishedModelTransport::class)->usingConfiguration(
            (array) data_get($release?->configuration, 'forecast.configuration', []),
            fn () => app(GeneratePrediction::class)->metricInputHolds($game),
        );
    }
}
