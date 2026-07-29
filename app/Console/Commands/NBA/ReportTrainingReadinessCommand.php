<?php

namespace App\Console\Commands\NBA;

use App\Models\NBA\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\ML\TrustedSnapshotDataset;
use Illuminate\Console\Command;

class ReportTrainingReadinessCommand extends Command
{
    protected $signature = 'nba:report-training-readiness
        {--season= : Filter rows by season}
        {--minimum-rows=120 : Minimum strict rows required for a pilot}';

    protected $description = 'Report target stability and point-in-time readiness for NBA model training';

    public function handle(TrustedSnapshotDataset $dataset): int
    {
        $season = $this->option('season') !== null ? (int) $this->option('season') : null;
        $minimumRows = max(1, (int) $this->option('minimum-rows'));
        $allRows = $dataset->rows('nba', $season, false);
        $strictRows = $dataset->rows('nba', $season, true);

        if ($allRows->isEmpty()) {
            $this->warn('No completed NBA snapshot targets found for the selected scope.');

            return self::SUCCESS;
        }

        $snapshotQuery = PredictionFeatureSnapshot::query()
            ->where('sport', 'nba')
            ->when($season, function ($query) use ($season): void {
                $query->whereIn('game_id', Game::query()->where('season', $season)->select('id'));
            });
        $missingRunCount = (clone $snapshotQuery)->whereNull('model_run_id')->count();
        $unstableTargets = $strictRows->groupBy('game_id')
            ->filter(fn ($rows): bool => $rows->pluck('target_hash')->unique()->count() !== 1)
            ->count();
        $missingTargets = $strictRows->filter(fn (array $row): bool => $row['target_home_margin'] === null
            || $row['target_total_points'] === null
            || $row['target_hash'] === null)->count();
        $ready = $strictRows->count() >= $minimumRows
            && $missingTargets === 0
            && $unstableTargets === 0
            && $missingRunCount === 0;

        $this->info('NBA Training Readiness');
        $this->line('Scope: '.($season ? "season {$season}" : 'all seasons'));
        $this->table(
            ['Gate', 'Value', 'Status'],
            [
                ['Completed target rows', (string) $allRows->count(), 'info'],
                ['Verified pregame rows', (string) $strictRows->count(), $strictRows->count() >= $minimumRows ? 'pass' : 'blocked'],
                ['Rows missing stable targets', (string) $missingTargets, $missingTargets === 0 ? 'pass' : 'blocked'],
                ['Games with conflicting target hashes', (string) $unstableTargets, $unstableTargets === 0 ? 'pass' : 'blocked'],
                ['Snapshots missing model run lineage', (string) $missingRunCount, $missingRunCount === 0 ? 'pass' : 'blocked'],
                ['Pilot training gate', $ready ? 'READY' : 'BLOCKED', $ready ? 'pass' : 'blocked'],
            ],
        );

        $this->newLine();
        $this->info('Point-In-Time Status');
        $this->table(
            ['Status', 'Rows'],
            $allRows
                ->groupBy('availability_status')
                ->map(fn ($rows, string $status): array => [$status, (string) $rows->count()])
                ->values()
                ->all(),
        );

        $this->newLine();
        $this->info('By Model Version');
        $this->table(
            ['Model', 'Feature', 'Blend', 'Rows', 'Spread MAE', 'Total MAE'],
            $strictRows
                ->groupBy(fn (array $row): string => implode('|', [
                    $row['model_version'],
                    $row['feature_version'],
                    $row['blend_version'],
                ]))
                ->map(function ($rows, string $key): array {
                    [$model, $feature, $blend] = explode('|', $key);

                    return [
                        $model,
                        $feature,
                        $blend,
                        (string) $rows->count(),
                        number_format((float) $rows->avg('target_model_spread_error'), 2),
                        number_format((float) $rows->avg('target_model_total_error'), 2),
                    ];
                })
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }
}
