<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncDepthChartsCommand;
use App\Jobs\ESPN\NFL\FetchTeamDepthCharts;

class SyncDepthChartsCommand extends AbstractSyncDepthChartsCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-depth-charts';

    protected const SPORT_CODE = 'NFL';

    protected const DEPTH_CHARTS_SYNC_JOB_CLASS = FetchTeamDepthCharts::class;
}
