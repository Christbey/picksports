<?php

namespace App\Jobs\ESPN\WCBB;

use App\Actions\ESPN\WCBB\SyncPlayerInjuries;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\WCBB\EspnService;
use Illuminate\Support\Facades\Log;

class FetchPlayerInjuries extends BulkEspnJob
{
    public function __construct(
        public ?string $teamEspnId = null
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncPlayerInjuries($service);

        if ($this->teamEspnId) {
            $count = $action->execute($this->teamEspnId);
            Log::info("WCBB: Synced {$count} player injuries for team {$this->teamEspnId} from ESPN");
        } else {
            $count = $action->syncAllTeams();
            Log::info("WCBB: Synced {$count} total player injuries from ESPN");
        }
    }
}
