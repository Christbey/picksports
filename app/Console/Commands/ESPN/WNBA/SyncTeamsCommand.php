<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Console\Commands\ESPN\AbstractSyncTeamsCommand;
use App\Jobs\ESPN\WNBA\FetchTeams;

class SyncTeamsCommand extends AbstractSyncTeamsCommand
{
    protected const COMMAND_NAME = 'espn:sync-wnba-teams';
    protected const SPORT_CODE = 'WNBA';
    protected const TEAMS_SYNC_JOB_CLASS = FetchTeams::class;
}
