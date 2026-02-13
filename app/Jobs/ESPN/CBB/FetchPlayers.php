<?php

namespace App\Jobs\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncPlayers;
use App\Services\ESPN\CBB\EspnService;
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
            Log::info("CBB: Synced {$count} players for team {$this->teamEspnId} from ESPN");
        } else {
            // Dispatch individual jobs for each team to avoid timeouts
            $teams = \App\Models\CBB\Team::all();
            foreach ($teams as $team) {
                FetchPlayers::dispatch($team->espn_id);
            }
            Log::info("CBB: Dispatched player sync jobs for {$teams->count()} teams");
        }
    }
}
