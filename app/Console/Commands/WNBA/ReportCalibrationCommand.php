<?php

namespace App\Console\Commands\WNBA;

use App\Models\WNBA\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ReportCalibrationCommand extends Command
{
    protected $signature = 'wnba:report-calibration
        {--season= : Filter by season}
        {--limit=500 : Limit number of most recent graded predictions to inspect}
        {--json : Output the report as JSON}';

    protected $description = 'Report WNBA prediction accuracy, bias, and confidence calibration metrics';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn('No graded WNBA predictions found for the selected scope.');

            return self::SUCCESS;
        }

        $report = $this->buildReport($rows);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('WNBA Prediction Calibration Report');
        $this->line('Scope: '.($this->option('season') ? 'season '.$this->option('season') : 'all seasons'));
        $this->line('Rows: '.(string) $report['summary']['count']);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Winner accuracy', number_format((float) $report['summary']['winner_accuracy'], 1).'%'],
                ['Spread MAE', number_format((float) $report['summary']['spread_mae'], 2)],
                ['Total MAE', number_format((float) $report['summary']['total_mae'], 2)],
                ['Spread bias', $this->signed($report['summary']['spread_bias'], 2)],
                ['Total bias', $this->signed($report['summary']['total_bias'], 2)],
                ['Avg confidence', number_format((float) $report['summary']['avg_confidence'], 2)],
                ['Confidence range', number_format((float) $report['summary']['min_confidence'], 2).' - '.number_format((float) $report['summary']['max_confidence'], 2)],
            ]
        );

        $this->newLine();
        $this->info('Confidence Buckets');
        $this->table(
            ['Bucket', 'Games', 'Winner %', 'Spread MAE', 'Total MAE'],
            $report['confidence_buckets']
        );

        return self::SUCCESS;
    }

    private function loadRows(): Collection
    {
        $query = Prediction::query()
            ->with('game')
            ->whereNotNull('graded_at')
            ->whereNotNull('predicted_spread')
            ->whereNotNull('predicted_total')
            ->latest('graded_at');

        if ($this->option('season')) {
            $query->whereHas('game', fn ($gameQuery) => $gameQuery->where('season', (int) $this->option('season')));
        }

        return $query
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->filter(fn (Prediction $prediction): bool => $prediction->game !== null
                && is_numeric($prediction->game->home_score)
                && is_numeric($prediction->game->away_score)
                && is_numeric($prediction->predicted_spread)
                && is_numeric($prediction->predicted_total))
            ->values();
    }

    private function buildReport(Collection $rows): array
    {
        $mapped = $rows->map(function (Prediction $prediction): array {
            $actualSpread = (float) $prediction->game->home_score - (float) $prediction->game->away_score;
            $actualTotal = (float) $prediction->game->home_score + (float) $prediction->game->away_score;
            $predictedSpread = (float) $prediction->predicted_spread;
            $predictedTotal = (float) $prediction->predicted_total;

            return [
                'winner_correct' => (bool) $prediction->winner_correct,
                'spread_error' => abs($actualSpread - $predictedSpread),
                'total_error' => abs($actualTotal - $predictedTotal),
                'spread_bias' => $predictedSpread - $actualSpread,
                'total_bias' => $predictedTotal - $actualTotal,
                'confidence_score' => (float) $prediction->confidence_score,
                'confidence_bucket' => $this->confidenceBucket((float) $prediction->confidence_score),
            ];
        });

        return [
            'summary' => [
                'count' => $mapped->count(),
                'winner_accuracy' => $this->percent($mapped->where('winner_correct', true)->count(), $mapped->count()),
                'spread_mae' => round((float) $mapped->avg('spread_error'), 2),
                'total_mae' => round((float) $mapped->avg('total_error'), 2),
                'spread_bias' => round((float) $mapped->avg('spread_bias'), 2),
                'total_bias' => round((float) $mapped->avg('total_bias'), 2),
                'avg_confidence' => round((float) $mapped->avg('confidence_score'), 2),
                'min_confidence' => round((float) $mapped->min('confidence_score'), 2),
                'max_confidence' => round((float) $mapped->max('confidence_score'), 2),
            ],
            'confidence_buckets' => $mapped
                ->groupBy('confidence_bucket')
                ->sortKeys()
                ->map(fn (Collection $bucket, string $label): array => [
                    'bucket' => $label,
                    'games' => $bucket->count(),
                    'winner_accuracy' => number_format($this->percent($bucket->where('winner_correct', true)->count(), $bucket->count()), 1).'%',
                    'spread_mae' => number_format((float) $bucket->avg('spread_error'), 2),
                    'total_mae' => number_format((float) $bucket->avg('total_error'), 2),
                ])
                ->values()
                ->all(),
        ];
    }

    private function confidenceBucket(float $confidence): string
    {
        if ($confidence < 55) {
            return '50-54.9';
        }

        if ($confidence < 60) {
            return '55-59.9';
        }

        if ($confidence < 65) {
            return '60-64.9';
        }

        if ($confidence < 70) {
            return '65-69.9';
        }

        if ($confidence < 75) {
            return '70-74.9';
        }

        if ($confidence < 80) {
            return '75-79.9';
        }

        return '80+';
    }

    private function percent(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 1);
    }

    private function signed(float|int|null $value, int $decimals): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return ($value >= 0 ? '+' : '').number_format((float) $value, $decimals);
    }
}
