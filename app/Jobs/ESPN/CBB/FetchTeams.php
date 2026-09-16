<?php

namespace App\Jobs\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncTeams;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\CBB\EspnService;
use Illuminate\Support\Facades\Log;

class FetchTeams extends BulkEspnJob
{
    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeams($service);

        $count = $action->execute();

        Log::info("CBB: Synced {$count} teams from ESPN");
    }
}
