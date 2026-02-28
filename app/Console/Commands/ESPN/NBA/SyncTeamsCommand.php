<?php

namespace App\Console\Commands\ESPN\NBA;

use App\Console\Commands\ESPN\AbstractSyncTeamsCommand;
use App\Jobs\ESPN\NBA\FetchTeams;

class SyncTeamsCommand extends AbstractSyncTeamsCommand
{
    protected const COMMAND_NAME = 'espn:sync-nba-teams';
    protected const SPORT_CODE = 'NBA';
    protected const TEAMS_SYNC_JOB_CLASS = FetchTeams::class;
}
