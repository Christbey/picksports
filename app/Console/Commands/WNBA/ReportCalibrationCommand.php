<?php

namespace App\Console\Commands\WNBA;

use App\Models\WNBA\Prediction;
use App\Services\Predictions\Basketball\BasketballBacktestForecastSource;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ReportCalibrationCommand extends Command
{
    protected $signature = 'wnba:report-calibration
        {--forecast-source= : canonical or legacy (defaults to the active WNBA source)}
        {--season= : Filter by season}
        {--limit=500 : Limit number of most recent graded predictions to inspect}
        {--json : Output the report as JSON}';

    protected $description = 'Report WNBA prediction accuracy, bias, and confidence calibration metrics';

    public function handle(): int
    {
        try {
            $source = app(BasketballBacktestForecastSource::class)->name('wnba', $this->option('forecast-source'));
        } catch (\InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $rows = $source === 'canonical' ? $this->canonicalRows() : $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn($source === 'canonical' ? 'No eligible canonical WNBA forecasts found for completed games in the selected scope.' : 'No graded WNBA predictions found for the selected scope.');

            return self::SUCCESS;
        }

        $report = $this->buildReport($rows);
        if ($source === 'canonical') {
            $report['forecast_source'] = 'canonical';
            $report['evidence'] = 'Frozen canonical projections and recorded confidence; observed performance does not grant calibration or betting approval.';
            $report['forecast_lineage'] = $rows->map(fn (object $row): array => $row->canonical_lineage)->all();
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('WNBA Prediction Calibration Report');
        if ($source === 'canonical') {
            $this->line('Forecast source: canonical');
            $this->line($report['evidence']);
        }
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
                ['Avg confidence', $this->formatConfidence($report['summary']['avg_confidence'])],
                ['Confidence range', $this->formatConfidence($report['summary']['min_confidence']).' - '.$this->formatConfidence($report['summary']['max_confidence'])],
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

    private function canonicalRows(): Collection
    {
        return app(BasketballBacktestForecastSource::class)->forFinalGames('wnba', $this->option('season') ? (int) $this->option('season') : null)
            ->filter(fn (object $forecast): bool => $forecast->predicted_spread !== null && $forecast->predicted_total !== null)
            ->sortByDesc(fn (object $forecast): array => [$forecast->generated_at->timestamp, $forecast->canonical_prediction_id])
            ->take(max(1, (int) $this->option('limit')))
            ->map(function (object $forecast): object {
                $actual = (float) $forecast->game->home_score - (float) $forecast->game->away_score;
                $forecast->winner_correct = ($actual > 0 && $forecast->predicted_spread > 0) || ($actual < 0 && $forecast->predicted_spread < 0);

                return $forecast;
            })->values();
    }

    private function formatConfidence(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value, 2);
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
        $mapped = $rows->map(function (object $prediction): array {
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
                'confidence_score' => $prediction instanceof Prediction ? (float) $prediction->confidence_score : $prediction->confidence_score,
                'confidence_bucket' => $prediction->confidence_score === null && ! $prediction instanceof Prediction ? null : $this->confidenceBucket((float) $prediction->confidence_score),
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
                'avg_confidence' => $mapped->whereNotNull('confidence_score')->isEmpty() ? null : round((float) $mapped->avg('confidence_score'), 2),
                'min_confidence' => $mapped->whereNotNull('confidence_score')->isEmpty() ? null : round((float) $mapped->min('confidence_score'), 2),
                'max_confidence' => $mapped->whereNotNull('confidence_score')->isEmpty() ? null : round((float) $mapped->max('confidence_score'), 2),
            ],
            'confidence_buckets' => $mapped
                ->whereNotNull('confidence_bucket')
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
