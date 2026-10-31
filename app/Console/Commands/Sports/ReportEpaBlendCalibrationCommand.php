<?php

namespace App\Console\Commands\Sports;

use App\Services\Predictions\EpaBlendCalibrationSource;
use Illuminate\Console\Command;

class ReportEpaBlendCalibrationCommand extends Command
{
    protected $signature = 'sports:report-epa-blend-calibration
        {sport : One of nfl,nba,cbb,wcbb}
        {--season= : Filter by season}
        {--forecast-source= : canonical or legacy (defaults to the active sport source)}
        {--model-version= : NFL canonical recommendation version (defaults to current)}';

    protected $description = 'Report prediction calibration split by true-EPA blend applied vs not applied';

    public function handle(EpaBlendCalibrationSource $source): int
    {
        $sport = strtolower((string) $this->argument('sport'));
        try {
            $forecastSource = $source->name($sport, $this->option('forecast-source'));
        } catch (\InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $season = $this->option('season');
        $this->line('Forecast source: '.$forecastSource);
        if ($forecastSource === 'canonical') {
            $this->line($sport === 'nfl' ? 'Evidence: frozen NFL recommendation forecasts.' : 'Evidence: published canonical forecasts.');
        }
        $empty = ['count' => 0, 'spread_error' => 0.0, 'total_error' => 0.0, 'total_count' => 0, 'winner_correct' => 0];
        $groups = ['all' => $empty, 'true_epa_applied' => $empty, 'true_epa_not_applied' => $empty];
        if ($forecastSource === 'canonical') {
            $groups['true_epa_unknown'] = $empty;
        }
        foreach ($source->rows($sport, $forecastSource, $season !== null && $season !== '' ? (int) $season : null, $this->option('model-version')) as $row) {
            $this->accumulate($groups['all'], $row);
            $group = $row->epa_applied === null ? 'true_epa_unknown' : ($row->epa_applied ? 'true_epa_applied' : 'true_epa_not_applied');
            $this->accumulate($groups[$group], $row);
        }
        if ($groups['all']['count'] === 0) {
            $this->warn('No graded predictions found for the selected scope.');

            return self::SUCCESS;
        }

        $this->line(strtoupper($sport).' EPA Blend Calibration Report');
        $this->line('----------------------------------------');
        $this->line('Scope: '.($season ? "season {$season}" : 'all seasons'));
        $this->line('Total graded predictions: '.$groups['all']['count']);
        $this->newLine();

        $tableRows = [];
        foreach ($groups as $label => $groupRows) {
            $stats = $this->summarizeGroup($groupRows);
            $tableRows[] = [
                $label,
                $stats['count'],
                $stats['spread_mae'],
                $stats['total_mae'],
                $stats['winner_accuracy'],
            ];
        }

        $this->table(
            ['Group', 'Count', 'Spread MAE', 'Total MAE', 'Winner Acc %'],
            $tableRows
        );

        $applied = $this->summarizeGroup($groups['true_epa_applied']);
        $notApplied = $this->summarizeGroup($groups['true_epa_not_applied']);
        $this->newLine();
        $this->line('Observational deltas (applied - not_applied):');
        $this->line('Spread MAE delta: '.$this->formatDelta($applied['spread_mae_raw'], $notApplied['spread_mae_raw']));
        $this->line('Total MAE delta: '.$this->formatDelta($applied['total_mae_raw'], $notApplied['total_mae_raw']));
        $this->line('Winner accuracy delta: '.$this->formatDelta($applied['winner_accuracy_raw'], $notApplied['winner_accuracy_raw'], true));
        $this->line('Note: This is observational and not a causal A/B split.');

        return self::SUCCESS;
    }

    private function accumulate(array &$stats, object $row): void
    {
        $stats['count']++;
        $stats['spread_error'] += abs((float) $row->actual_spread - (float) $row->predicted_spread);
        if ($row->predicted_total !== null && $row->actual_total !== null) {
            $stats['total_error'] += abs((float) $row->actual_total - (float) $row->predicted_total);
            $stats['total_count']++;
        }
        $stats['winner_correct'] += (int) ((bool) $row->winner_correct);
    }

    private function summarizeGroup(array $stats): array
    {
        $count = $stats['count'];
        $spreadMae = $count === 0 ? null : $stats['spread_error'] / $count;
        $totalMae = $stats['total_count'] === 0 ? null : $stats['total_error'] / $stats['total_count'];
        $winnerAcc = $count === 0 ? null : $stats['winner_correct'] / $count * 100;

        return ['count' => $count, 'spread_mae' => $spreadMae === null ? 'n/a' : number_format($spreadMae, 3),
            'total_mae' => $totalMae === null ? 'n/a' : number_format($totalMae, 3),
            'winner_accuracy' => $winnerAcc === null ? 'n/a' : number_format($winnerAcc, 2),
            'spread_mae_raw' => $spreadMae, 'total_mae_raw' => $totalMae, 'winner_accuracy_raw' => $winnerAcc];
    }

    private function formatDelta(?float $a, ?float $b, bool $asPercent = false): string
    {
        if ($a === null || $b === null) {
            return 'n/a';
        }

        $delta = $a - $b;
        $formatted = number_format($delta, $asPercent ? 2 : 3);

        return ($delta > 0 ? '+' : '').$formatted;
    }
}
