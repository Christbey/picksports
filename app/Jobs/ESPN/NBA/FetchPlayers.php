<?php

namespace App\Jobs\ESPN\NBA;

use App\Actions\ESPN\NBA\SyncPlayers;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\NBA\EspnService;
use Illuminate\Support\Facades\Log;

class FetchPlayers extends BulkEspnJob
{
    public function __construct(
        public ?string $teamEspnId = null
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncPlayers($service);

        if ($this->teamEspnId) {
            $count = $action->execute($this->teamEspnId);
            Log::info("NBA: Synced {$count} players for team {$this->teamEspnId} from ESPN");
        } else {
            $count = $action->syncAllTeams();
            Log::info("NBA: Synced {$count} total players from ESPN");
        }
    }
}
