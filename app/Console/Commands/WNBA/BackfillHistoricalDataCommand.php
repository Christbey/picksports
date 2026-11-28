<?php

namespace App\Console\Commands\WNBA;

use App\Models\WNBA\Game;
use App\Services\WNBA\HistoricalEloReplay;
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
        {--regrade : Retired; use bounded audited legacy prediction grade repair}';

    protected $description = 'Backfill WNBA historical games, odds, metrics, predictions, and reports across seasons';

    public function handle(): int
    {
        if ($this->option('regrade')) {
            $this->error('The --regrade flag is retired. Use the bounded audited sports:repair-legacy-prediction-grades workflow.');

            return self::FAILURE;
        }

        $stage = strtolower((string) $this->option('stage'));
        if (! in_array($stage, ['full', 'espn', 'odds', 'metrics', 'predictions', 'reports'], true)) {
            $this->error('The --stage option must be one of: full, espn, odds, metrics, predictions, reports.');

            return self::FAILURE;
        }

        $fromSeason = filter_var($this->option('from-season'), FILTER_VALIDATE_INT);
        $toSeason = filter_var($this->option('to-season'), FILTER_VALIDATE_INT);
        if ($fromSeason === false || $toSeason === false || $fromSeason < 1900 || $toSeason > (int) now()->year
            || $fromSeason > $toSeason || $toSeason - $fromSeason >= 10) {
            $this->error('Provide a valid ordered season range within 1900-current year and at most 10 seasons.');

            return self::FAILURE;
        }

        $this->info("WNBA historical backfill for {$fromSeason}-{$toSeason} ({$stage}).");

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
                try {
                    $count = app(HistoricalEloReplay::class)->replay($fromSeason, $season);
                    $this->info("Historical Elo replay completed for {$count} games without changing current team ratings.");
                } catch (\RuntimeException|\DomainException $exception) {
                    $this->error('Historical Elo replay refused or failed; prior history was retained.');

                    return self::FAILURE;
                }

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
