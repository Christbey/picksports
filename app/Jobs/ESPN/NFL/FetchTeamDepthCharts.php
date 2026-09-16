<?php

namespace App\Jobs\ESPN\NFL;

use App\Actions\ESPN\NFL\SyncTeamDepthCharts;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\NFL\EspnService;
use Illuminate\Support\Facades\Log;

class FetchTeamDepthCharts extends BulkEspnJob
{
    public function __construct(
        public ?string $teamEspnId = null,
        public int $season = 0,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeamDepthCharts($service);
        $season = $this->season > 0 ? $this->season : now()->year;

        if ($this->teamEspnId) {
            $count = $action->execute($this->teamEspnId, $season);
            Log::info("NFL: Synced {$count} depth chart entries for team {$this->teamEspnId} in season {$season} from ESPN");

            return;
        }

        $count = $action->syncAllTeams($season);
        Log::info("NFL: Synced {$count} total depth chart entries for season {$season} from ESPN");
    }
}
