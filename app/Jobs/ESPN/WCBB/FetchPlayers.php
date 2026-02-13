<?php

namespace App\Jobs\ESPN\WCBB;

use App\Actions\ESPN\WCBB\SyncPlayers;
use App\Services\ESPN\WCBB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchPlayers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     * Syncing all 300+ WCBB team rosters requires significant time.
     */
    public $timeout = 1800; // 30 minutes

    public function __construct(
        public ?string $teamEspnId = null
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncPlayers($service);

        if ($this->teamEspnId) {
            $count = $action->execute($this->teamEspnId);
            Log::info("WCBB: Synced {$count} players for team {$this->teamEspnId} from ESPN");
        } else {
            $count = $action->syncAllTeams();
            Log::info("WCBB: Synced {$count} total players from ESPN");
        }
    }
}
