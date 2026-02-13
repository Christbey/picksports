<?php

namespace App\Jobs\ESPN\NFL;

use App\Actions\ESPN\NFL\SyncTeams;
use App\Services\ESPN\NFL\EspnService;
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

        Log::info("NFL: Synced {$count} teams from ESPN");
    }
}
