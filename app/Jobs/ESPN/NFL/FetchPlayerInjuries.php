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
        return $this->teamEspnId ?: 'all-teams';
    }

    public function handle(): void
    {
        if ($this->teamEspnId) {
            $service = new EspnService;
            $action = new SyncPlayerInjuries($service);
            $count = $action->execute($this->teamEspnId);
            Log::info("NFL: Synced {$count} player injuries for team {$this->teamEspnId} from ESPN");

            return;
        }

        $teamEspnIds = Team::query()
            ->whereNotNull('espn_id')
            ->pluck('espn_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->values();

        $teamEspnIds->each(fn (string $teamEspnId) => self::dispatch($teamEspnId));

        Log::info("NFL: Dispatched {$teamEspnIds->count()} team injury sync jobs");
    }
}
