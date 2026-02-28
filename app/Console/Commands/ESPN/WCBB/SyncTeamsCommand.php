<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Console\Commands\ESPN\AbstractSyncTeamsCommand;
use App\Jobs\ESPN\WCBB\FetchTeams;

class SyncTeamsCommand extends AbstractSyncTeamsCommand
{
    protected const COMMAND_NAME = 'espn:sync-wcbb-teams';
    protected const SPORT_CODE = 'WCBB';
    protected const TEAMS_SYNC_JOB_CLASS = FetchTeams::class;
}
