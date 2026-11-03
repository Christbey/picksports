<?php

namespace App\Services\CBB\Predictions;

use App\Actions\CBB\GeneratePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CBB\Game;
use App\Models\CBB\PlayerInjury;
use App\Models\CBB\TeamMetric;
use App\Models\SportEvent;
use App\Services\CBB\CbbPregameEligibility;
use App\Services\Predictions\Basketball\BasketballInputSnapshotBuilder;
use App\Services\Predictions\Basketball\EstablishedBasketballModel;
use Illuminate\Database\Eloquent\Model;

class CbbInputSnapshotBuilder extends BasketballInputSnapshotBuilder
{
    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        $game = $event->cbbGame()->with(['homeTeam', 'awayTeam'])->first();
        if ($game === null || ! app(CbbPregameEligibility::class)->teamsAreKnown($game->homeTeam, $game->awayTeam)) {
            throw new PredictionLifecycleException('CBB pregame forecasts require known teams; placeholder matchups remain held.');
        }

        $game->setRelation('sportEvent', $event);
        if (data_get($release->configuration, 'forecast.source') === CbbFullModelReleaseDefinition::FORECAST_SOURCE
            && ! app(CbbPregameEligibility::class)->identityMatches($game)) {
            throw new PredictionLifecycleException('CBB canonical kickoff and neutral-site identity must match the linked game.');
        }

        return app(EstablishedBasketballModel::class)->capture(
            parent::build($event, $release), $release, 'cbb', CbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
            CbbFullModelReleaseDefinition::FORECAST_SOURCE, CbbFullModelReleaseDefinition::configurationKeys(),
            fn () => app(GeneratePrediction::class)->preview($game),
        );
    }

    protected function sport(): string
    {
        return 'cbb';
    }

    protected function inputSchemaVersion(): string
    {
        return CbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }

    protected function gameRelation(): string
    {
        return 'cbbGame';
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
