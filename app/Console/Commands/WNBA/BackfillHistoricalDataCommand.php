<?php

namespace App\Console\Commands\WNBA;

use App\Actions\WNBA\CalculateElo;
use App\Models\WNBA\EloRating;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class BackfillHistoricalDataCommand extends Command
{
    protected $signature = 'wnba:backfill-historical-data
        {--from-season=2021 : First WNBA season to backfill}
        {--to-season=2025 : Last WNBA season to backfill}
        {--stage=full : full, espn, odds, metrics, predictions, or reports}
        {--season-type=2 : Season type for metrics/predictions}
        {--odds-hours-before=24 : Hours before tip for historical odds snapshots}
        {--limit=0 : Per-season game limit for detail, odds, and prediction stages}
        {--force-details : Re-fetch ESPN details for all final games}
        {--skip-espn : Skip ESPN scoreboard/details backfill}
        {--skip-odds : Skip historical odds backfill}
        {--skip-metrics : Skip Elo and team metrics recalculation}
        {--skip-predictions : Skip historical prediction generation/grading}
        {--hydrate-current-odds : Copy historical odds onto games.odds_data when empty}
        {--regrade : Clear existing prediction grades before regrading selected historical predictions}';

    protected $description = 'Backfill WNBA historical games, odds, metrics, predictions, and reports across seasons';

    public function handle(): int
    {
        $stage = strtolower((string) $this->option('stage'));
        if (! in_array($stage, ['full', 'espn', 'odds', 'metrics', 'predictions', 'reports'], true)) {
            $this->error('The --stage option must be one of: full, espn, odds, metrics, predictions, reports.');

            return self::FAILURE;
        }

        $fromSeason = (int) $this->option('from-season');
        $toSeason = (int) $this->option('to-season');
        if ($fromSeason > $toSeason) {
            $this->error('--from-season must be less than or equal to --to-season.');

            return self::FAILURE;
        }

        $this->info("WNBA historical backfill for {$fromSeason}-{$toSeason} ({$stage}).");

        if ($this->shouldRun($stage, 'metrics') && ! (bool) $this->option('skip-metrics')) {
            $this->rebuildHistoricalElo($fromSeason, $toSeason);
        }

        for ($season = $fromSeason; $season <= $toSeason; $season++) {
            $this->newLine();
            $this->info("Season {$season}");

            if ($this->shouldRun($stage, 'espn') && ! (bool) $this->option('skip-espn')) {
                $this->runArtisan('espn:backfill-historical', array_filter([
                    'sport' => 'wnba',
                    '--season' => $season,
                    '--stage' => 'full',
                    '--sync' => true,
                    '--force-details' => (bool) $this->option('force-details'),
                    '--limit' => max(0, (int) $this->option('limit')),
                ], fn (mixed $value): bool => $value !== false && $value !== null));
            }

            if ($this->shouldRun($stage, 'odds') && ! (bool) $this->option('skip-odds')) {
                $this->runArtisan('wnba:sync-historical-odds', array_filter([
                    '--season' => $season,
                    '--hours-before' => max(0, (int) $this->option('odds-hours-before')),
                    '--limit' => max(0, (int) $this->option('limit')),
                    '--hydrate-current-when-empty' => (bool) $this->option('hydrate-current-odds'),
                ], fn (mixed $value): bool => $value !== false && $value !== null));
            }

            if ($this->shouldRun($stage, 'metrics') && ! (bool) $this->option('skip-metrics')) {
                $this->runArtisan('wnba:calculate-team-metrics', [
                    '--season' => $season,
                    '--season-type' => (string) $this->option('season-type'),
                ]);
            }

            if ($this->shouldRun($stage, 'predictions') && ! (bool) $this->option('skip-predictions')) {
                $this->runArtisan('wnba:backfill-historical-predictions', array_filter([
                    '--season' => $season,
                    '--season-type' => (string) $this->option('season-type'),
                    '--limit' => max(0, (int) $this->option('limit')),
                    '--regrade' => (bool) $this->option('regrade'),
                ], fn (mixed $value): bool => $value !== false && $value !== null && $value !== ''));
            }

            if ($this->shouldRun($stage, 'reports')) {
                $this->seasonSummary($season);
            }
        }

        $this->newLine();
        $this->info('WNBA historical backfill finished.');

        return self::SUCCESS;
    }

    private function rebuildHistoricalElo(int $fromSeason, int $toSeason): void
    {
        $games = Game::query()
            ->whereBetween('season', [$fromSeason, $toSeason])
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->orderBy('id')
            ->get();

        if ($games->isEmpty()) {
            $this->warn('No completed WNBA games found for historical Elo replay.');

            return;
        }

        $this->line("Replaying scoped historical Elo for {$games->count()} WNBA game(s).");

        $defaultElo = (int) config('wnba.elo.default', 1500);
        $currentTeamElos = Team::query()
            ->pluck('elo_rating', 'id')
            ->map(fn (mixed $elo): int => (int) round((float) $elo))
            ->all();

        Team::query()->update(['elo_rating' => $defaultElo]);

        EloRating::query()
            ->whereBetween('season', [$fromSeason, $toSeason])
            ->delete();

        $calculator = app(CalculateElo::class);
        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        try {
            foreach ($games as $game) {
                $calculator->execute($game, skipIfExists: false);
                $bar->advance();
            }
        } finally {
            $bar->finish();
            $this->newLine(2);

            foreach ($currentTeamElos as $teamId => $elo) {
                Team::query()
                    ->whereKey((int) $teamId)
                    ->update(['elo_rating' => $elo]);
            }
        }

        $this->info('Historical Elo replay completed without changing current team Elo ratings.');
    }

    private function shouldRun(string $stage, string $target): bool
    {
        return $stage === 'full' || $stage === $target;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runArtisan(string $command, array $arguments): void
    {
        $this->line('$ php artisan '.$command.' '.$this->formatArguments($arguments));

        $exitCode = Artisan::call($command, $arguments, $this->output);

        if ($exitCode !== self::SUCCESS) {
            throw new \RuntimeException("Command {$command} failed with exit code {$exitCode}.");
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function formatArguments(array $arguments): string
    {
        return collect($arguments)
            ->map(function (mixed $value, string $key): string {
                if (str_starts_with($key, '--')) {
                    return is_bool($value) ? $key : "{$key}={$value}";
                }

                return (string) $value;
            })
            ->implode(' ');
    }

    private function seasonSummary(int $season): void
    {
        $games = Game::query()->where('season', $season);
        $finalGames = (clone $games)->where('status', 'STATUS_FINAL')->count();
        $gamesWithOdds = (clone $games)->whereNotNull('odds_data')->count();
        $gamesWithPredictions = (clone $games)->whereHas('prediction')->count();

        $this->table(
            ['Season', 'Final Games', 'Games With Odds', 'Games With Predictions'],
            [[$season, $finalGames, $gamesWithOdds, $gamesWithPredictions]],
        );
    }
}
