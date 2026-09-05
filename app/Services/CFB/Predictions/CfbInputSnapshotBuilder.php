<?php

namespace App\Services\CFB\Predictions;

use App\Models\CFB\Game;
use App\Models\CFB\PlayerInjury;
use App\Models\CFB\TeamMetric;
use App\Services\Predictions\Football\FootballInputSnapshotBuilder;
use Illuminate\Database\Eloquent\Model;

class CfbInputSnapshotBuilder extends FootballInputSnapshotBuilder
{
    protected function sport(): string
    {
        return 'cfb';
    }

    protected function inputSchemaVersion(): string
    {
        return CfbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }

    protected function gameRelation(): string
    {
        return 'cfbGame';
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
