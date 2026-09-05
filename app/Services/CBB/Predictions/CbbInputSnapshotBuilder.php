<?php

namespace App\Services\CBB\Predictions;

use App\Models\CBB\Game;
use App\Models\CBB\PlayerInjury;
use App\Models\CBB\TeamMetric;
use App\Services\Predictions\Basketball\BasketballInputSnapshotBuilder;
use Illuminate\Database\Eloquent\Model;

class CbbInputSnapshotBuilder extends BasketballInputSnapshotBuilder
{
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
