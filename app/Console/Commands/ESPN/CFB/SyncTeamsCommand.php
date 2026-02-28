<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSyncTeamsCommand;
use App\Jobs\ESPN\CFB\FetchTeams;

class SyncTeamsCommand extends AbstractSyncTeamsCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-teams';
    protected const SPORT_CODE = 'CFB';
    protected const TEAMS_SYNC_JOB_CLASS = FetchTeams::class;
}
