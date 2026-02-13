<?php

namespace App\Jobs\ESPN\MLB;

use App\Actions\ESPN\MLB\SyncTeams;
use App\Services\ESPN\MLB\EspnService;
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

        Log::info("MLB: Synced {$count} teams from ESPN");
    }
}
