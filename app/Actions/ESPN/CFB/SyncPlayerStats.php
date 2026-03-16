<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractFootballSyncPlayerStats;

class SyncPlayerStats extends AbstractFootballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const PLAYER_MODEL_CLASS = \App\Models\CFB\Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = \App\Models\CFB\PlayerStat::class;

    protected function passingCompletionsField(): string
    {
        return 'passing_completions';
    }

    protected function passingAttemptsField(): string
    {
        return 'passing_attempts';
    }

    protected function interceptionsField(): string
    {
        return 'interceptions_thrown';
    }

    protected function rushingAttemptsField(): string
    {
        return 'rushing_attempts';
    }

    protected function receivingTargetsField(): string
    {
        return 'receiving_targets';
    }
}
