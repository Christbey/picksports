<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class ReportCalibrationCommand extends Command
{
    protected $signature = 'mlb:report-calibration
        {--season= : Filter by season}
        {--limit=500 : Limit number of most recent graded predictions to inspect}
        {--feature-version=core-v3 : Filter to a single feature_version (use "any" to include all)}
        {--output= : Optional JSON report output path}';

    protected $description = 'Report MLB prediction accuracy and market calibration metrics';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn('No graded MLB predictions found for the selected scope.');

            return self::SUCCESS;
        }

        $report = $this->buildReport($rows);

        $this->info('MLB Prediction Calibration Report');
        $this->line('Scope: '.($this->option('season') ? 'season '.$this->option('season') : 'all seasons'));
        $this->line('Rows: '.(string) $report['summary']['count']);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Winner accuracy', number_format((float) $report['summary']['winner_accuracy'], 1).'%'],
                ['Spread MAE', number_format((float) $report['summary']['spread_mae'], 2)],
                ['Total MAE', number_format((float) $report['summary']['total_mae'], 2)],
                ['Market spread MAE', $this->fmtNullable($report['summary']['market_spread_mae'] ?? null)],
                ['Market total MAE', $this->fmtNullable($report['summary']['market_total_mae'] ?? null)],
                ['Spread bias vs market', $this->signedNullable($report['summary']['spread_bias_vs_market'] ?? null, 2)],
                ['Total bias vs market', $this->signedNullable($report['summary']['total_bias_vs_market'] ?? null, 2)],
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

        if ($output = $this->option('output')) {
            $path = (string) $output;
            $directory = dirname($path);
            if (! is_dir($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
            $this->info("Wrote report to {$path}");
        }

        return self::SUCCESS;
    }

    private function loadRows(): Collection
    {
        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('graded_at')
            ->whereNotNull('predicted_spread')
            ->whereNotNull('predicted_total')
            ->latest('graded_at');

        if ($this->option('season')) {
            $query->whereHas('game', fn ($gameQuery) => $gameQuery->where('season', (int) $this->option('season')));
        }

        $featureVersion = (string) $this->option('feature-version');
        if ($featureVersion !== '' && strtolower($featureVersion) !== 'any') {
            $query->where('feature_version', $featureVersion);
        }

        $limit = max(1, (int) $this->option('limit'));
        $query->limit($limit);

        return $query->get()
            ->map(function (Prediction $prediction): ?array {
                $game = $prediction->game;
                if (! $game || ! is_numeric($game->home_score) || ! is_numeric($game->away_score)) {
                    return null;
                }

                $marketSpread = is_numeric($prediction->vegas_spread) ? (float) $prediction->vegas_spread : null;
                $marketTotal = $this->extractMarketTotal($prediction);
                $actualMargin = (float) $game->home_score - (float) $game->away_score;
                $actualTotal = (float) $game->home_score + (float) $game->away_score;

                return [
                    'game' => $this->teamName($game->awayTeam).' @ '.$this->teamName($game->homeTeam),
                    'predicted_spread' => (float) $prediction->predicted_spread,
                    'predicted_total' => (float) $prediction->predicted_total,
                    'actual_margin' => $actualMargin,
                    'actual_total' => $actualTotal,
                    'winner_correct' => (bool) $prediction->winner_correct,
                    'spread_error' => (float) ($prediction->spread_error ?? abs($actualMargin - (float) $prediction->predicted_spread)),
                    'total_error' => (float) ($prediction->total_error ?? abs($actualTotal - (float) $prediction->predicted_total)),
                    'confidence_score' => (float) $prediction->confidence_score,
                    'market_spread' => $marketSpread,
                    'market_total' => $marketTotal,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function buildReport(Collection $rows): array
    {
        $marketSpreadRows = $rows->filter(fn (array $row) => $row['market_spread'] !== null)->values();
        $marketTotalRows = $rows->filter(fn (array $row) => $row['market_total'] !== null)->values();

        return [
            'report_type' => 'mlb_prediction_calibration',
            'season' => $this->option('season') ? (int) $this->option('season') : null,
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'count' => $rows->count(),
                'winner_accuracy' => round($rows->where('winner_correct', true)->count() / max(1, $rows->count()) * 100, 1),
                'spread_mae' => round((float) $rows->avg('spread_error'), 2),
                'total_mae' => round((float) $rows->avg('total_error'), 2),
                'market_spread_sample' => $marketSpreadRows->count(),
                'market_total_sample' => $marketTotalRows->count(),
                // NOTE: `market_spread` is captured in Vegas convention (negative = home favored),
                // while `predicted_spread` and `actual_margin` are home-perspective (positive = home won by N).
                // We compare them by inverting the Vegas sign.
                'market_spread_mae' => $marketSpreadRows->isNotEmpty()
                    ? round((float) $marketSpreadRows->map(fn (array $row) => abs((float) $row['actual_margin'] + (float) $row['market_spread']))->avg(), 2)
                    : null,
                'market_total_mae' => $marketTotalRows->isNotEmpty()
                    ? round((float) $marketTotalRows->map(fn (array $row) => abs((float) $row['actual_total'] - (float) $row['market_total']))->avg(), 2)
                    : null,
                'spread_bias_vs_market' => $marketSpreadRows->isNotEmpty()
                    ? round((float) $marketSpreadRows->avg('predicted_spread') + (float) $marketSpreadRows->avg('market_spread'), 2)
                    : null,
                'total_bias_vs_market' => $marketTotalRows->isNotEmpty()
                    ? round((float) $marketTotalRows->avg('predicted_total') - (float) $marketTotalRows->avg('market_total'), 2)
                    : null,
                'avg_confidence' => round((float) $rows->avg('confidence_score'), 2),
                'min_confidence' => round((float) $rows->min('confidence_score'), 2),
                'max_confidence' => round((float) $rows->max('confidence_score'), 2),
            ],
            'confidence_buckets' => $this->confidenceBuckets($rows),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function confidenceBuckets(Collection $rows): array
    {
        $buckets = [
            '50-54.9' => fn (float $confidence) => $confidence >= 50.0 && $confidence < 55.0,
            '55-59.9' => fn (float $confidence) => $confidence >= 55.0 && $confidence < 60.0,
            '60-64.9' => fn (float $confidence) => $confidence >= 60.0 && $confidence < 65.0,
            '65+' => fn (float $confidence) => $confidence >= 65.0,
        ];

        $table = [];
        foreach ($buckets as $label => $filter) {
            $group = $rows->filter(fn (array $row) => $filter((float) $row['confidence_score']))->values();
            if ($group->isEmpty()) {
                continue;
            }

            $table[] = [
                $label,
                (string) $group->count(),
                number_format($group->where('winner_correct', true)->count() / $group->count() * 100, 1).'%',
                number_format((float) $group->avg('spread_error'), 2),
                number_format((float) $group->avg('total_error'), 2),
            ];
        }

        return $table;
    }

    private function extractMarketTotal(Prediction $prediction): ?float
    {
        $metadataTotal = data_get($prediction->model_metadata, 'market_context.market_total');
        if (is_numeric($metadataTotal)) {
            return (float) $metadataTotal;
        }

        $oddsData = $prediction->game?->odds_data;
        if (! is_array($oddsData)) {
            return null;
        }

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'totals') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (($outcome['name'] ?? null) === 'Over' && is_numeric($outcome['point'] ?? null)) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    private function teamName(mixed $team): string
    {
        if (! $team) {
            return 'Unknown';
        }

        return trim(((string) ($team->location ?? '')).' '.((string) ($team->name ?? '')));
    }

    private function fmtNullable(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2) : 'n/a';
    }

    private function signedNullable(mixed $value, int $precision = 2): string
    {
        if (! is_numeric($value)) {
            return 'n/a';
        }

        $number = number_format((float) $value, $precision);

        return ((float) $value > 0 ? '+' : '').$number;
    }
}
