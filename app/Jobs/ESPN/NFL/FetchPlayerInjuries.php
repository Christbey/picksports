<?php

namespace App\Jobs\ESPN\NFL;

use App\Actions\ESPN\NFL\SyncPlayerInjuries;
use App\Models\NFL\Team;
use App\Services\ESPN\NFL\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchPlayerInjuries implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?string $teamEspnId = null
    ) {}

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return $this->teamEspnId ?? 'all-teams';
    }

    public function handle(): void
    {
        if ($this->teamEspnId !== null) {
            $service = new EspnService;
            $action = new SyncPlayerInjuries($service);
            $count = $action->execute($this->teamEspnId);
            if (! $action->lastSyncReliable()) {
                throw new \RuntimeException('NFL injury source unavailable or incomplete for the selected team.');
            }
            Log::info("NFL: Synced {$count} player injuries for team {$this->teamEspnId} from ESPN");

            return;
        }

        $dispatched = 0;
        Team::query()->select(['id', 'espn_id'])->chunkById(100, function ($teams) use (&$dispatched): void {
            foreach ($teams as $team) {
                if (preg_match('/\A[1-9][0-9]*\z/', (string) $team->espn_id) !== 1) {
                    continue;
                }
                self::dispatch((string) $team->espn_id)->onConnection($this->connection)->onQueue($this->queue ?? 'default');
                $dispatched++;
            }
        });

        Log::info("NFL: Dispatched {$dispatched} team injury sync jobs");
    }
}
