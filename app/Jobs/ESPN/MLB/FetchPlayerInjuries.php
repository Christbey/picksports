<?php

namespace App\Jobs\ESPN\MLB;

use App\Actions\ESPN\MLB\SyncPlayerInjuries;
use App\Services\ESPN\MLB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchPlayerInjuries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?string $teamEspnId = null
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncPlayerInjuries($service);

        if ($this->teamEspnId !== null) {
            $count = $action->execute($this->teamEspnId);
            if (! $action->lastSyncReliable()) {
                throw new \RuntimeException('MLB injury source unavailable or incomplete for the selected team.');
            }
            Log::info("MLB: Synced {$count} player injuries for team {$this->teamEspnId} from ESPN");
        } else {
            $count = $action->syncAllTeams();
            Log::info("MLB: Synced {$count} total player injuries from ESPN");
        }
    }
}
