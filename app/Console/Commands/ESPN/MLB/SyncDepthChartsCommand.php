<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Console\Commands\ESPN\AbstractSyncDepthChartsCommand;
use App\Jobs\ESPN\MLB\FetchTeamDepthCharts;

class SyncDepthChartsCommand extends AbstractSyncDepthChartsCommand
{
    protected const COMMAND_NAME = 'espn:sync-mlb-depth-charts';

    protected const SPORT_CODE = 'MLB';

    protected const DEPTH_CHARTS_SYNC_JOB_CLASS = FetchTeamDepthCharts::class;
}
