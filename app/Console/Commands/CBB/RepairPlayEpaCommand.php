<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\CalculateGamePlayEpa;
use App\Actions\CBB\RefreshTeamPlayEpa;
use App\Actions\ESPN\CBB\SyncPlays;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Support\BasketballSeason;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;

class RepairPlayEpaCommand extends Command
{
    protected $signature = 'cbb:repair-play-epa
        {--season= : Defaults to the current basketball season}
        {--after-game-id=0 : Resume after this game ID}
        {--game-id= : Repair one completed game}
        {--limit=10 : Maximum games per batch (1-50)}
        {--apply : Fetch complete feeds and persist repaired play EPA and team EPA}';

    protected $description = 'Repair completed CBB games missing play EPA in bounded, resumable batches';

    public function handle(SyncPlays $sync, RefreshTeamPlayEpa $refresh, CalculateGamePlayEpa $calculate, SportsViewCache $cache): int
    {
        $season = (int) ($this->option('season') ?: BasketballSeason::forDate());
        $limit = (int) $this->option('limit');
        if ($season < 1 || $limit < 1 || $limit > 50) {
            $this->error('Use a valid season and a limit between 1 and 50.');

            return self::INVALID;
        }
        $query = Game::query()->where('season', $season)
            ->where('status', config('cbb.statuses.final'))
            ->whereHas('plays')
            ->where('id', '>', (int) $this->option('after-game-id'))
            ->when($this->option('game-id'), fn ($query, $id) => $query->whereKey((int) $id))
            ->whereDoesntHave('plays', fn ($query) => $query->whereNotNull('true_epa'));
        $remaining = (clone $query)->count();
        $games = $query->orderBy('id')->limit($limit)->get();
        $this->info("Season {$season}: {$remaining} games missing EPA; batch {$games->count()}.");
        if (! $this->option('apply')) {
            $this->line('Preview only. Use --apply to repair this batch.');

            return self::SUCCESS;
        }
        $failed = 0;
        foreach ($games as $game) {
            try {
                $count = $sync->execute((string) $game->espn_event_id);
                if ($count === 0) {
                    throw new \RuntimeException('Play sync or EPA calculation failed.');
                }
                $eligible = $calculate->execute($game);
                foreach (Team::query()->whereIn('id', [$game->home_team_id, $game->away_team_id])->get() as $team) {
                    $refresh->execute($team, $season);
                }
                $this->line("Game {$game->id}: {$count} plays; {$eligible} EPA plays.");
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("Game {$game->id}: {$exception->getMessage()}");
                report($exception);
            }
        }
        $cache->bustSegments([SportsViewCache::SEGMENT_TEAM_METRICS_INDEX, SportsViewCache::SEGMENT_TEAM_METRICS_BY_TEAM]);
        $this->info('Batch complete. Failed: '.$failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
