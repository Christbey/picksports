<?php

namespace App\Services\WCBB\Predictions;

use App\Actions\WCBB\GeneratePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Exceptions\Predictions\WcbbUnusableMetricsException;
use App\Models\SportEvent;
use App\Models\WCBB\Game;
use App\Models\WCBB\PlayerInjury;
use App\Models\WCBB\TeamMetric;
use App\Services\Predictions\Basketball\BasketballInputSnapshotBuilder;
use App\Services\Predictions\Basketball\CollegeBasketballPregameEligibility;
use App\Services\Predictions\EstablishedModelTransport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class WcbbInputSnapshotBuilder extends BasketballInputSnapshotBuilder
{
    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        $game = $event->wcbbGame()->with(['homeTeam', 'awayTeam'])->first();
        if ($game === null || ! app(CollegeBasketballPregameEligibility::class)->teamsAreKnown($game->homeTeam, $game->awayTeam)) {
            throw new PredictionLifecycleException('WCBB pregame forecasts require known teams; placeholder matchups remain held.');
        }

        $game->setRelation('sportEvent', $event);
        if (data_get($release->configuration, 'forecast.source') === WcbbFullModelReleaseDefinition::FORECAST_SOURCE
            && ! app(CollegeBasketballPregameEligibility::class)->identityMatches($game)) {
            throw new PredictionLifecycleException('WCBB canonical kickoff and neutral-site identity must match the linked game.');
        }

        return app(EstablishedModelTransport::class)->capture(
            parent::build($event, $release), $release, 'wcbb', WcbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
            WcbbFullModelReleaseDefinition::FORECAST_SOURCE, WcbbFullModelReleaseDefinition::configurationKeys(),
            function () use ($game): ?array {
                if (app(GeneratePrediction::class)->metricInputHolds($game) !== []) {
                    throw new WcbbUnusableMetricsException('WCBB pregame forecast held: unusable efficiency or tempo inputs.');
                }

                return app(GeneratePrediction::class)->preview($game);
            },
        );
    }

    protected function metricFor(
        Model $game,
        Model $team,
        CarbonImmutable $capturedAt,
        CarbonImmutable $cutoffAt,
        CalculationReleaseData $release,
    ): ?Model {
        if (data_get($release->configuration, 'inputs.recover_invalid_team_metrics') !== true) {
            return parent::metricFor($game, $team, $capturedAt, $cutoffAt, $release);
        }

        return app(GeneratePrediction::class)->metricWithPreviousSeasonFallback(
            (int) $team->getKey(),
            (int) $game->season,
            $capturedAt,
            $game->game_date->toDateString(),
        );
    }

    protected function sport(): string
    {
        return 'wcbb';
    }

    protected function inputSchemaVersion(): string
    {
        return WcbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }

    protected function gameRelation(): string
    {
        return 'wcbbGame';
    }

    /** @return class-string<Model> */
    protected function gameModel(): string
    {
        return Game::class;
    }

    /** @return class-string<Model> */
    protected function teamMetricModel(): string
    {
        return TeamMetric::class;
    }

    /** @return class-string<Model> */
    protected function playerInjuryModel(): string
    {
        return PlayerInjury::class;
    }

    protected function teamMetricsUseSeasonType(): bool
    {
        return false;
    }
}
