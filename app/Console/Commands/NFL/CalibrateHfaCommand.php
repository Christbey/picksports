<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use Illuminate\Console\Command;

class CalibrateHfaCommand extends Command
{
    protected $signature = 'nfl:calibrate-hfa
                            {--season=2025 : Season to test against}
                            {--min= : Minimum HFA value to test (defaults to config)}
                            {--max= : Maximum HFA value to test (defaults to config)}
                            {--step= : Step size between values (defaults to config)}';

    protected $description = 'Test different Home Field Advantage values to find optimal setting';

    public function handle(): int
    {
        $season = $this->option('season');
        $min = $this->option('min') ?? config('nfl.calibration.hfa.min');
        $max = $this->option('max') ?? config('nfl.calibration.hfa.max');
        $step = $this->option('step') ?? config('nfl.calibration.hfa.step');

        $this->info("Testing HFA values from {$min} to {$max} (step: {$step}) against {$season} season data...");
        $this->newLine();

        $games = Game::query()
            ->where('status', 'STATUS_FINAL')
            ->where('season', $season)
            ->where('neutral_site', 0) // Only non-neutral games for HFA testing
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->get();

        if ($games->isEmpty()) {
            $this->error('No games found for testing');

            return Command::FAILURE;
        }

        $this->info("Testing against {$games->count()} non-neutral site games");
        $this->newLine();

        $results = [];

        for ($hfa = $min; $hfa <= $max; $hfa += $step) {
            $correct = 0;
            $total = 0;

            foreach ($games as $game) {
                $homeEloRecord = EloRating::query()
                    ->where('team_id', $game->home_team_id)
                    ->where('date', '<=', $game->game_date)
                    ->orderBy('date', 'desc')
                    ->first();

                $awayEloRecord = EloRating::query()
                    ->where('team_id', $game->away_team_id)
                    ->where('date', '<=', $game->game_date)
                    ->orderBy('date', 'desc')
                    ->first();

                if (! $homeEloRecord || ! $awayEloRecord) {
                    continue;
                }

                $homeElo = $homeEloRecord->elo_rating;
                $awayElo = $awayEloRecord->elo_rating;

                // Apply HFA
                $adjustedHomeElo = $homeElo + $hfa;

                // Predict winner
                $predictedHomeWin = $adjustedHomeElo > $awayElo;
                $actualHomeWin = $game->home_score > $game->away_score;

                if ($predictedHomeWin === $actualHomeWin) {
                    $correct++;
                }
                $total++;
            }

            $accuracy = round(($correct / $total) * 100, 2);

            $results[] = [
                'hfa' => $hfa,
                'correct' => $correct,
                'total' => $total,
                'accuracy' => $accuracy,
            ];
        }

        // Sort by accuracy
        usort($results, fn ($a, $b) => $b['accuracy'] <=> $a['accuracy']);

        // Display results
        $this->table(
            ['HFA Value', 'Correct', 'Total', 'Accuracy %'],
            array_map(fn ($r) => [
                $r['hfa'],
                $r['correct'],
                $r['total'],
                $r['accuracy'].'%',
            ], $results)
        );

        $this->newLine();
        $best = $results[0];
        $this->info("🏆 Optimal HFA Value: {$best['hfa']} ({$best['accuracy']}% accuracy)");

        return Command::SUCCESS;
    }
}
