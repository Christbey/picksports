<?php

namespace App\Jobs\ESPN\CFB;

use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\CFB\EspnService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;

class FetchPlayerInjuries extends BulkEspnJob implements ShouldBeUnique
{
    public function __construct(
        public ?string $teamEspnId = null
    ) {
        parent::__construct();
    }

    public int $uniqueFor = 2100;

    public function uniqueId(): string
    {
        return $this->teamEspnId ?: 'all-teams';
    }

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncPlayerInjuries($service);

        if ($this->teamEspnId) {
            $count = $action->execute($this->teamEspnId);
            Log::info("CFB: Synced {$count} player injuries for team {$this->teamEspnId} from ESPN");
        } else {
            $count = $action->syncAllTeams();
            Log::info("CFB: Synced {$count} total player injuries from ESPN");
        }
    }
}
