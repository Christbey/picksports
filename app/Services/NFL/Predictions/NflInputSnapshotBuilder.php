<?php

namespace App\Services\NFL\Predictions;

use App\Models\NFL\Game;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\TeamMetric;
use App\Services\Predictions\Football\FootballInputSnapshotBuilder;
use Illuminate\Database\Eloquent\Model;

class NflInputSnapshotBuilder extends FootballInputSnapshotBuilder
{
    protected function sport(): string
    {
        return 'nfl';
    }

    protected function inputSchemaVersion(): string
    {
        return NflCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }

    protected function gameRelation(): string
    {
        return 'nflGame';
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
