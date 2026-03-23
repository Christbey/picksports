<?php

namespace App\Console\Commands\NBA;

use App\Models\PredictionFeatureSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ReportTrainingReadinessCommand extends Command
{
    protected $signature = 'nba:report-training-readiness
        {--season= : Filter rows by season}';

    protected $description = 'Report baseline NBA model readiness from feature snapshot and evaluation tables';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn('No NBA training rows found for the selected scope.');

            return self::SUCCESS;
        }

        $this->info('NBA Training Readiness');
        $this->line('Scope: '.($this->option('season') ? 'season '.$this->option('season') : 'all seasons'));
        $this->line('Rows: '.$rows->count());
        $this->newLine();

        $winnerAccuracy = $rows->avg(fn (array $row): float => $row['winner_correct'] ? 1.0 : 0.0) * 100;
        $modelBeatMarketRate = $rows
            ->filter(fn (array $row): bool => $row['model_beats_market_spread'] !== null)
            ->avg(fn (array $row): float => $row['model_beats_market_spread'] ? 1.0 : 0.0);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Avg spread error', number_format((float) $rows->avg('spread_error'), 2)],
                ['Avg total error', number_format((float) $rows->avg('total_error'), 2)],
                ['Avg Brier score', number_format((float) $rows->avg('brier_score'), 4)],
                ['Winner accuracy', number_format((float) $winnerAccuracy, 1).'%'],
                ['Model beats market spread', $modelBeatMarketRate !== null ? number_format($modelBeatMarketRate * 100, 1).'%' : 'N/A'],
            ]
        );

        $this->newLine();
        $this->info('By Model Version');
        $this->table(
            ['Model', 'Feature', 'Blend', 'Rows', 'Spread MAE', 'Total MAE', 'Brier'],
            $rows
                ->groupBy(fn (array $row) => implode('|', [
                    $row['model_version'],
                    $row['feature_version'],
                    $row['blend_version'],
                ]))
                ->map(function (Collection $group, string $key): array {
                    [$model, $feature, $blend] = explode('|', $key);

                    return [
                        $model,
                        $feature,
                        $blend,
                        (string) $group->count(),
                        number_format((float) $group->avg('spread_error'), 2),
                        number_format((float) $group->avg('total_error'), 2),
                        number_format((float) $group->avg('brier_score'), 4),
                    ];
                })
                ->values()
                ->all()
        );

        $this->newLine();
        $this->info('By Confidence Bucket');
        $this->table(
            ['Bucket', 'Rows', 'Winner Accuracy', 'Spread MAE'],
            $this->confidenceBuckets($rows)
        );

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadRows(): Collection
    {
        $query = PredictionFeatureSnapshot::query()
            ->where('prediction_feature_snapshots.sport', 'nba')
            ->where('prediction_feature_snapshots.prediction_table', 'nba_predictions')
            ->join('prediction_evaluations as pe', function ($join): void {
                $join->on('prediction_feature_snapshots.prediction_table', '=', 'pe.prediction_table')
                    ->on('prediction_feature_snapshots.prediction_id', '=', 'pe.prediction_id')
                    ->on('prediction_feature_snapshots.model_version', '=', 'pe.model_version')
                    ->on('prediction_feature_snapshots.feature_version', '=', 'pe.feature_version')
                    ->on('prediction_feature_snapshots.blend_version', '=', 'pe.blend_version');
            })
            ->join('nba_games as g', 'prediction_feature_snapshots.game_id', '=', 'g.id')
            ->select(
                'prediction_feature_snapshots.model_version',
                'prediction_feature_snapshots.feature_version',
                'prediction_feature_snapshots.blend_version',
                'prediction_feature_snapshots.outputs',
                'pe.errors',
                'pe.market_comparison',
                'g.season'
            );

        if ($this->option('season')) {
            $query->where('g.season', (int) $this->option('season'));
        }

        return $query->get()->map(function (PredictionFeatureSnapshot $snapshot): array {
            $outputs = $this->arrayValue($snapshot->outputs);
            $errors = $this->arrayValue($snapshot->errors);
            $marketComparison = $this->arrayValue($snapshot->market_comparison);

            return [
                'model_version' => $snapshot->model_version,
                'feature_version' => $snapshot->feature_version,
                'blend_version' => $snapshot->blend_version,
                'confidence_score' => (float) ($outputs['confidence_score'] ?? 0),
                'spread_error' => (float) ($errors['spread_error'] ?? 0),
                'total_error' => (float) ($errors['total_error'] ?? 0),
                'brier_score' => (float) ($errors['brier_score'] ?? 0),
                'winner_correct' => (bool) ($errors['winner_correct'] ?? false),
                'model_beats_market_spread' => $marketComparison['model_beats_market_spread'] ?? null,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function confidenceBuckets(Collection $rows): array
    {
        $buckets = [
            '50-59.9' => fn (float $confidence): bool => $confidence >= 50 && $confidence < 60,
            '60-69.9' => fn (float $confidence): bool => $confidence >= 60 && $confidence < 70,
            '70-79.9' => fn (float $confidence): bool => $confidence >= 70 && $confidence < 80,
            '80+' => fn (float $confidence): bool => $confidence >= 80,
        ];

        $table = [];
        foreach ($buckets as $label => $filter) {
            $group = $rows->filter(fn (array $row): bool => $filter((float) $row['confidence_score']))->values();
            if ($group->isEmpty()) {
                continue;
            }

            $table[] = [
                $label,
                (string) $group->count(),
                number_format($group->avg(fn (array $row): float => $row['winner_correct'] ? 1.0 : 0.0) * 100, 1).'%',
                number_format((float) $group->avg('spread_error'), 2),
            ];
        }

        return $table;
    }
}
