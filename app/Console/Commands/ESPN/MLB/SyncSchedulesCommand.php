<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Actions\ESPN\MLB\SyncGamesFromSchedule;
use App\Console\Commands\ESPN\AbstractSyncSchedulesCommand;
use App\Models\MLB\Team;
use App\Services\ESPN\MLB\EspnService;

class SyncSchedulesCommand extends AbstractSyncSchedulesCommand
{
    protected const COMMAND_NAME = 'espn:sync-mlb-schedules';
    protected const SPORT_CODE = 'MLB';
    protected const DEFAULT_SEASON = '2025';
    protected const TEAM_MODEL_CLASS = Team::class;
    protected const ESPN_SERVICE_CLASS = EspnService::class;
    protected const SCHEDULE_SYNC_ACTION_CLASS = SyncGamesFromSchedule::class;
}
