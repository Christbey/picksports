<?php

namespace App\Jobs\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncPlayers;
use App\Jobs\ESPN\BulkEspnJob;
use App\Models\CBB\RosterSnapshot;
use App\Models\CBB\Team;
use App\Services\ESPN\CBB\EspnService;
use App\Support\BasketballSeason;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

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
            try {
                $count = $action->execute($this->teamEspnId, $this->season);
            } catch (UnexpectedValueException $exception) {
                Log::warning('CBB: Current roster observation rejected', ['team_espn_id' => $this->teamEspnId, 'season' => $this->season, 'reason' => $exception->getMessage()]);

                return;
            }
            $team = Team::query()->where('espn_id', $this->teamEspnId)->firstOrFail();
            if ($count > 0 && $this->includePrior && ! RosterSnapshot::knownAt($team->id, $this->season - 1, now())) {
                try {
                    $priorCount = $action->execute($this->teamEspnId, $this->season - 1);
                    if ($priorCount === 0) {
                        Log::warning('CBB: Prior roster unavailable', ['team_espn_id' => $this->teamEspnId, 'season' => $this->season - 1]);
                    }
                } catch (UnexpectedValueException $exception) {
                    Log::warning('CBB: Prior roster observation rejected', ['team_espn_id' => $this->teamEspnId, 'season' => $this->season - 1, 'reason' => $exception->getMessage()]);
                }
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
