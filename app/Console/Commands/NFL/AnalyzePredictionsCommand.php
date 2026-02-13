<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Prediction;
use Illuminate\Console\Command;

class AnalyzePredictionsCommand extends Command
{
    protected $signature = 'nfl:analyze-predictions
                            {--season=2025 : Season to analyze}
                            {--detailed : Show detailed game-by-game results}';

    protected $description = 'Analyze prediction accuracy and calibration metrics';

    public function handle(): int
    {
        $season = $this->option('season');

        $predictions = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function ($query) use ($season) {
                $query->where('season', $season)
                    ->where('status', 'STATUS_FINAL');
            })
            ->get();

        if ($predictions->isEmpty()) {
            $this->warn('No predictions found for completed games in season '.$season);

            return Command::SUCCESS;
        }

        $this->info("Analyzing {$predictions->count()} predictions from {$season} season...");
        $this->newLine();

        // Calculate metrics
        $metrics = $this->calculateMetrics($predictions);

        // Display overall metrics
        $this->displayOverallMetrics($metrics);
        $this->newLine();

        // Display calibration by confidence bucket
        $this->displayCalibrationByBucket($metrics['buckets']);
        $this->newLine();

        // Display spread accuracy
        $this->displaySpreadAccuracy($metrics);
        $this->newLine();

        // Show detailed results if requested
        if ($this->option('detailed')) {
            $this->displayDetailedResults($predictions);
        }

        return Command::SUCCESS;
    }

    protected function calculateMetrics($predictions): array
    {
        $correct = 0;
        $brierScore = 0;
        $logLoss = 0;
        $spreadErrors = [];
        $buckets = $this->initializeBuckets();

        foreach ($predictions as $prediction) {
            $game = $prediction->game;

            // Determine actual winner
            $homeWon = $game->home_score > $game->away_score;
            $actualOutcome = $homeWon ? 1 : 0;

            // Predicted probability that home team wins
            $predictedProb = (float) $prediction->win_probability;

            // Accuracy
            $predictedHomeWin = $predictedProb > 0.5;
            if ($predictedHomeWin === $homeWon) {
                $correct++;
            }

            // Brier Score: (predicted_prob - actual_outcome)^2
            $brierScore += pow($predictedProb - $actualOutcome, 2);

            // Log Loss: -[actual*log(predicted) + (1-actual)*log(1-predicted)]
            $epsilon = 1e-15; // Prevent log(0)
            $clippedProb = max($epsilon, min(1 - $epsilon, $predictedProb));
            $logLoss += -($actualOutcome * log($clippedProb) + (1 - $actualOutcome) * log(1 - $clippedProb));

            // Spread error (how far off was the predicted spread)
            $actualSpread = $game->home_score - $game->away_score;
            $predictedSpread = (float) $prediction->predicted_spread;
            $spreadErrors[] = abs($actualSpread - $predictedSpread);

            // Bucket by confidence
            $bucket = $this->getBucket($predictedProb);
            $buckets[$bucket]['total']++;
            if ($homeWon) {
                $buckets[$bucket]['wins']++;
            }
        }

        $total = $predictions->count();

        return [
            'total' => $total,
            'correct' => $correct,
            'accuracy' => round(($correct / $total) * 100, 2),
            'brier_score' => round($brierScore / $total, 4),
            'log_loss' => round($logLoss / $total, 4),
            'mean_absolute_spread_error' => round(array_sum($spreadErrors) / count($spreadErrors), 2),
            'median_spread_error' => $this->median($spreadErrors),
            'buckets' => $buckets,
        ];
    }

    protected function initializeBuckets(): array
    {
        return [
            '50-55%' => ['total' => 0, 'wins' => 0, 'range' => [0.50, 0.55]],
            '55-60%' => ['total' => 0, 'wins' => 0, 'range' => [0.55, 0.60]],
            '60-65%' => ['total' => 0, 'wins' => 0, 'range' => [0.60, 0.65]],
            '65-70%' => ['total' => 0, 'wins' => 0, 'range' => [0.65, 0.70]],
            '70-75%' => ['total' => 0, 'wins' => 0, 'range' => [0.70, 0.75]],
            '75-80%' => ['total' => 0, 'wins' => 0, 'range' => [0.75, 0.80]],
            '80-85%' => ['total' => 0, 'wins' => 0, 'range' => [0.80, 0.85]],
            '85-90%' => ['total' => 0, 'wins' => 0, 'range' => [0.85, 0.90]],
            '90-95%' => ['total' => 0, 'wins' => 0, 'range' => [0.90, 0.95]],
            '95-100%' => ['total' => 0, 'wins' => 0, 'range' => [0.95, 1.00]],
        ];
    }

    protected function getBucket(float $probability): string
    {
        // Normalize to higher probability (home or away)
        $prob = max($probability, 1 - $probability);

        return match (true) {
            $prob >= 0.95 => '95-100%',
            $prob >= 0.90 => '90-95%',
            $prob >= 0.85 => '85-90%',
            $prob >= 0.80 => '80-85%',
            $prob >= 0.75 => '75-80%',
            $prob >= 0.70 => '70-75%',
            $prob >= 0.65 => '65-70%',
            $prob >= 0.60 => '60-65%',
            $prob >= 0.55 => '55-60%',
            default => '50-55%',
        };
    }

    protected function displayOverallMetrics(array $metrics): void
    {
        $this->info('📊 Overall Performance Metrics');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Predictions', $metrics['total']],
                ['Correct Predictions', $metrics['correct']],
                ['Accuracy', $metrics['accuracy'].'%'],
                ['Brier Score', $metrics['brier_score'].' (lower is better)'],
                ['Log Loss', $metrics['log_loss'].' (lower is better)'],
            ]
        );
    }

    protected function displayCalibrationByBucket(array $buckets): void
    {
        $this->info('📈 Calibration by Confidence Bucket');
        $this->line('A well-calibrated model should have actual win rate match predicted probability.');
        $this->newLine();

        $rows = [];
        foreach ($buckets as $label => $data) {
            if ($data['total'] === 0) {
                continue;
            }

            $actualWinRate = round(($data['wins'] / $data['total']) * 100, 1);
            $expectedMidpoint = round((($data['range'][0] + $data['range'][1]) / 2) * 100, 1);
            $calibrationError = abs($actualWinRate - $expectedMidpoint);

            $rows[] = [
                $label,
                $data['total'],
                $data['wins'],
                $actualWinRate.'%',
                $expectedMidpoint.'%',
                round($calibrationError, 1).'%',
            ];
        }

        $this->table(
            ['Confidence Range', 'Total', 'Wins', 'Actual Win %', 'Expected %', 'Calibration Error'],
            $rows
        );
    }

    protected function displaySpreadAccuracy(array $metrics): void
    {
        $this->info('🎯 Spread Prediction Accuracy');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Mean Absolute Error', $metrics['mean_absolute_spread_error'].' points'],
                ['Median Absolute Error', $metrics['median_spread_error'].' points'],
            ]
        );
    }

    protected function displayDetailedResults($predictions): void
    {
        $this->newLine();
        $this->info('📋 Detailed Game Results');

        $rows = [];
        foreach ($predictions as $prediction) {
            $game = $prediction->game;
            $homeWon = $game->home_score > $game->away_score;
            $predictedHomeWin = $prediction->win_probability > 0.5;
            $correct = $predictedHomeWin === $homeWon;

            $actualSpread = $game->home_score - $game->away_score;
            $spreadError = abs($actualSpread - $prediction->predicted_spread);

            $rows[] = [
                $game->game_date->format('Y-m-d'),
                "{$game->homeTeam->abbreviation} vs {$game->awayTeam->abbreviation}",
                "{$game->home_score}-{$game->away_score}",
                round($prediction->win_probability * 100, 1).'%',
                round($prediction->predicted_spread, 1),
                round($actualSpread, 1),
                round($spreadError, 1),
                $correct ? '✓' : '✗',
            ];
        }

        $this->table(
            ['Date', 'Matchup', 'Score', 'Win Prob', 'Pred Spread', 'Actual Spread', 'Spread Error', 'Correct'],
            $rows
        );
    }

    protected function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return round(($values[$middle - 1] + $values[$middle]) / 2, 2);
        }

        return round($values[$middle], 2);
    }
}
