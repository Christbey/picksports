<?php

namespace App\Jobs\ESPN\MLB;

use App\Actions\ESPN\MLB\SyncTeamDepthCharts;
use App\Services\ESPN\MLB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchTeamDepthCharts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?string $teamEspnId = null,
        public int $season = 0,
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeamDepthCharts($service);
        $season = $this->season > 0 ? $this->season : now()->year;

        if ($this->teamEspnId) {
            $count = $action->execute($this->teamEspnId, $season);
            Log::info("MLB: Synced {$count} depth chart entries for team {$this->teamEspnId} in season {$season} from ESPN");

            return;
        }

        $count = $action->syncAllTeams($season);
        Log::info("MLB: Synced {$count} total depth chart entries for season {$season} from ESPN");
    }
}
