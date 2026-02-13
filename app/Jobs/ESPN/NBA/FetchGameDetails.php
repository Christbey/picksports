<?php

namespace App\Jobs\ESPN\NBA;

use App\Actions\ESPN\NBA\SyncGameDetails;
use App\Actions\ESPN\NBA\SyncPlayerStats;
use App\Actions\ESPN\NBA\SyncTeamStats;
use App\Services\ESPN\NBA\EspnService;
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
        public string $eventId
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $syncPlayerStats = new SyncPlayerStats;
        $syncTeamStats = new SyncTeamStats;
        $action = new SyncGameDetails($service, $syncPlayerStats, $syncTeamStats);

        $result = $action->execute($this->eventId);

        Log::info("NBA: Synced {$result['plays']} plays, {$result['player_stats']} player stats, and {$result['team_stats']} team stats for event {$this->eventId} from ESPN");
    }
}
