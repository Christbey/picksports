<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Prediction;
use App\Models\CFB\PredictionCalibration;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class UpdateAdaptiveCalibrationCommand extends Command
{
    protected $signature = 'cfb:update-adaptive-calibration
        {--season= : Season to learn from; defaults to cfb.season.default}
        {--from-week=0 : First week to include}
        {--through-week= : Last completed week to include; defaults to latest graded week}
        {--min-games= : Minimum games required per week bucket}
        {--learning-rate= : Signed-error learning rate}
        {--dry-run : Report learned calibration without writing}
        {--inactive : Store the profile without activating it}
        {--json : Output JSON}';

    protected $description = 'Update active CFB adaptive calibration from graded season-to-date predictions';

    private const BUCKETS = ['week_0_1', 'week_2_4', 'week_5_8', 'week_9_plus'];

    private const COMPONENTS = [
        'composite',
        'returning_production',
        'talent_recruiting',
        'qb_continuity',
        'transfer_portal',
        'coaching_continuity',
        'schedule_spot',
    ];

    public function handle(): int
    {
        $season = (int) ($this->option('season') ?: config('cfb.season.default', date('Y')));
        $fromWeek = max(0, (int) $this->option('from-week'));
        $minGames = max(1, (int) ($this->option('min-games') ?: config('cfb.predictions.adaptive_calibration.min_games_per_bucket', 8)));
        $learningRate = max(0.0, min(1.0, (float) ($this->option('learning-rate') ?: config('cfb.predictions.adaptive_calibration.learning_rate', 0.25))));

        $rows = $this->trainingRows($season, $fromWeek, $this->option('through-week'));
        $throughWeek = $rows->isEmpty() ? null : (int) $rows->max('week');
        $report = $this->buildCalibrationReport($rows, $season, $fromWeek, $throughWeek, $minGames, $learningRate);

        if (! $this->option('dry-run')) {
            $calibration = $this->storeCalibration($report, ! (bool) $this->option('inactive'));
            $report['calibration_id'] = (int) $calibration->id;
            $report['is_active'] = (bool) $calibration->is_active;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function trainingRows(int $season, int $fromWeek, mixed $throughWeekOption): Collection
    {
        $throughWeek = is_numeric($throughWeekOption) ? (int) $throughWeekOption : null;

        $predictions = Prediction::query()
            ->with('game')
            ->whereNotNull('graded_at')
            ->whereNotNull('predicted_spread')
            ->whereNotNull('predicted_total')
            ->whereHas('game', function ($query) use ($season, $fromWeek, $throughWeek): void {
                $query->where('season', $season)
                    ->whereNotNull('week')
                    ->where('week', '>=', $fromWeek)
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');

                if ($throughWeek !== null) {
                    $query->where('week', '<=', $throughWeek);
                }
            })
            ->get();

        if ($predictions->isEmpty()) {
            return collect();
        }

        $snapshots = PredictionFeatureSnapshot::query()
            ->where('sport', 'cfb')
            ->where('prediction_table', 'cfb_predictions')
            ->whereIn('prediction_id', $predictions->pluck('id')->all())
            ->orderByDesc('id')
            ->get()
            ->unique('prediction_id')
            ->keyBy('prediction_id');

        return $predictions
            ->map(function (Prediction $prediction) use ($snapshots): ?array {
                $game = $prediction->game;

                if (! $game) {
                    return null;
                }

                $actualMargin = (float) $game->home_score - (float) $game->away_score;
                $actualTotal = (float) $game->home_score + (float) $game->away_score;
                $snapshot = $snapshots->get($prediction->id);

                return [
                    'prediction_id' => (int) $prediction->id,
                    'game_id' => (int) $game->id,
                    'week' => (int) $game->week,
                    'bucket' => $this->weekBucket((int) $game->week),
                    'predicted_spread' => (float) $prediction->predicted_spread,
                    'predicted_total' => (float) $prediction->predicted_total,
                    'actual_margin' => $actualMargin,
                    'actual_total' => $actualTotal,
                    'signed_spread_error' => $actualMargin - (float) $prediction->predicted_spread,
                    'signed_total_error' => $actualTotal - (float) $prediction->predicted_total,
                    'winner_correct' => $prediction->winner_correct,
                    'components' => (array) data_get($snapshot?->model_metadata, 'cfb_preseason_layer.components', []),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function buildCalibrationReport(
        Collection $rows,
        int $season,
        int $fromWeek,
        ?int $throughWeek,
        int $minGames,
        float $learningRate
    ): array {
        $parameters = [
            'week_buckets' => [],
            'preseason_component_multipliers' => [],
        ];
        $metrics = [
            'week_buckets' => [],
        ];

        foreach (self::BUCKETS as $bucket) {
            $bucketRows = $rows->where('bucket', $bucket)->values();
            $bucketMetrics = $this->bucketMetrics($bucketRows);
            $metrics['week_buckets'][$bucket] = $bucketMetrics;

            if ($bucketRows->count() < $minGames) {
                $parameters['week_buckets'][$bucket] = [
                    'sample_size' => $bucketRows->count(),
                    'spread_adjustment' => 0.0,
                    'total_adjustment' => 0.0,
                    'confidence_penalty' => 0.0,
                    'status' => 'insufficient_sample',
                ];
                $parameters['preseason_component_multipliers'][$bucket] = [];

                continue;
            }

            $parameters['week_buckets'][$bucket] = [
                'sample_size' => $bucketRows->count(),
                'spread_adjustment' => round($this->clamp(
                    (float) $bucketRows->avg('signed_spread_error') * $learningRate,
                    (float) config('cfb.predictions.adaptive_calibration.max_spread_adjustment', 3.0)
                ), 3),
                'total_adjustment' => round($this->clamp(
                    (float) $bucketRows->avg('signed_total_error') * $learningRate,
                    (float) config('cfb.predictions.adaptive_calibration.max_total_adjustment', 3.0)
                ), 3),
                'confidence_penalty' => $this->confidencePenalty($bucketMetrics['winner_accuracy']),
                'status' => 'active',
            ];
            $parameters['preseason_component_multipliers'][$bucket] = $this->componentMultipliers($bucketRows);
        }

        return [
            'report_type' => 'cfb_adaptive_calibration_update',
            'season' => $season,
            'training_from_week' => $fromWeek,
            'training_through_week' => $throughWeek,
            'games_count' => $rows->count(),
            'min_games' => $minGames,
            'learning_rate' => $learningRate,
            'parameters' => $parameters,
            'metrics' => $metrics,
            'generated_at' => now()->toIso8601String(),
            'dry_run' => (bool) $this->option('dry-run'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{sample_size:int,winner_accuracy:float,mean_signed_spread_error:float,spread_mae:float,mean_signed_total_error:float,total_mae:float}
     */
    private function bucketMetrics(Collection $rows): array
    {
        $winnerRows = $rows->filter(fn (array $row): bool => $row['winner_correct'] !== null);

        return [
            'sample_size' => $rows->count(),
            'winner_accuracy' => $winnerRows->isEmpty()
                ? 0.0
                : round($winnerRows->where('winner_correct', true)->count() / $winnerRows->count() * 100, 1),
            'mean_signed_spread_error' => $rows->isEmpty() ? 0.0 : round((float) $rows->avg('signed_spread_error'), 3),
            'spread_mae' => $rows->isEmpty()
                ? 0.0
                : round((float) $rows->avg(fn (array $row): float => abs((float) $row['signed_spread_error'])), 2),
            'mean_signed_total_error' => $rows->isEmpty() ? 0.0 : round((float) $rows->avg('signed_total_error'), 3),
            'total_mae' => $rows->isEmpty()
                ? 0.0
                : round((float) $rows->avg(fn (array $row): float => abs((float) $row['signed_total_error'])), 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function componentMultipliers(Collection $rows): array
    {
        $multipliers = [];
        $minComponentGames = max(1, (int) config('cfb.predictions.adaptive_calibration.min_component_games', 6));
        $componentLearningRate = max(0.0, min(1.0, (float) config('cfb.predictions.adaptive_calibration.component_learning_rate', 0.15)));
        $maxDelta = max(0.0, (float) config('cfb.predictions.adaptive_calibration.max_component_multiplier_delta', 0.25));
        $minMultiplier = (float) config('cfb.predictions.adaptive_calibration.min_component_multiplier', 0.75);
        $maxMultiplier = (float) config('cfb.predictions.adaptive_calibration.max_component_multiplier', 1.25);

        foreach (self::COMPONENTS as $component) {
            $componentRows = $rows
                ->map(function (array $row) use ($component): ?array {
                    $adjustment = data_get($row, "components.{$component}.spread_adjustment");

                    if (! is_numeric($adjustment) || abs((float) $adjustment) < 0.05) {
                        return null;
                    }

                    return [
                        'adjustment' => (float) $adjustment,
                        'signed_spread_error' => (float) $row['signed_spread_error'],
                    ];
                })
                ->filter()
                ->values();

            if ($componentRows->count() < $minComponentGames) {
                continue;
            }

            $directionalResidual = (float) $componentRows->avg(
                fn (array $row): float => $row['signed_spread_error'] * ($row['adjustment'] >= 0 ? 1.0 : -1.0)
            );
            $averageMagnitude = max(0.25, (float) $componentRows->avg(fn (array $row): float => abs($row['adjustment'])));
            $delta = $this->clamp(($directionalResidual / $averageMagnitude) * $componentLearningRate, $maxDelta);
            $multipliers[$component] = round(max($minMultiplier, min($maxMultiplier, 1.0 + $delta)), 3);
        }

        return $multipliers;
    }

    private function confidencePenalty(float $winnerAccuracy): float
    {
        $target = (float) config('cfb.predictions.adaptive_calibration.target_winner_accuracy', 58.0);

        if ($winnerAccuracy >= $target) {
            return 0.0;
        }

        return round(min(
            (float) config('cfb.predictions.adaptive_calibration.max_confidence_penalty', 4.0),
            ($target - $winnerAccuracy) * (float) config('cfb.predictions.adaptive_calibration.confidence_penalty_points_per_accuracy_gap', 0.20)
        ), 2);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function storeCalibration(array $report, bool $activate): PredictionCalibration
    {
        if ($activate) {
            PredictionCalibration::query()
                ->where('season', (int) $report['season'])
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        return PredictionCalibration::query()->create([
            'season' => $report['season'],
            'training_from_week' => $report['training_from_week'],
            'training_through_week' => $report['training_through_week'],
            'games_count' => $report['games_count'],
            'min_games' => $report['min_games'],
            'learning_rate' => $report['learning_rate'],
            'parameters' => $report['parameters'],
            'metrics' => $report['metrics'],
            'is_active' => $activate,
            'generated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $this->info('CFB Adaptive Calibration Update');
        $this->line(sprintf(
            'Season %d | weeks %d-%s | games %d | %s',
            $report['season'],
            $report['training_from_week'],
            $report['training_through_week'] ?? 'n/a',
            $report['games_count'],
            $report['dry_run'] ? 'dry run' : ((bool) ($report['is_active'] ?? false) ? 'active' : 'stored inactive')
        ));
        $this->newLine();

        $rows = collect($report['parameters']['week_buckets'])
            ->map(fn (array $bucket, string $label): array => [
                $label,
                (string) $bucket['sample_size'],
                $bucket['status'],
                number_format((float) $bucket['spread_adjustment'], 2),
                number_format((float) $bucket['total_adjustment'], 2),
                number_format((float) $bucket['confidence_penalty'], 2),
            ])
            ->values()
            ->all();

        $this->table(['Bucket', 'Games', 'Status', 'Spread Adj', 'Total Adj', 'Conf Penalty'], $rows);

        if (isset($report['calibration_id'])) {
            $this->info('Calibration ID: '.$report['calibration_id']);
        }
    }

    private function weekBucket(int $week): string
    {
        return match (true) {
            $week <= 1 => 'week_0_1',
            $week <= 4 => 'week_2_4',
            $week <= 8 => 'week_5_8',
            default => 'week_9_plus',
        };
    }

    private function clamp(float $value, float $maxAbsoluteValue): float
    {
        $maxAbsoluteValue = abs($maxAbsoluteValue);

        return max(-$maxAbsoluteValue, min($maxAbsoluteValue, $value));
    }
}
