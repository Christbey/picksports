<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use Illuminate\Console\Command;

class CalibrateSpreadCommand extends Command
{
    protected $signature = 'nfl:calibrate-spread
                            {--season=2025 : Season to calibrate against}
                            {--hfa= : Home field advantage to use (defaults to config)}
                            {--min= : Minimum points per ELO to test (defaults to config)}
                            {--max= : Maximum points per ELO to test (defaults to config)}
                            {--step= : Step size between values (defaults to config)}';

    protected $description = 'Calibrate the ELO-to-points conversion factor for spread predictions';

    public function handle(): int
    {
        $season = $this->option('season');
        $hfa = $this->option('hfa') ?? config('nfl.elo.home_field_advantage');
        $min = $this->option('min') ?? config('nfl.calibration.spread.min');
        $max = $this->option('max') ?? config('nfl.calibration.spread.max');
        $step = $this->option('step') ?? config('nfl.calibration.spread.step');

        $this->info("Calibrating spread conversion factor for {$season} season...");
        $this->info("Using HFA: {$hfa}");
        $this->newLine();

        $games = Game::query()
            ->where('status', 'STATUS_FINAL')
            ->where('season', $season)
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->get();

        if ($games->isEmpty()) {
            $this->error('No games found for calibration');

            return Command::FAILURE;
        }

        $this->info("Testing against {$games->count()} games");
        $this->newLine();

        $results = [];

        for ($pointsPerElo = $min; $pointsPerElo <= $max; $pointsPerElo += $step) {
            $errors = [];

            foreach ($games as $game) {
                if (! $game->homeTeam || ! $game->awayTeam) {
                    continue;
                }

                // Get ELO ratings at the time of the game
                $homeElo = $this->getEloAtDate($game->home_team_id, $game->game_date);
                $awayElo = $this->getEloAtDate($game->away_team_id, $game->game_date);

                // Apply HFA (unless neutral site)
                $adjustedHomeElo = $game->neutral_site ? $homeElo : $homeElo + $hfa;

                // Calculate predicted spread
                $eloDiff = $adjustedHomeElo - $awayElo;
                $predictedSpread = $eloDiff * $pointsPerElo;

                // Calculate actual spread
                $actualSpread = $game->home_score - $game->away_score;

                // Track error
                $errors[] = abs($actualSpread - $predictedSpread);
            }

            if (empty($errors)) {
                continue;
            }

            $mae = array_sum($errors) / count($errors);
            $median = $this->median($errors);

            $results[] = [
                'points_per_elo' => round($pointsPerElo, 4),
                'mae' => round($mae, 2),
                'median' => round($median, 2),
            ];
        }

        // Sort by MAE (lower is better)
        usort($results, fn ($a, $b) => $a['mae'] <=> $b['mae']);

        // Display top 10 results
        $this->info('🏆 Top 10 Conversion Factors:');
        $this->newLine();

        $rows = [];
        foreach (array_slice($results, 0, 10) as $index => $result) {
            $rows[] = [
                $index + 1,
                $result['points_per_elo'],
                $result['mae'].' pts',
                $result['median'].' pts',
            ];
        }

        $this->table(
            ['Rank', 'Points per ELO', 'Mean Abs Error', 'Median Error'],
            $rows
        );

        $this->newLine();
        $best = $results[0];
        $this->info("✨ Optimal Conversion Factor: {$best['points_per_elo']}");
        $this->line("  Mean Absolute Error: {$best['mae']} points");
        $this->line("  Median Error: {$best['median']} points");
        $this->newLine();
        $this->line('Example spreads with this factor:');
        $this->line('  50 ELO difference = '.round(50 * $best['points_per_elo'], 1).' points');
        $this->line('  100 ELO difference = '.round(100 * $best['points_per_elo'], 1).' points');
        $this->line('  150 ELO difference = '.round(150 * $best['points_per_elo'], 1).' points');
        $this->line('  200 ELO difference = '.round(200 * $best['points_per_elo'], 1).' points');

        return Command::SUCCESS;
    }

    protected function getEloAtDate(int $teamId, $gameDate): float
    {
        $eloRecord = EloRating::query()
            ->where('team_id', $teamId)
            ->where('date', '<=', $gameDate)
            ->orderBy('date', 'desc')
            ->first();

        return $eloRecord ? (float) $eloRecord->elo_rating : config('nfl.elo.default_rating');
    }

    protected function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }
}
