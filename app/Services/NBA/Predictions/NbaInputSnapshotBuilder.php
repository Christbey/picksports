<?php

namespace App\Services\NBA\Predictions;

use App\Actions\NBA\GeneratePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\NBA\Game;
use App\Models\NBA\PlayerInjury;
use App\Models\NBA\TeamMetric;
use App\Models\SportEvent;
use App\Services\Predictions\Basketball\BasketballInputSnapshotBuilder;
use App\Services\Predictions\Basketball\EstablishedBasketballModel;
use Illuminate\Database\Eloquent\Model;

class NbaInputSnapshotBuilder extends BasketballInputSnapshotBuilder
{
    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        $snapshot = parent::build($event, $release);

        return app(EstablishedBasketballModel::class)->capture(
            $snapshot, $release, 'nba', NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
            NbaFullModelReleaseDefinition::FORECAST_SOURCE, NbaFullModelReleaseDefinition::configurationKeys(),
            fn () => app(GeneratePrediction::class)->preview($event->nbaGame()->with(['homeTeam', 'awayTeam'])->firstOrFail()),
        );
    }

    protected function sport(): string
    {
        return 'nba';
    }

    protected function inputSchemaVersion(): string
    {
        return NbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }

    protected function gameRelation(): string
    {
        return 'nbaGame';
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
}
