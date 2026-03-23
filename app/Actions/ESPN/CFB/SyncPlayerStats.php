<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractFootballSyncPlayerStats;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;

class SyncPlayerStats extends AbstractFootballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = PlayerStat::class;

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
