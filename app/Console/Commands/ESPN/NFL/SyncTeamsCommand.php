<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncTeamsCommand;
use App\Jobs\ESPN\NFL\FetchTeams;

class SyncTeamsCommand extends AbstractSyncTeamsCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-teams';
    protected const SPORT_CODE = 'NFL';
    protected const TEAMS_SYNC_JOB_CLASS = FetchTeams::class;
}
