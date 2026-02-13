<?php

namespace App\Jobs\ESPN\NBA;

use App\Actions\ESPN\NBA\SyncTeams;
use App\Services\ESPN\NBA\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchTeams implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeams($service);

        $count = $action->execute();

        Log::info("NBA: Synced {$count} teams from ESPN");
    }
}
