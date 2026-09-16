<?php

namespace App\Jobs\ESPN\MLB;

use App\Actions\ESPN\MLB\SyncTeams;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\MLB\EspnService;
use Illuminate\Support\Facades\Log;

class FetchTeams extends BulkEspnJob
{
    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeams($service);

        $count = $action->execute();

        Log::info("MLB: Synced {$count} teams from ESPN");
    }
}
