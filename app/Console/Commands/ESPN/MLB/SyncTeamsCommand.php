<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Console\Commands\ESPN\AbstractSyncTeamsCommand;
use App\Jobs\ESPN\MLB\FetchTeams;

class SyncTeamsCommand extends AbstractSyncTeamsCommand
{
    protected const COMMAND_NAME = 'espn:sync-mlb-teams';
    protected const SPORT_CODE = 'MLB';
    protected const TEAMS_SYNC_JOB_CLASS = FetchTeams::class;
}
