<?php

namespace App\Console\Commands\ESPN\NBA;

use App\Console\Commands\ESPN\AbstractSyncDepthChartsCommand;
use App\Jobs\ESPN\NBA\FetchTeamDepthCharts;

class SyncDepthChartsCommand extends AbstractSyncDepthChartsCommand
{
    protected const COMMAND_NAME = 'espn:sync-nba-depth-charts';

    protected const SPORT_CODE = 'NBA';

    protected const DEPTH_CHARTS_SYNC_JOB_CLASS = FetchTeamDepthCharts::class;
}
