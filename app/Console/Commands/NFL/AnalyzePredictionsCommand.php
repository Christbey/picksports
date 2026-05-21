<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Prediction;
use Illuminate\Console\Command;

class AnalyzePredictionsCommand extends Command
{
    protected $signature = 'nfl:analyze-predictions
                            {--season= : Season to analyze (defaults to config nfl.season.default)}
                            {--from-season= : Analyze starting with this NFL season}
                            {--to-season= : Analyze through this NFL season}
                            {--detailed : Show detailed game-by-game results}';

    protected $description = 'Analyze prediction accuracy and calibration metrics';

    public function handle(): int
    {
        try {
            $scope = $this->resolveScope();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $predictions = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function ($query) use ($scope) {
                $query->where('status', 'STATUS_FINAL');

                if ($scope['season'] !== null) {
                    $query->where('season', $scope['season']);
                }

                if ($scope['from_season'] !== null) {
                    $query->where('season', '>=', $scope['from_season']);
                }

                if ($scope['to_season'] !== null) {
                    $query->where('season', '<=', $scope['to_season']);
                }
            })
            ->get();

        if ($predictions->isEmpty()) {
            $this->warn('No predictions found for completed games in '.$scope['label']);

            return Command::SUCCESS;
        }

        $this->info("Analyzing {$predictions->count()} predictions from {$scope['label']}...");
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

        $this->displayYearBreakdown($predictions);
        $this->newLine();

        $this->displayAnalysisLayerBreakdown($predictions);
        $this->newLine();

        // Show detailed results if requested
        if ($this->option('detailed')) {
            $this->displayDetailedResults($predictions);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{season:?int,from_season:?int,to_season:?int,label:string}
     */
    protected function resolveScope(): array
    {
        $season = $this->option('season');
        $fromSeason = $this->option('from-season');
        $toSeason = $this->option('to-season');

        if ($season !== null && ($fromSeason !== null || $toSeason !== null)) {
            throw new \InvalidArgumentException('Use either --season or --from-season/--to-season, not both.');
        }

        if ($fromSeason !== null || $toSeason !== null) {
            $start = (int) ($fromSeason ?? $toSeason);
            $end = (int) ($toSeason ?? $fromSeason);

            if ($start > $end) {
                throw new \InvalidArgumentException('--from-season must be less than or equal to --to-season.');
            }

            return [
                'season' => null,
                'from_season' => $start,
                'to_season' => $end,
                'label' => "seasons {$start}-{$end}",
            ];
        }

        $resolvedSeason = (int) ($season ?? config('nfl.season.default'));

        return [
            'season' => $resolvedSeason,
            'from_season' => null,
            'to_season' => null,
            'label' => "season {$resolvedSeason}",
        ];
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

            // Bucket by predicted-side confidence.
            $bucket = $this->getBucket($predictedProb);
            $buckets[$bucket]['total']++;
            if ($predictedHomeWin === $homeWon) {
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

    protected function displayYearBreakdown($predictions): void
    {
        $this->info('Year Breakdown');

        $rows = $predictions
            ->groupBy(fn (Prediction $prediction) => (int) $prediction->game->season)
            ->sortKeys()
            ->map(function ($seasonPredictions, int $season): array {
                $metrics = $this->calculateMetrics($seasonPredictions);

                return [
                    $season,
                    $metrics['total'],
                    $metrics['accuracy'].'%',
                    $metrics['brier_score'],
                    $metrics['log_loss'],
                    $metrics['mean_absolute_spread_error'],
                    $metrics['median_spread_error'],
                ];
            })
            ->values()
            ->all();

        $this->table(
            ['Season', 'Games', 'Winner Acc', 'Brier', 'Log Loss', 'Spread MAE', 'Median Err'],
            $rows
        );
    }

    protected function displayAnalysisLayerBreakdown($predictions): void
    {
        $withAnalysis = $predictions
            ->filter(fn (Prediction $prediction) => is_array(data_get($prediction->model_metadata, 'analysis_layer')))
            ->values();

        if ($withAnalysis->isEmpty()) {
            $this->warn('No analysis layer metadata found for this scope.');

            return;
        }

        $this->info('Analysis Layer Breakdown');

        $classificationRows = $withAnalysis
            ->groupBy(fn (Prediction $prediction) => (string) data_get($prediction->model_metadata, 'analysis_layer.bet_classification', 'unknown'))
            ->map(function ($rows, string $classification): array {
                return $this->analysisSummaryRow($classification, $rows);
            })
            ->sortByDesc(fn (array $row) => (int) $row['Games'])
            ->values()
            ->all();

        $this->table(
            ['Classification', 'Games', 'Winner Acc', 'Avg Trust', 'Avg Spread Edge', 'Avg Total Edge'],
            array_map(fn (array $row): array => array_values($row), $classificationRows)
        );

        $this->newLine();
        $this->info('Model Signal Classification');
        $signalRows = $withAnalysis
            ->groupBy(fn (Prediction $prediction) => $this->modelSignalClassificationForPrediction($prediction))
            ->map(function ($rows, string $classification): array {
                return $this->analysisSummaryRow($classification, $rows);
            })
            ->sortByDesc(fn (array $row) => (int) $row['Games'])
            ->values()
            ->all();

        $this->table(
            ['Signal', 'Games', 'Winner Acc', 'Avg Trust', 'Avg Spread Edge', 'Avg Total Edge'],
            array_map(fn (array $row): array => array_values($row), $signalRows)
        );

        $this->newLine();
        $this->info('Trust Score Bands');
        $trustRows = collect([
            '0-55' => [0, 55],
            '55-65' => [55, 65],
            '65-75' => [65, 75],
            '75-85' => [75, 85],
            '85-100' => [85, 100.01],
        ])
            ->map(function (array $range, string $label) use ($withAnalysis): array {
                $rows = $withAnalysis->filter(function (Prediction $prediction) use ($range): bool {
                    $trust = (float) data_get($prediction->model_metadata, 'analysis_layer.trust_score', 0);

                    return $trust >= $range[0] && $trust < $range[1];
                });

                return $this->analysisSummaryRow($label, $rows);
            })
            ->filter(fn (array $row): bool => (int) $row['Games'] > 0)
            ->values()
            ->all();

        $this->table(
            ['Trust Band', 'Games', 'Winner Acc', 'Avg Trust', 'Avg Spread Edge', 'Avg Total Edge'],
            array_map(fn (array $row): array => array_values($row), $trustRows)
        );

        $this->newLine();
        $this->info('Risk Flags');
        $riskRows = $this->analysisTokenRows($withAnalysis, 'risk_flags');
        $this->table(['Risk Flag', 'Games', 'Winner Acc'], $riskRows);

        $this->newLine();
        $this->info('Reason Codes');
        $reasonRows = $this->analysisTokenRows($withAnalysis, 'reason_codes');
        $this->table(['Reason Code', 'Games', 'Winner Acc'], $reasonRows);
    }

    protected function analysisSummaryRow(string $label, $predictions): array
    {
        $count = $predictions->count();
        $correct = $predictions->filter(fn (Prediction $prediction): bool => $this->predictionWinnerCorrect($prediction))->count();

        return [
            'Label' => $label,
            'Games' => $count,
            'Winner Acc' => $count > 0 ? round(($correct / $count) * 100, 1).'%' : 'n/a',
            'Avg Trust' => $count > 0 ? round((float) $predictions->avg(fn (Prediction $prediction) => (float) data_get($prediction->model_metadata, 'analysis_layer.trust_score', 0)), 1) : 'n/a',
            'Avg Spread Edge' => $count > 0 ? round((float) $predictions->avg(fn (Prediction $prediction) => abs((float) data_get($prediction->model_metadata, 'analysis_layer.calculated_edge.spread_points', 0))), 2) : 'n/a',
            'Avg Total Edge' => $count > 0 ? round((float) $predictions->avg(fn (Prediction $prediction) => abs((float) data_get($prediction->model_metadata, 'analysis_layer.calculated_edge.total_points', 0))), 2) : 'n/a',
        ];
    }

    protected function analysisTokenRows($predictions, string $key): array
    {
        $rows = [];

        foreach ($predictions as $prediction) {
            foreach ((array) data_get($prediction->model_metadata, "analysis_layer.{$key}", []) as $token) {
                $token = (string) $token;
                if ($token === '') {
                    continue;
                }

                $rows[$token] ??= ['games' => 0, 'correct' => 0];
                $rows[$token]['games']++;
                if ($this->predictionWinnerCorrect($prediction)) {
                    $rows[$token]['correct']++;
                }
            }
        }

        return collect($rows)
            ->map(fn (array $row, string $token): array => [
                $token,
                $row['games'],
                $row['games'] > 0 ? round(($row['correct'] / $row['games']) * 100, 1).'%' : 'n/a',
            ])
            ->sortByDesc(fn (array $row): int => (int) $row[1])
            ->take(12)
            ->values()
            ->all();
    }

    protected function predictionWinnerCorrect(Prediction $prediction): bool
    {
        $game = $prediction->game;
        if (! $game || $game->home_score === null || $game->away_score === null) {
            return false;
        }

        $homeWon = (float) $game->home_score > (float) $game->away_score;
        $predictedHomeWin = (float) $prediction->win_probability > 0.5;

        return $predictedHomeWin === $homeWon;
    }

    protected function modelSignalClassificationForPrediction(Prediction $prediction): string
    {
        $stored = data_get($prediction->model_metadata, 'analysis_layer.model_signal_classification');
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $trustScore = (float) data_get($prediction->model_metadata, 'analysis_layer.trust_score', 0);

        return match (true) {
            $trustScore >= (float) config('nfl.predictions.analysis_layer.strong_model_signal_threshold', 65.0) => 'strong_model_side',
            $trustScore >= (float) config('nfl.predictions.analysis_layer.lean_model_signal_threshold', 55.0) => 'lean_model_side',
            default => 'pass_model_side',
        };
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
