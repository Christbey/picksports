<?php

namespace App\Jobs\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncPlayers;
use App\Jobs\ESPN\BulkEspnJob;
use App\Models\CBB\RosterSnapshot;
use App\Models\CBB\Team;
use App\Services\ESPN\CBB\EspnService;
use App\Support\BasketballSeason;
use Illuminate\Support\Facades\Log;

class FetchPlayers extends BulkEspnJob
{
    public ?int $season = null;

    public bool $includePrior = false;

    public function __construct(
        public ?string $teamEspnId = null,
        ?int $season = null,
        bool $includePrior = false
    ) {
        parent::__construct();
        $this->season = $season ?? BasketballSeason::forDate();
        $this->includePrior = $includePrior;
    }

    public function handle(): void
    {
        $this->season ??= BasketballSeason::forDate();
        $service = new EspnService;
        $action = new SyncPlayers($service);

        if ($this->teamEspnId) {
            $count = $action->execute($this->teamEspnId, $this->season);
            $team = Team::query()->where('espn_id', $this->teamEspnId)->firstOrFail();
            if ($count > 0 && $this->includePrior && ! RosterSnapshot::knownAt($team->id, $this->season - 1, now())) {
                $action->execute($this->teamEspnId, $this->season - 1);
            }
            Log::info("CBB: Synced {$count} players for team {$this->teamEspnId} from ESPN");
        } else {
            // Dispatch individual jobs for each team to avoid timeouts
            $teams = Team::all();
            foreach ($teams as $team) {
                FetchPlayers::dispatch($team->espn_id, $this->season, $this->includePrior);
            }
            Log::info("CBB: Dispatched player sync jobs for {$teams->count()} teams");
        }
    }
}
