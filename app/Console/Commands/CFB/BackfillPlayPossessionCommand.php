<?php

namespace App\Console\Commands\CFB;

use App\Actions\ESPN\CFB\SyncPlays;
use App\Models\CFB\Game;
use Illuminate\Console\Command;

class BackfillPlayPossessionCommand extends Command
{
    protected $signature = 'cfb:backfill-play-possession
        {--season= : Limit to season (e.g. 2025)}
        {--game_id= : Limit to a single cfb_games.id}
        {--limit=0 : Limit number of games (0 = all)}
        {--dry-run : Preview matching games without writing}';

    protected $description = 'Resync CFB play-by-play for games with missing possession_team_id';

    public function handle(SyncPlays $syncPlays): int
    {
        $season = $this->option('season');
        $gameId = $this->option('game_id');
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Game::query()
            ->whereHas('plays', fn ($plays) => $plays->whereNull('possession_team_id'))
            ->orderByDesc('game_date');

        if ($season !== null && $season !== '') {
            $query->where('season', (int) $season);
        }

        if ($gameId !== null && $gameId !== '') {
            $query->where('id', (int) $gameId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $games = $query->get(['id', 'espn_event_id']);
        if ($games->isEmpty()) {
            $this->warn('No matching games found.');

            return self::SUCCESS;
        }

        $this->info("Processing {$games->count()} game(s)...".($dryRun ? ' [dry-run]' : ''));
        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $updated = 0;

        foreach ($games as $game) {
            if (! $dryRun) {
                $syncPlays->execute((string) $game->espn_event_id);
                $updated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Dry run complete. Candidate games to resync: {$games->count()}");
        } else {
            $this->info("Backfill complete. Resynced games: {$updated}");
        }

        return self::SUCCESS;
    }
}
