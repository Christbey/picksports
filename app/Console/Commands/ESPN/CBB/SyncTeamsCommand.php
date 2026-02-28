<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSyncTeamsCommand;
use App\Jobs\ESPN\CBB\FetchTeams;

class SyncTeamsCommand extends AbstractSyncTeamsCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-teams';
    protected const SPORT_CODE = 'CBB';
    protected const TEAMS_SYNC_JOB_CLASS = FetchTeams::class;
}
