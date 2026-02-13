<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use Illuminate\Console\Command;

class CalibrateAllParametersCommand extends Command
{
    protected $signature = 'nfl:calibrate-all
                            {--season=2025 : Season to calibrate against}
                            {--metric=accuracy : Optimization metric (accuracy, brier)}
                            {--quick : Run quick calibration with fewer combinations}';

    protected $description = 'Comprehensive parameter calibration to optimize all ELO system parameters simultaneously';

    protected array $eloHistory = [];

    public function handle(): int
    {
        $season = $this->option('season');
        $metric = $this->option('metric');
        $quick = $this->option('quick');

        $this->info("Running comprehensive parameter calibration for {$season} season...");
        $this->info("Optimization metric: {$metric}");
        $this->newLine();

        $games = Game::query()
            ->where('status', 'STATUS_FINAL')
            ->where('season', $season)
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->orderBy('id')
            ->get();

        if ($games->isEmpty()) {
            $this->error('No games found for calibration');

            return Command::FAILURE;
        }

        $this->info("Testing against {$games->count()} games");
        $this->newLine();

        // Define parameter ranges to test
        $parameterSets = $this->generateParameterSets($quick);

        $this->info("Testing {$parameterSets->count()} parameter combinations...");
        $this->newLine();

        $bar = $this->output->createProgressBar($parameterSets->count());
        $bar->start();

        $results = [];

        foreach ($parameterSets as $params) {
            $score = $this->testParameterSet($games, $params, $metric);

            $results[] = array_merge($params, [
                'score' => $score['value'],
                'accuracy' => $score['accuracy'],
                'brier' => $score['brier'],
                'correct' => $score['correct'],
                'total' => $score['total'],
            ]);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Sort by score (lower is better for brier, higher for accuracy)
        usort($results, function ($a, $b) use ($metric) {
            if ($metric === 'brier') {
                return $a['score'] <=> $b['score']; // Lower is better
            }

            return $b['score'] <=> $a['score']; // Higher is better
        });

        // Display top 10 results
        $this->info('🏆 Top 10 Parameter Combinations:');
        $this->newLine();

        $rows = [];
        foreach (array_slice($results, 0, 10) as $index => $result) {
            $rows[] = [
                $index + 1,
                $result['hfa'],
                $result['k_factor'],
                $result['playoff_mult'],
                $result['recency_mult'],
                $result['mov_coef'],
                $result['accuracy'].'%',
                $result['brier'],
            ];
        }

        $this->table(
            ['Rank', 'HFA', 'K-Factor', 'Playoff x', 'Recency x', 'MOV Coef', 'Accuracy', 'Brier'],
            $rows
        );

        $this->newLine();
        $best = $results[0];
        $this->info('✨ Optimal Parameters:');
        $this->line("  HFA: {$best['hfa']}");
        $this->line("  K-Factor: {$best['k_factor']}");
        $this->line("  Playoff Multiplier: {$best['playoff_mult']}");
        $this->line("  Recency Multiplier: {$best['recency_mult']}");
        $this->line("  MOV Coefficient: {$best['mov_coef']}");
        $this->newLine();
        $this->line("  Accuracy: {$best['accuracy']}%");
        $this->line("  Brier Score: {$best['brier']}");
        $this->line("  Correct: {$best['correct']}/{$best['total']}");

        return Command::SUCCESS;
    }

    protected function generateParameterSets(bool $quick): \Illuminate\Support\Collection
    {
        $sets = collect();

        // Get parameter ranges from config
        $mode = $quick ? 'quick' : 'full';
        $config = config("nfl.calibration.all_parameters.{$mode}");

        $hfaValues = $config['hfa'];
        $kFactorValues = $config['k_factor'];
        $playoffMultValues = $config['playoff_mult'];
        $recencyMultValues = $config['recency_mult'];
        $movCoefValues = $config['mov_coef'];

        // Generate all combinations
        foreach ($hfaValues as $hfa) {
            foreach ($kFactorValues as $kFactor) {
                foreach ($playoffMultValues as $playoffMult) {
                    foreach ($recencyMultValues as $recencyMult) {
                        foreach ($movCoefValues as $movCoef) {
                            $sets->push([
                                'hfa' => $hfa,
                                'k_factor' => $kFactor,
                                'playoff_mult' => $playoffMult,
                                'recency_mult' => $recencyMult,
                                'mov_coef' => $movCoef,
                            ]);
                        }
                    }
                }
            }
        }

        return $sets;
    }

    protected function testParameterSet($games, array $params, string $metric): array
    {
        // Reset ELO ratings for all teams
        $this->eloHistory = [];

        $correct = 0;
        $brierScore = 0;
        $total = 0;

        foreach ($games as $game) {
            if (! $game->homeTeam || ! $game->awayTeam) {
                continue;
            }

            // Get current ELO ratings
            $homeElo = $this->getTeamElo($game->home_team_id);
            $awayElo = $this->getTeamElo($game->away_team_id);

            // Apply HFA (unless neutral site)
            $adjustedHomeElo = $game->neutral_site ? $homeElo : $homeElo + $params['hfa'];

            // Calculate win probability
            $winProbability = $this->calculateWinProbability($adjustedHomeElo, $awayElo);

            // Determine actual outcome
            $homeWon = $game->home_score > $game->away_score;
            $actualOutcome = $homeWon ? 1 : 0;

            // Track accuracy
            $predictedHomeWin = $winProbability > 0.5;
            if ($predictedHomeWin === $homeWon) {
                $correct++;
            }

            // Calculate Brier score component
            $brierScore += pow($winProbability - $actualOutcome, 2);

            $total++;

            // Update ELO ratings
            $kFactor = $this->calculateKFactor($game, $params);
            $homeExpected = $this->calculateWinProbability($adjustedHomeElo, $awayElo);
            $awayExpected = 1 - $homeExpected;

            $homeActual = $homeWon ? 1 : 0;
            $awayActual = 1 - $homeActual;

            $homeChange = $kFactor * ($homeActual - $homeExpected);
            $awayChange = $kFactor * ($awayActual - $awayExpected);

            $this->updateTeamElo($game->home_team_id, $homeChange);
            $this->updateTeamElo($game->away_team_id, $awayChange);
        }

        $accuracy = round(($correct / $total) * 100, 2);
        $avgBrier = round($brierScore / $total, 4);

        return [
            'value' => $metric === 'accuracy' ? $accuracy : $avgBrier,
            'accuracy' => $accuracy,
            'brier' => $avgBrier,
            'correct' => $correct,
            'total' => $total,
        ];
    }

    protected function calculateKFactor(Game $game, array $params): float
    {
        $kFactor = $params['k_factor'];

        // Apply recency weighting for early season games
        if ($game->week && $game->week <= 4 && $game->season_type === 'Regular Season') {
            $kFactor *= $params['recency_mult'];
        }

        // Apply playoff multiplier
        if ($game->season_type === 'Postseason') {
            $kFactor *= $params['playoff_mult'];
        }

        // Apply margin of victory multiplier
        $margin = abs($game->home_score - $game->away_score);
        $movMultiplier = min(2.2, 1.0 + (log($margin + 1) * $params['mov_coef']));
        $kFactor *= $movMultiplier;

        return $kFactor;
    }

    protected function calculateWinProbability(float $ratingA, float $ratingB): float
    {
        return 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
    }

    protected function getTeamElo(int $teamId): float
    {
        return $this->eloHistory[$teamId] ?? config('nfl.elo.default_rating');
    }

    protected function updateTeamElo(int $teamId, float $change): void
    {
        $current = $this->getTeamElo($teamId);
        $this->eloHistory[$teamId] = $current + $change;
    }
}
