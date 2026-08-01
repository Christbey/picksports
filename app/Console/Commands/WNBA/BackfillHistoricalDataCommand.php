<?php

namespace App\Console\Commands\WNBA;

use App\Models\WNBA\EloRating;
use App\Models\WNBA\Game;
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
                $this->rebuildHistoricalElo($fromSeason, $season);

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

        $this->line("Replaying scoped historical Elo for {$games->count()} WNBA game(s) without mutating current team ratings.");

        $defaultElo = (int) config('wnba.elo.default', 1500);
        $teamElos = [];

        EloRating::query()
            ->whereBetween('season', [$fromSeason, $toSeason])
            ->delete();

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        foreach ($games as $game) {
            $homeTeamId = (int) $game->home_team_id;
            $awayTeamId = (int) $game->away_team_id;
            $homeElo = $teamElos[$homeTeamId] ?? $defaultElo;
            $awayElo = $teamElos[$awayTeamId] ?? $defaultElo;

            [$homeChange, $awayChange] = $this->eloChanges($game, $homeElo, $awayElo);
            $newHomeElo = (int) round($homeElo + $homeChange);
            $newAwayElo = (int) round($awayElo + $awayChange);

            $teamElos[$homeTeamId] = $newHomeElo;
            $teamElos[$awayTeamId] = $newAwayElo;

            $this->saveHistoricalElo($homeTeamId, $game, $newHomeElo, $homeChange);
            $this->saveHistoricalElo($awayTeamId, $game, $newAwayElo, $awayChange);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Historical Elo replay completed.');
    }

    /**
     * @return array{0:float,1:float}
     */
    private function eloChanges(Game $game, int $homeElo, int $awayElo): array
    {
        $homeAdvantage = (float) config('wnba.elo.home_court_advantage', 80);
        $homeExpected = 1 / (1 + pow(10, ($awayElo - ($homeElo + $homeAdvantage)) / 400));
        $awayExpected = 1 - $homeExpected;
        $homeActual = (float) $game->home_score > (float) $game->away_score ? 1.0 : 0.0;
        $awayActual = 1 - $homeActual;
        $kFactor = $this->historicalKFactor($game);

        return [
            round($kFactor * ($homeActual - $homeExpected), 1),
            round($kFactor * ($awayActual - $awayExpected), 1),
        ];
    }

    private function historicalKFactor(Game $game): float
    {
        $kFactor = (float) config('wnba.elo.base_k_factor', 25);
        $kFactor *= $this->marginMultiplier(abs((int) $game->home_score - (int) $game->away_score));

        if (in_array((string) $game->season_type, $this->seasonTypeCandidates(config('wnba.season.types.postseason', 3)), true)) {
            $kFactor *= (float) config('wnba.elo.playoff_multiplier', 1.0);
        }

        return $kFactor;
    }

    private function marginMultiplier(int $margin): float
    {
        foreach (config('wnba.elo.margin_multipliers', []) as $tier) {
            if (($tier['max_margin'] ?? null) === null || $margin <= (int) $tier['max_margin']) {
                return (float) ($tier['multiplier'] ?? 1.0);
            }
        }

        return 1.0;
    }

    private function saveHistoricalElo(int $teamId, Game $game, int $newElo, float $eloChange): void
    {
        EloRating::query()->updateOrCreate(
            [
                'team_id' => $teamId,
                'game_id' => $game->id,
            ],
            [
                'season' => $game->season,
                'week' => $game->week ?? null,
                'date' => $game->game_date,
                'elo_rating' => $newElo,
                'elo_change' => $eloChange,
            ]
        );
    }

    /**
     * @return list<string>
     */
    private function seasonTypeCandidates(mixed $seasonType): array
    {
        if ($seasonType === null || $seasonType === '') {
            return [];
        }

        $typeNames = config('wnba.season.type_names', []);
        $typesByKey = config('wnba.season.types', []);
        $candidates = [(string) $seasonType];

        if (is_string($seasonType) && isset($typeNames[$seasonType])) {
            $candidates[] = (string) $typeNames[$seasonType];
        }

        if (is_string($seasonType) && isset($typesByKey[$seasonType])) {
            $candidates[] = (string) $typesByKey[$seasonType];
        }

        if (is_numeric($seasonType)) {
            $matchedKey = array_search((int) $seasonType, $typesByKey, true);
            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                $candidates[] = (string) ($typeNames[$matchedKey] ?? '');
            }
        }

        return array_values(array_unique(array_filter($candidates, fn (string $value): bool => $value !== '')));
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
