<?php

namespace App\Jobs\ESPN\CFB;

use App\Actions\ESPN\CFB\SyncTeams;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\CFB\EspnService;
use Illuminate\Support\Facades\Log;

class FetchTeams extends BulkEspnJob
{
    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeams($service);

        $count = $action->execute();

        Log::info("CFB: Synced {$count} teams from ESPN");
    }
}
