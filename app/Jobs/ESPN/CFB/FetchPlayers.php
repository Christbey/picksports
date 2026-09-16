<?php

namespace App\Jobs\ESPN\CFB;

use App\Actions\ESPN\CFB\SyncPlayers;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\CFB\EspnService;
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
            Log::info("CFB: Synced {$count} players for team {$this->teamEspnId} from ESPN");
        } else {
            $count = $action->syncAllTeams();
            Log::info("CFB: Synced {$count} total players from ESPN");
        }
    }
}
