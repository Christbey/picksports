<?php

namespace App\Services\NBA\Predictions;

use App\Models\NBA\Game;
use App\Models\NBA\PlayerInjury;
use App\Models\NBA\TeamMetric;
use App\Services\Predictions\Basketball\BasketballInputSnapshotBuilder;
use Illuminate\Database\Eloquent\Model;

class NbaInputSnapshotBuilder extends BasketballInputSnapshotBuilder
{
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
