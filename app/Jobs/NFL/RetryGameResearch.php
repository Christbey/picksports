<?php

namespace App\Jobs\NFL;

use App\Models\NFL\Game;
use App\Models\User;
use App\Services\NFL\Research\OfficialSourceIngestor;
use App\Services\NFL\Research\ResearchPipeline;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RetryGameResearch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $gameId, public int $userId, public string $owner) {}

    public static function key(int $gameId): string
    {
        return 'nfl-admin-research:'.$gameId;
    }

    public function handle(OfficialSourceIngestor $ingestor, ResearchPipeline $pipeline): void
    {
        try {
            if (data_get(Cache::get(self::key($this->gameId)), 'run_id') !== $this->owner) {
                return;
            }
            $game = Game::with(['homeTeam', 'awayTeam'])->findOrFail($this->gameId);
            $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
            if (! User::find($this->userId)?->isAdmin() || ! config('nfl_research.enabled')
                || ! $kickoff?->isFuture() || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
                $this->state('failed', 'Research can only be retried by an admin before kickoff while research is enabled.');

                return;
            }
            $this->state('running', 'Refreshing sources and rerunning research.');
            $ingestor->sync([$game->homeTeam->abbreviation, $game->awayTeam->abbreviation]);
            $revision = $pipeline->review($game, forceResearch: true, requestedBy: $this->userId);
            $reason = data_get($revision?->brief, 'research_refresh.deferred_reason');
            $this->state($revision && ! $reason ? 'completed' : 'blocked',
                $revision ? ($reason ?: 'Research rerun completed. Review the updated assessment.') : 'Research is already running or the game is no longer eligible.');
        } catch (Throwable $exception) {
            report($exception);
            $this->state('failed', 'Research retry failed. Please try again later.');
        } finally {
            Cache::restoreLock(self::key($this->gameId).':lock', $this->owner)->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->state('failed', 'Research retry failed or timed out. You can retry again.');
        Cache::restoreLock(self::key($this->gameId).':lock', $this->owner)->release();
    }

    private function state(string $status, string $message): void
    {
        $key = self::key($this->gameId);
        $state = Cache::get($key);
        if (($state['run_id'] ?? null) !== $this->owner) {
            return;
        }
        Cache::put($key, [...$state, 'status' => $status, 'message' => $message, 'updated_at' => now()->toIso8601String()], now()->addMinutes(30));
    }
}
