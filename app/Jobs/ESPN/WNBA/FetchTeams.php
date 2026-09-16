<?php

namespace App\Jobs\ESPN\WNBA;

use App\Actions\ESPN\WNBA\SyncTeams;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\WNBA\EspnService;
use Illuminate\Support\Facades\Log;

class FetchTeams extends BulkEspnJob
{
    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeams($service);

        $count = $action->execute();

        Log::info("WNBA: Synced {$count} teams from ESPN");
    }
}
