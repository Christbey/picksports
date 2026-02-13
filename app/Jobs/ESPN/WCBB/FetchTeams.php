<?php

namespace App\Jobs\ESPN\WCBB;

use App\Actions\ESPN\WCBB\SyncTeams;
use App\Services\ESPN\WCBB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchTeams implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes for all WCBB teams

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeams($service);

        $count = $action->execute();

        Log::info("WCBB: Synced {$count} teams from ESPN");
    }
}
