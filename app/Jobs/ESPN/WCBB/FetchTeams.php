<?php

namespace App\Jobs\ESPN\WCBB;

use App\Actions\ESPN\WCBB\SyncTeams;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\WCBB\EspnService;
use Illuminate\Support\Facades\Log;

class FetchTeams extends BulkEspnJob
{
    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeams($service);

        $count = $action->execute();

        Log::info("WCBB: Synced {$count} teams from ESPN");
    }
}
