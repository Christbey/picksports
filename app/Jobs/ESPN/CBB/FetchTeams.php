<?php

namespace App\Jobs\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncTeams;
use App\Services\ESPN\CBB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchTeams implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes for all CBB teams

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeams($service);

        $count = $action->execute();

        Log::info("CBB: Synced {$count} teams from ESPN");
    }
}
