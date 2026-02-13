<?php

namespace App\Jobs\ESPN\NFL;

use App\Actions\ESPN\NFL\SyncPlayers;
use App\Services\ESPN\NFL\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchPlayers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?string $teamEspnId = null
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncPlayers($service);

        if ($this->teamEspnId) {
            $count = $action->execute($this->teamEspnId);
            Log::info("NFL: Synced {$count} players for team {$this->teamEspnId} from ESPN");
        } else {
            $count = $action->syncAllTeams();
            Log::info("NFL: Synced {$count} total players from ESPN");
        }
    }
}
