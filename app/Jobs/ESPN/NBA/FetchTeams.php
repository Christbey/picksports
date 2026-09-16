<?php

namespace App\Jobs\ESPN\NBA;

use App\Actions\ESPN\NBA\SyncTeams;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\NBA\EspnService;
use Illuminate\Support\Facades\Log;

class FetchTeams extends BulkEspnJob
{
    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeams($service);

        $count = $action->execute();

        Log::info("NBA: Synced {$count} teams from ESPN");
    }
}
