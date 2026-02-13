<?php

namespace App\Jobs\ESPN\WCBB;

use App\Actions\ESPN\WCBB\SyncGameDetails;
use App\Actions\ESPN\WCBB\SyncPlayerStats;
use App\Actions\ESPN\WCBB\SyncPlays;
use App\Actions\ESPN\WCBB\SyncTeamStats;
use App\Services\ESPN\WCBB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchGameDetails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $eventId
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $syncPlayerStats = new SyncPlayerStats;
        $syncTeamStats = new SyncTeamStats;
        $syncPlays = new SyncPlays($service);
        $action = new SyncGameDetails($service, $syncPlayerStats, $syncTeamStats, $syncPlays);

        $result = $action->execute($this->eventId);

        Log::info("WCBB: Synced {$result['plays']} plays, {$result['player_stats']} player stats, and {$result['team_stats']} team stats for event {$this->eventId} from ESPN");
    }
}
