<?php

namespace App\Console\Commands\MLB;

use App\Services\MLB\MlbStartingPitcherForecastService;
use Illuminate\Console\Command;

class GradeStartingPitcherForecastsCommand extends Command
{
    protected $signature = 'mlb:grade-starting-pitcher-forecasts
        {--season= : MLB season to reconcile and report}
        {--include-post-start : Include forecasts recorded after scheduled first pitch}
        {--json : Emit the grading report as JSON}';

    protected $description = 'Reconcile rotation starter forecasts against confirmed ESPN box-score starters';

    public function handle(MlbStartingPitcherForecastService $forecasts): int
    {
        $season = (int) ($this->option('season') ?: now()->year);
        $reconciled = $forecasts->reconcileSeason($season);
        $report = $forecasts->report($season, (bool) $this->option('include-post-start'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'reconciliation' => $reconciled,
                ...$report,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Reconciled %d games and %d starter forecast rows.',
            $reconciled['games'],
            $reconciled['graded_forecasts'],
        ));

        $summary = $report['summary'];
        if ($summary['forecasts'] === 0) {
            $this->warn('No eligible graded starter forecasts are available yet.');

            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], [
            ['Latest eligible forecasts', $summary['forecasts']],
            ['Correct', $summary['correct']],
            ['Accuracy', $this->percentage($summary['accuracy'])],
            ['Average confidence', $this->percentage($summary['average_confidence'])],
            ['Average Brier', $this->number($summary['average_brier'])],
            ['Average log loss', $this->number($summary['average_log_loss'])],
            ['Pitcher rating MAE', $this->number($summary['rating_mae'])],
            ['All immutable forecast snapshots', data_get($report, 'all_snapshots_summary.forecasts', 0)],
        ]);

        $this->newLine();
        $this->info('Accuracy by confidence');
        $this->table(
            ['Bucket', 'Forecasts', 'Correct', 'Accuracy', 'Brier'],
            collect($report['by_confidence'])->map(fn (array $row): array => [
                $row['bucket'],
                $row['forecasts'],
                $row['correct'],
                $this->percentage($row['accuracy']),
                $this->number($row['average_brier']),
            ])->all(),
        );

        $this->newLine();
        $this->info('Accuracy by source');
        $this->table(
            ['Source', 'Forecasts', 'Correct', 'Accuracy', 'Confidence', 'Brier'],
            collect($report['by_source'])->map(fn (array $row): array => [
                $row['prediction_source'],
                $row['forecasts'],
                $row['correct'],
                $this->percentage($row['accuracy']),
                $this->percentage($row['average_confidence']),
                $this->number($row['average_brier']),
            ])->all(),
        );

        $this->newLine();
        $this->info('Accuracy by forecast horizon');
        $this->table(
            ['Horizon', 'Forecasts', 'Correct', 'Accuracy', 'Confidence', 'Brier'],
            collect($report['by_horizon'])->map(fn (array $row): array => [
                $row['bucket'],
                $row['forecasts'],
                $row['correct'],
                $this->percentage($row['accuracy']),
                $this->percentage($row['average_confidence']),
                $this->number($row['average_brier']),
            ])->all(),
        );

        $this->newLine();
        $this->info('Accuracy by projection status');
        $this->table(
            ['Status', 'Forecasts', 'Correct', 'Accuracy', 'Confidence', 'Brier'],
            collect($report['by_projection_status'])->map(fn (array $row): array => [
                $row['status'],
                $row['forecasts'],
                $row['correct'],
                $this->percentage($row['accuracy']),
                $this->percentage($row['average_confidence']),
                $this->number($row['average_brier']),
            ])->all(),
        );

        $this->newLine();
        $this->info('Most frequently forecast pitchers');
        $this->table(
            ['Pitcher', 'Forecasts', 'Correct', 'Accuracy', 'Confidence', 'Brier'],
            collect($report['by_pitcher'])->take(25)->map(fn (array $row): array => [
                $row['pitcher_name'] ?: $row['pitcher_espn_id'],
                $row['forecasts'],
                $row['correct'],
                $this->percentage($row['accuracy']),
                $this->percentage($row['average_confidence']),
                $this->number($row['average_brier']),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function percentage(?float $value): string
    {
        return $value === null ? 'n/a' : round($value * 100, 2).'%';
    }

    private function number(?float $value): string
    {
        return $value === null ? 'n/a' : (string) round($value, 4);
    }
}
