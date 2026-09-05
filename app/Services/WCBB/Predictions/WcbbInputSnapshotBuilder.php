<?php

namespace App\Services\WCBB\Predictions;

use App\Models\WCBB\Game;
use App\Models\WCBB\PlayerInjury;
use App\Models\WCBB\TeamMetric;
use App\Services\Predictions\Basketball\BasketballInputSnapshotBuilder;
use Illuminate\Database\Eloquent\Model;

class WcbbInputSnapshotBuilder extends BasketballInputSnapshotBuilder
{
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
