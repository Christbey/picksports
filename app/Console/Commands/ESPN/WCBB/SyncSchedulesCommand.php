<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Actions\ESPN\WCBB\SyncGamesFromSchedule;
use App\Console\Commands\ESPN\AbstractSyncSchedulesCommand;
use App\Models\WCBB\Team;
use App\Services\ESPN\WCBB\EspnService;

class SyncSchedulesCommand extends AbstractSyncSchedulesCommand
{
    protected const COMMAND_NAME = 'espn:sync-wcbb-schedules';
    protected const SPORT_CODE = 'WCBB';
    protected const DEFAULT_SEASON = '2026';
    protected const TEAM_MODEL_CLASS = Team::class;
    protected const ESPN_SERVICE_CLASS = EspnService::class;
    protected const SCHEDULE_SYNC_ACTION_CLASS = SyncGamesFromSchedule::class;
}
