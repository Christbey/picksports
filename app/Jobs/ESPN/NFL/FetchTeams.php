<?php

namespace App\Jobs\ESPN\NFL;

use App\Actions\ESPN\NFL\SyncTeams;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\NFL\EspnService;
use Illuminate\Support\Facades\Log;

class FetchTeams extends BulkEspnJob
{
    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeams($service);

        $count = $action->execute();

        Log::info("NFL: Synced {$count} teams from ESPN");
    }
}
