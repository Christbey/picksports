<?php

namespace App\Jobs\ESPN\WNBA;

use App\Actions\ESPN\WNBA\SyncGameDetails;
use App\Actions\ESPN\WNBA\SyncPlayerStats;
use App\Actions\ESPN\WNBA\SyncPlays;
use App\Actions\ESPN\WNBA\SyncTeamStats;
use App\Services\ESPN\WNBA\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchGameDetails implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $eventId
    ) {}

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(): void
    {
        $service = new EspnService;
        $syncPlayerStats = new SyncPlayerStats;
        $syncTeamStats = new SyncTeamStats;
        $syncPlays = new SyncPlays($service);
        $action = new SyncGameDetails($service, $syncPlayerStats, $syncTeamStats, $syncPlays);

        $result = $action->execute($this->eventId);

        Log::info("WNBA: Synced {$result['plays']} plays, {$result['player_stats']} player stats, and {$result['team_stats']} team stats for event {$this->eventId} from ESPN");
    }
}
