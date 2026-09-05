<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\GenerateCanonicalPrediction;
use App\Models\NBA\Game;
use Illuminate\Console\Command;

class GenerateCanonicalPredictionsCommand extends Command
{
    protected $signature = 'nba:generate-canonical-predictions
        {--game= : Generate for one NBA game ID}
        {--season= : Limit to a season}
        {--date= : Limit to a game date (YYYY-MM-DD)}
        {--draft : Create immutable revisions without publishing them}';

    protected $description = 'Generate canonical NBA predictions through the snapshot and calculation-release lifecycle';

    public function handle(GenerateCanonicalPrediction $generator): int
    {
        $query = Game::query()
            ->with(['sportEvent', 'homeTeam', 'awayTeam'])
            ->whereNotNull('sport_event_id')
            ->whereHas('sportEvent', fn ($query) => $query->where('starts_at', '>=', now()))
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->withoutCompletedPlayoffSeriesPlaceholders()
            ->orderBy('game_date')
            ->orderBy('id');

        if (filled($this->option('game'))) {
            $query->whereKey((int) $this->option('game'));
        }

        if (filled($this->option('season'))) {
            $query->where('season', (int) $this->option('season'));
        }

        if (filled($this->option('date'))) {
            $query->whereDate('game_date', (string) $this->option('date'));
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->warn('No eligible linked NBA games matched the request.');

            return self::SUCCESS;
        }

        $published = ! $this->option('draft');
        $succeeded = 0;
        $failures = 0;

        foreach ($games as $game) {
            try {
                $generator->execute($game, $published, 'artisan');
                $succeeded++;
            } catch (\Throwable $exception) {
                $failures++;
                $this->error("Game {$game->getKey()}: {$exception->getMessage()}");
            }
        }

        $this->info("Canonical NBA generation complete: {$succeeded} succeeded, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
