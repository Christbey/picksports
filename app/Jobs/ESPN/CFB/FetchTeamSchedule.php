<?php

namespace App\Jobs\ESPN\CFB;

use App\Actions\ESPN\CFB\SyncGamesFromSchedule;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchTeamSchedule implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public string $teamEspnId,
        public int $season,
        public bool $dispatchDetails = false,
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncGamesFromSchedule($service);

        $count = $action->execute($this->teamEspnId, $this->season);

        if ($this->dispatchDetails) {
            $this->dispatchMissingDetailsJobs();
        }

        Log::info("CFB: Synced {$count} games from team schedule for team ESPN ID {$this->teamEspnId} in season {$this->season}");
    }

    private function dispatchMissingDetailsJobs(): void
    {
        $team = Team::query()->where('espn_id', $this->teamEspnId)->first();

        if (! $team) {
            return;
        }

        Game::query()
            ->where('season', $this->season)
            ->whereNotNull('espn_event_id')
            ->where(function ($query) use ($team): void {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->where(function ($query): void {
                $query->whereDoesntHave('playerStats')
                    ->orWhereDoesntHave('teamStats')
                    ->orWhereDoesntHave('plays')
                    ->orWhere(function ($gameQuery): void {
                        $gameQuery->whereDate('game_date', '<', now()->toDateString())
                            ->where(function ($pastGameQuery): void {
                                $pastGameQuery->where('status', '!=', 'STATUS_FINAL')
                                    ->orWhereNull('home_score')
                                    ->orWhereNull('away_score');
                            });
                    });
            })
            ->pluck('espn_event_id')
            ->filter()
            ->unique()
            ->each(fn ($eventId) => FetchGameDetails::dispatch((string) $eventId));
    }
}
