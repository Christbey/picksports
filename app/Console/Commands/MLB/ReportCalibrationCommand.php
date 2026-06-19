<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\MLB\MlbPredictionRecommendationService;
use App\Support\MLB\MlbGamePhase;
use App\Support\Odds\AmericanOdds;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class ReportCalibrationCommand extends Command
{
    protected $signature = 'mlb:report-calibration
        {--season= : Filter by season}
        {--limit=500 : Limit number of most recent graded predictions to inspect}
        {--feature-version=core-v3 : Filter to a single feature_version (use "any" to include all)}
        {--strict-pregame : Exclude rows without provably pregame-safe timestamps and market context}
        {--diagnostics : Include underperformance diagnostic breakdowns}
        {--compare-market : Include model-vs-market diagnostic baselines}
        {--json : Output the report as JSON}
        {--output= : Optional JSON report output path}';

    protected $description = 'Report MLB prediction accuracy and market calibration metrics';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            if ($this->option('strict-pregame') && isset($this->strictPregameExcludedRows) && $this->strictPregameExcludedRows->isNotEmpty()) {
                $this->warn('No strict-pregame eligible MLB predictions found for the selected scope.');
                $this->line('Graded candidate rows inspected: '.(string) $this->gradedCandidateCount);
                $this->line('Rows excluded by strict-pregame rules: '.(string) $this->strictPregameExcludedRows->count());
                $this->newLine();
                $this->info('Strict Pregame Exclusions');
                $this->table(
                    ['Reason', 'Rows'],
                    $this->strictExclusionReasonRows()
                );

                return self::SUCCESS;
            }

            $this->warn('No graded MLB predictions found for the selected scope.');

            return self::SUCCESS;
        }

        $report = $this->buildReport($rows);

        if ($this->option('diagnostics') || $this->option('compare-market')) {
            $report['diagnostics'] = $this->buildDiagnostics($rows);
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('MLB Prediction Calibration Report');
        $this->line('Scope: '.($this->option('season') ? 'season '.$this->option('season') : 'all seasons'));
        $this->line('Rows: '.(string) $report['summary']['count']);
        if ($this->option('strict-pregame')) {
            $this->line('Strict pregame: enabled');
            $this->line('Rows excluded: '.(string) $report['strict_pregame']['excluded_count']);
        }
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

        $this->newLine();
        $this->info('Public Recommendation Buckets');
        $this->table(
            ['Bucket', 'Games', 'Winner %', 'Spread MAE', 'Total MAE'],
            $report['public_recommendation_buckets']
        );

        $this->newLine();
        $this->info('Candidate Recommendation Buckets');
        $this->table(
            ['Bucket', 'Games', 'Winner %', 'Spread MAE', 'Total MAE'],
            $report['candidate_recommendation_buckets']
        );

        if (($report['promotion_block_reasons'] ?? []) !== []) {
            $this->newLine();
            $this->info('Promotion Block Reasons');
            $this->table(
                ['Reason', 'Rows'],
                $report['promotion_block_reasons']
            );
        }

        if ($this->option('strict-pregame')) {
            $this->newLine();
            $this->info('Strict Pregame Exclusions');
            $this->table(
                ['Reason', 'Rows'],
                $report['strict_pregame']['exclusion_reasons']
            );
        }

        if (($report['diagnostics'] ?? null) !== null) {
            $this->renderDiagnostics($report['diagnostics']);
        }

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

        $strictExcluded = [];

        $candidateRows = $query->get();
        $this->gradedCandidateCount = $candidateRows->count();

        $rows = $candidateRows
            ->map(function (Prediction $prediction): ?array {
                $game = $prediction->game;
                if (! $game || ! is_numeric($game->home_score) || ! is_numeric($game->away_score)) {
                    return null;
                }

                $snapshot = $this->latestFeatureSnapshot($prediction);
                $strictExclusions = $this->strictPregameExclusions($prediction, $snapshot);

                if ($this->option('strict-pregame') && $strictExclusions !== []) {
                    return [
                        '_excluded' => true,
                        'exclusion_reasons' => $strictExclusions,
                    ];
                }

                $recommendation = app(MlbPredictionRecommendationService::class)->forPrediction($prediction);
                $publicRecommendation = $recommendation['public'] ?? $recommendation;
                $candidateRecommendation = $recommendation['candidate'] ?? $recommendation['pregame_recommendation'] ?? $recommendation;
                $promotion = (array) ($recommendation['promotion'] ?? []);

                $marketSpread = is_numeric($prediction->vegas_spread) ? (float) $prediction->vegas_spread : null;
                $marketTotal = $this->extractMarketTotal($prediction);
                $h2hPrices = $this->extractH2hPrices($prediction);
                $marketProbabilities = AmericanOdds::noVigProbabilities($h2hPrices['home'], $h2hPrices['away']);
                $actualMargin = (float) $game->home_score - (float) $game->away_score;
                $actualTotal = (float) $game->home_score + (float) $game->away_score;
                $homeWinProbability = (float) $prediction->win_probability;
                $homeWon = $actualMargin > 0;
                $modelPickSide = $this->modelPickSide((float) $prediction->predicted_spread, $homeWinProbability);
                $marketHomeMargin = $marketSpread !== null ? -1 * $marketSpread : null;
                $marketPickSide = match (true) {
                    $marketHomeMargin !== null => $marketHomeMargin >= 0 ? 'home' : 'away',
                    $marketProbabilities['home'] !== null && $marketProbabilities['away'] !== null => $marketProbabilities['home'] >= $marketProbabilities['away'] ? 'home' : 'away',
                    default => null,
                };
                $modelPickWon = match ($modelPickSide) {
                    'home' => $homeWon,
                    'away' => ! $homeWon,
                    default => null,
                };
                $marketPickWon = $marketPickSide === null ? null : ($marketPickSide === 'home' ? $homeWon : ! $homeWon);
                $modelProbability = match ($modelPickSide) {
                    'home' => $homeWinProbability,
                    'away' => 1 - $homeWinProbability,
                    default => max($homeWinProbability, 1 - $homeWinProbability),
                };
                $marketProbability = $marketPickSide === 'home'
                    ? $marketProbabilities['home']
                    : ($marketPickSide === 'away' ? $marketProbabilities['away'] : null);
                $parkAdjustment = data_get($prediction->model_metadata, 'park_context.total_adjustment');
                $weatherAdjustment = data_get($prediction->model_metadata, 'actual_weather.total_adjustment');
                $homePitcherSource = (string) data_get($prediction->model_metadata, 'pitcher_inputs.home_source', 'unknown');
                $awayPitcherSource = (string) data_get($prediction->model_metadata, 'pitcher_inputs.away_source', 'unknown');
                $candidateRiskFlags = array_values((array) ($candidateRecommendation['risk_flags'] ?? []));
                $publicRiskFlags = array_values((array) ($publicRecommendation['risk_flags'] ?? []));
                $promotionBlockReasons = array_values((array) ($promotion['block_reasons'] ?? []));

                return [
                    'game_id' => (int) $game->id,
                    'game' => $this->teamName($game->awayTeam).' @ '.$this->teamName($game->homeTeam),
                    'game_date' => $game->game_date?->toDateString(),
                    'month' => $game->game_date?->format('M') ?? 'unknown',
                    'home_team' => $this->teamName($game->homeTeam),
                    'away_team' => $this->teamName($game->awayTeam),
                    'home_won' => $homeWon,
                    'predicted_spread' => (float) $prediction->predicted_spread,
                    'predicted_total' => (float) $prediction->predicted_total,
                    'home_win_probability' => $homeWinProbability,
                    'model_pick_side' => $modelPickSide,
                    'model_pick_won' => $modelPickWon,
                    'model_pick_probability' => $modelProbability,
                    'actual_margin' => $actualMargin,
                    'actual_total' => $actualTotal,
                    'winner_correct' => (bool) $prediction->winner_correct,
                    'spread_error' => (float) ($prediction->spread_error ?? abs($actualMargin - (float) $prediction->predicted_spread)),
                    'total_error' => (float) ($prediction->total_error ?? abs($actualTotal - (float) $prediction->predicted_total)),
                    'confidence_score' => (float) $prediction->confidence_score,
                    'market_spread' => $marketSpread,
                    'market_home_margin' => $marketHomeMargin,
                    'market_pick_side' => $marketPickSide,
                    'market_pick_won' => $marketPickWon,
                    'market_home_probability' => $marketProbabilities['home'],
                    'market_away_probability' => $marketProbabilities['away'],
                    'market_pick_probability' => $marketProbability,
                    'home_moneyline' => $h2hPrices['home'],
                    'away_moneyline' => $h2hPrices['away'],
                    'market_total' => $marketTotal,
                    'recommendation_type' => (string) ($candidateRecommendation['recommendation_type'] ?? 'no_play'),
                    'candidate_recommendation_type' => (string) ($candidateRecommendation['recommendation_type'] ?? 'no_play'),
                    'public_recommendation_type' => (string) ($publicRecommendation['recommendation_type'] ?? 'no_play'),
                    'promotion_status' => (string) ($promotion['status'] ?? 'unknown'),
                    'promotion_block_reasons' => $promotionBlockReasons,
                    'promotion_block_reason_key' => $promotionBlockReasons === [] ? 'none' : implode(',', $promotionBlockReasons),
                    'risk_flags' => $candidateRiskFlags,
                    'risk_flag_key' => $candidateRiskFlags === [] ? 'none' : implode(',', $candidateRiskFlags),
                    'public_risk_flags' => $publicRiskFlags,
                    'public_risk_flag_key' => $publicRiskFlags === [] ? 'none' : implode(',', $publicRiskFlags),
                    'signal_score' => $candidateRecommendation['score'] ?? null,
                    'raw_edge' => $candidateRecommendation['raw_edge'] ?? null,
                    'no_vig_edge' => $candidateRecommendation['no_vig_edge'] ?? null,
                    'home_pitcher_source' => $homePitcherSource,
                    'away_pitcher_source' => $awayPitcherSource,
                    'pitcher_source_bucket' => $this->pitcherSourceBucket($homePitcherSource, $awayPitcherSource),
                    'park_adjustment' => is_numeric($parkAdjustment) ? (float) $parkAdjustment : 0.0,
                    'weather_adjustment' => is_numeric($weatherAdjustment) ? (float) $weatherAdjustment : 0.0,
                    'feature_snapshot_at' => $snapshot?->generated_at?->toIso8601String(),
                    'snapshot_run_id' => $snapshot?->snapshot_run_id,
                ];
            })
            ->filter()
            ->values();

        if (! $this->option('strict-pregame')) {
            return $rows;
        }

        $strictExcluded = $rows->filter(fn (array $row): bool => (bool) ($row['_excluded'] ?? false))->values();
        $this->strictPregameExcludedRows = $strictExcluded;

        return $rows
            ->reject(fn (array $row): bool => (bool) ($row['_excluded'] ?? false))
            ->values();
    }

    private Collection $strictPregameExcludedRows;

    private int $gradedCandidateCount = 0;

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
            'recommendation_buckets' => $this->recommendationBuckets($rows, 'candidate_recommendation_type'),
            'public_recommendation_buckets' => $this->recommendationBuckets($rows, 'public_recommendation_type'),
            'candidate_recommendation_buckets' => $this->recommendationBuckets($rows, 'candidate_recommendation_type'),
            'promotion_block_reasons' => $this->promotionBlockReasonRows($rows),
            'strict_pregame' => [
                'enabled' => (bool) $this->option('strict-pregame'),
                'excluded_count' => isset($this->strictPregameExcludedRows) ? $this->strictPregameExcludedRows->count() : 0,
                'exclusion_reasons' => $this->strictExclusionReasonRows(),
            ],
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

    /**
     * @return array{home:?int,away:?int}
     */
    private function extractH2hPrices(Prediction $prediction): array
    {
        $oddsData = $prediction->game?->odds_data;
        if (! is_array($oddsData)) {
            return ['home' => null, 'away' => null];
        }

        $homeName = strtolower((string) ($oddsData['home_team'] ?? ''));
        $awayName = strtolower((string) ($oddsData['away_team'] ?? ''));
        $homeTeam = strtolower($this->teamName($prediction->game?->homeTeam));
        $awayTeam = strtolower($this->teamName($prediction->game?->awayTeam));
        $prices = ['home' => null, 'away' => null];

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'h2h') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    $name = strtolower((string) ($outcome['name'] ?? ''));
                    $price = $outcome['price'] ?? null;
                    if ($name === '' || ! is_numeric($price)) {
                        continue;
                    }

                    if ($name === $homeName || $name === $homeTeam || str_contains($homeTeam, $name) || str_contains($name, $homeTeam)) {
                        $prices['home'] = (int) $price;
                    }

                    if ($name === $awayName || $name === $awayTeam || str_contains($awayTeam, $name) || str_contains($name, $awayTeam)) {
                        $prices['away'] = (int) $price;
                    }
                }

                if ($prices['home'] !== null || $prices['away'] !== null) {
                    return $prices;
                }
            }
        }

        return $prices;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function buildDiagnostics(Collection $rows): array
    {
        return [
            'metadata' => [
                'season' => $this->option('season') ? (int) $this->option('season') : null,
                'feature_version' => (string) $this->option('feature-version'),
                'limit' => (int) $this->option('limit'),
                'strict_pregame' => (bool) $this->option('strict-pregame'),
                'rows' => $rows->count(),
                'date_range' => [
                    'from' => $rows->min('game_date'),
                    'to' => $rows->max('game_date'),
                ],
            ],
            'baselines' => $this->baselineComparison($rows),
            'winner_breakdowns' => [
                'by_pick_side' => $this->groupRows($rows, fn (array $row): string => (string) $row['model_pick_side']),
                'by_favorite_underdog' => $this->groupRows($rows, fn (array $row): string => $this->favoriteBucket($row)),
                'by_model_probability_bucket' => $this->groupRows($rows, fn (array $row): string => $this->probabilityBucket((float) $row['model_pick_probability'])),
                'by_confidence_bucket' => $this->groupRows($rows, fn (array $row): string => $this->confidenceBucketLabel((float) $row['confidence_score'])),
            ],
            'spread_breakdowns' => [
                'by_predicted_spread_bucket' => $this->groupRows($rows, fn (array $row): string => $this->spreadBucket((float) $row['predicted_spread'])),
                'by_model_market_spread_gap' => $this->groupRows(
                    $rows->filter(fn (array $row): bool => $row['market_home_margin'] !== null)->values(),
                    fn (array $row): string => $this->gapBucket(abs((float) $row['predicted_spread'] - (float) $row['market_home_margin']))
                ),
            ],
            'total_breakdowns' => [
                'by_predicted_total_bucket' => $this->groupRows($rows, fn (array $row): string => $this->totalBucket((float) $row['predicted_total'])),
                'by_model_market_total_gap' => $this->groupRows(
                    $rows->filter(fn (array $row): bool => $row['market_total'] !== null)->values(),
                    fn (array $row): string => $this->gapBucket(abs((float) $row['predicted_total'] - (float) $row['market_total']))
                ),
            ],
            'confidence_distribution' => $this->confidenceDistribution($rows),
            'recommendation_breakdowns' => [
                'by_type' => $this->groupRows($rows, fn (array $row): string => (string) $row['candidate_recommendation_type']),
                'by_public_type' => $this->groupRows($rows, fn (array $row): string => (string) $row['public_recommendation_type']),
                'by_promotion_block_reason' => $this->groupRows($rows, fn (array $row): string => (string) $row['promotion_block_reason_key']),
                'by_signal_score' => $this->groupRows($rows, fn (array $row): string => $this->signalScoreBucket($row['signal_score'])),
                'by_raw_edge' => $this->groupRows($rows, fn (array $row): string => $this->edgeBucket($row['raw_edge'])),
                'by_no_vig_edge' => $this->groupRows($rows, fn (array $row): string => $this->edgeBucket($row['no_vig_edge'])),
                'by_risk_flag' => $this->groupRows($rows, fn (array $row): string => (string) $row['risk_flag_key']),
            ],
            'pitcher_source_breakdowns' => $this->groupRows($rows, fn (array $row): string => (string) $row['pitcher_source_bucket']),
            'park_weather_breakdowns' => [
                'park' => $this->groupRows($rows, fn (array $row): string => $this->adjustmentBucket((float) $row['park_adjustment'], 'park')),
                'weather' => $this->groupRows($rows, fn (array $row): string => $this->adjustmentBucket((float) $row['weather_adjustment'], 'weather')),
                'combined' => $this->groupRows($rows, fn (array $row): string => $this->adjustmentBucket((float) $row['park_adjustment'] + (float) $row['weather_adjustment'], 'combined')),
            ],
            'month_breakdowns' => $this->groupRows($rows, fn (array $row): string => (string) $row['month']),
            'market_disagreement_breakdowns' => [
                'winner_agreement' => $this->groupRows($rows, fn (array $row): string => $this->marketAgreementBucket($row)),
                'spread_gap' => $this->groupRows(
                    $rows->filter(fn (array $row): bool => $row['market_home_margin'] !== null)->values(),
                    fn (array $row): string => $this->gapBucket(abs((float) $row['predicted_spread'] - (float) $row['market_home_margin']))
                ),
                'total_gap' => $this->groupRows(
                    $rows->filter(fn (array $row): bool => $row['market_total'] !== null)->values(),
                    fn (array $row): string => $this->gapBucket(abs((float) $row['predicted_total'] - (float) $row['market_total']))
                ),
            ],
            'exclusions' => [
                'strict_pregame' => $this->strictExclusionReasonRows(),
                'graded_candidates' => $this->gradedCandidateCount,
            ],
            'bug_checks' => $this->bugChecks($rows),
            'warnings' => $this->diagnosticWarnings($rows),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function baselineComparison(Collection $rows): array
    {
        $marketRows = $rows->filter(fn (array $row): bool => $row['market_home_margin'] !== null || $row['market_total'] !== null)->values();
        $marketWinnerRows = $rows->filter(fn (array $row): bool => $row['market_pick_won'] !== null)->values();
        $marketProbabilityRows = $rows->filter(fn (array $row): bool => $row['market_home_probability'] !== null)->values();

        return [
            $this->baselineRow('Current model', $rows, 'model'),
            [
                ...$this->baselineRow('Market favorite/spread/total', $marketRows, 'market'),
                'winner_accuracy' => $marketWinnerRows->isNotEmpty() ? $this->pct($marketWinnerRows->where('market_pick_won', true)->count(), $marketWinnerRows->count()) : null,
                'brier' => $marketProbabilityRows->isNotEmpty() ? round((float) $marketProbabilityRows->map(fn (array $row): float => $this->brier((float) $row['market_home_probability'], (bool) $row['home_won']))->avg(), 4) : null,
                'log_loss' => $marketProbabilityRows->isNotEmpty() ? round((float) $marketProbabilityRows->map(fn (array $row): float => $this->logLoss((float) $row['market_home_probability'], (bool) $row['home_won']))->avg(), 4) : null,
            ],
            [
                ...$this->baselineRow('Home team', $rows, 'home'),
                'winner_accuracy' => $this->pct($rows->where('home_won', true)->count(), $rows->count()),
                'spread_mae' => null,
                'total_mae' => null,
                'brier' => round((float) $rows->map(fn (array $row): float => $this->brier(0.5, (bool) $row['home_won']))->avg(), 4),
                'log_loss' => round((float) $rows->map(fn (array $row): float => $this->logLoss(0.5, (bool) $row['home_won']))->avg(), 4),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function baselineRow(string $method, Collection $rows, string $type): array
    {
        $spreadRows = $rows->filter(fn (array $row): bool => $type !== 'market' || $row['market_home_margin'] !== null)->values();
        $totalRows = $rows->filter(fn (array $row): bool => $type !== 'market' || $row['market_total'] !== null)->values();

        return [
            'method' => $method,
            'rows' => $rows->count(),
            'winner_accuracy' => $type === 'model' ? $this->pct($rows->where('winner_correct', true)->count(), $rows->count()) : null,
            'spread_mae' => match ($type) {
                'model' => round((float) $rows->avg('spread_error'), 2),
                'market' => $spreadRows->isNotEmpty()
                    ? round((float) $spreadRows->map(fn (array $row): float => abs((float) $row['actual_margin'] - (float) $row['market_home_margin']))->avg(), 2)
                    : null,
                default => null,
            },
            'total_mae' => match ($type) {
                'model' => round((float) $rows->avg('total_error'), 2),
                'market' => $totalRows->isNotEmpty()
                    ? round((float) $totalRows->map(fn (array $row): float => abs((float) $row['actual_total'] - (float) $row['market_total']))->avg(), 2)
                    : null,
                default => null,
            },
            'brier' => $type === 'model'
                ? round((float) $rows->map(fn (array $row): float => $this->brier((float) $row['home_win_probability'], (bool) $row['home_won']))->avg(), 4)
                : null,
            'log_loss' => $type === 'model'
                ? round((float) $rows->map(fn (array $row): float => $this->logLoss((float) $row['home_win_probability'], (bool) $row['home_won']))->avg(), 4)
                : null,
            'notes' => $type === 'market' ? 'Market rows only where market data is present.' : null,
        ];
    }

    /**
     * @param  callable(array<string, mixed>): string  $groupBy
     * @return array<int, array<string, mixed>>
     */
    private function groupRows(Collection $rows, callable $groupBy): array
    {
        return $rows
            ->groupBy($groupBy)
            ->map(fn (Collection $group, string $label): array => $this->diagnosticGroupRow($label, $group->values()))
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private function diagnosticGroupRow(string $label, Collection $group): array
    {
        $marketWinnerRows = $group->filter(fn (array $row): bool => $row['market_pick_won'] !== null)->values();
        $marketSpreadRows = $group->filter(fn (array $row): bool => $row['market_home_margin'] !== null)->values();
        $marketTotalRows = $group->filter(fn (array $row): bool => $row['market_total'] !== null)->values();

        return [
            'label' => $label,
            'rows' => $group->count(),
            'accuracy' => $this->pct($group->where('winner_correct', true)->count(), $group->count()),
            'market_accuracy' => $marketWinnerRows->isNotEmpty() ? $this->pct($marketWinnerRows->where('market_pick_won', true)->count(), $marketWinnerRows->count()) : null,
            'avg_model_probability' => round((float) $group->avg('model_pick_probability'), 4),
            'avg_market_probability' => $group->whereNotNull('market_pick_probability')->isNotEmpty() ? round((float) $group->whereNotNull('market_pick_probability')->avg('market_pick_probability'), 4) : null,
            'spread_mae' => round((float) $group->avg('spread_error'), 2),
            'market_spread_mae' => $marketSpreadRows->isNotEmpty()
                ? round((float) $marketSpreadRows->map(fn (array $row): float => abs((float) $row['actual_margin'] - (float) $row['market_home_margin']))->avg(), 2)
                : null,
            'spread_bias' => round((float) $group->map(fn (array $row): float => (float) $row['predicted_spread'] - (float) $row['actual_margin'])->avg(), 2),
            'total_mae' => round((float) $group->avg('total_error'), 2),
            'market_total_mae' => $marketTotalRows->isNotEmpty()
                ? round((float) $marketTotalRows->map(fn (array $row): float => abs((float) $row['actual_total'] - (float) $row['market_total']))->avg(), 2)
                : null,
            'total_bias' => round((float) $group->map(fn (array $row): float => (float) $row['predicted_total'] - (float) $row['actual_total'])->avg(), 2),
            'brier' => round((float) $group->map(fn (array $row): float => $this->brier((float) $row['home_win_probability'], (bool) $row['home_won']))->avg(), 4),
            'avg_confidence' => round((float) $group->avg('confidence_score'), 2),
            'avg_signal_score' => $group->whereNotNull('signal_score')->isNotEmpty() ? round((float) $group->whereNotNull('signal_score')->avg('signal_score'), 2) : null,
            'avg_raw_edge' => $group->whereNotNull('raw_edge')->isNotEmpty() ? round((float) $group->whereNotNull('raw_edge')->avg('raw_edge'), 4) : null,
            'avg_no_vig_edge' => $group->whereNotNull('no_vig_edge')->isNotEmpty() ? round((float) $group->whereNotNull('no_vig_edge')->avg('no_vig_edge'), 4) : null,
            'avg_park_adjustment' => round((float) $group->avg('park_adjustment'), 2),
            'avg_weather_adjustment' => round((float) $group->avg('weather_adjustment'), 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|null>
     */
    private function confidenceDistribution(Collection $rows): array
    {
        $values = $rows->pluck('confidence_score')->map(fn (mixed $value): float => (float) $value)->sort()->values();
        $count = $values->count();
        $mean = (float) $values->avg();
        $variance = $count > 0
            ? (float) $values->map(fn (float $value): float => ($value - $mean) ** 2)->avg()
            : 0.0;

        return [
            'min' => $count > 0 ? round((float) $values->first(), 2) : null,
            'max' => $count > 0 ? round((float) $values->last(), 2) : null,
            'mean' => $count > 0 ? round($mean, 2) : null,
            'median' => $count > 0 ? round((float) $values->get((int) floor(($count - 1) / 2)), 2) : null,
            'std_dev' => $count > 0 ? round(sqrt($variance), 2) : null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function recommendationBuckets(Collection $rows, string $field): array
    {
        $groups = $rows->groupBy(fn (array $row): string => (string) ($row[$field] ?? 'no_play'));
        $table = [];

        foreach (['bet', 'lean', 'no_play', 'monitor'] as $bucket) {
            $group = $groups->get($bucket, collect())->values();
            if ($group->isEmpty()) {
                continue;
            }

            $table[] = [
                $bucket,
                (string) $group->count(),
                number_format($group->where('winner_correct', true)->count() / $group->count() * 100, 1).'%',
                number_format((float) $group->avg('spread_error'), 2),
                number_format((float) $group->avg('total_error'), 2),
            ];
        }

        return $table;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function promotionBlockReasonRows(Collection $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $reasons = (array) ($row['promotion_block_reasons'] ?? []);
            if ($reasons === []) {
                continue;
            }

            foreach ($reasons as $reason) {
                $reason = (string) $reason;
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }

        arsort($counts);

        return collect($counts)
            ->map(fn (int $count, string $reason): array => [$reason, (string) $count])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function renderDiagnostics(array $diagnostics): void
    {
        $this->newLine();
        $this->info('Diagnostic Baselines');
        $this->table(
            ['Method', 'Rows', 'Winner %', 'Spread MAE', 'Total MAE', 'Brier', 'Log Loss', 'Notes'],
            array_map(fn (array $row): array => [
                $row['method'],
                $row['rows'],
                $row['winner_accuracy'] !== null ? number_format((float) $row['winner_accuracy'], 1).'%' : 'n/a',
                $this->fmtNullable($row['spread_mae']),
                $this->fmtNullable($row['total_mae']),
                $row['brier'] !== null ? number_format((float) $row['brier'], 4) : 'n/a',
                $row['log_loss'] !== null ? number_format((float) $row['log_loss'], 4) : 'n/a',
                $row['notes'] ?? '',
            ], $diagnostics['baselines'])
        );

        foreach ([
            'Winner Accuracy By Pick Side' => data_get($diagnostics, 'winner_breakdowns.by_pick_side', []),
            'Winner Accuracy By Favorite/Underdog' => data_get($diagnostics, 'winner_breakdowns.by_favorite_underdog', []),
            'Winner Accuracy By Model Probability Bucket' => data_get($diagnostics, 'winner_breakdowns.by_model_probability_bucket', []),
            'Spread Error By Predicted Spread Bucket' => data_get($diagnostics, 'spread_breakdowns.by_predicted_spread_bucket', []),
            'Total Error By Predicted Total Bucket' => data_get($diagnostics, 'total_breakdowns.by_predicted_total_bucket', []),
            'Recommendation Performance' => data_get($diagnostics, 'recommendation_breakdowns.by_type', []),
            'Performance By Pitcher Source' => data_get($diagnostics, 'pitcher_source_breakdowns', []),
            'Performance By Park Adjustment' => data_get($diagnostics, 'park_weather_breakdowns.park', []),
            'Performance By Weather Adjustment' => data_get($diagnostics, 'park_weather_breakdowns.weather', []),
            'Performance By Combined Park/Weather Adjustment' => data_get($diagnostics, 'park_weather_breakdowns.combined', []),
            'Performance By Month' => data_get($diagnostics, 'month_breakdowns', []),
            'Model vs Market Winner Agreement' => data_get($diagnostics, 'market_disagreement_breakdowns.winner_agreement', []),
            'Model vs Market Spread Gap' => data_get($diagnostics, 'market_disagreement_breakdowns.spread_gap', []),
            'Model vs Market Total Gap' => data_get($diagnostics, 'market_disagreement_breakdowns.total_gap', []),
        ] as $title => $rows) {
            $this->newLine();
            $this->info($title);
            $this->table(
                ['Bucket', 'Rows', 'Winner %', 'Market %', 'Spread MAE', 'Market Spread MAE', 'Total MAE', 'Market Total MAE', 'Total Bias'],
                $this->diagnosticTableRows($rows)
            );
        }

        $this->newLine();
        $this->info('Confidence Distribution');
        $this->table(
            ['Metric', 'Value'],
            collect($diagnostics['confidence_distribution'])
                ->map(fn (mixed $value, string $key): array => [$key, $value ?? 'n/a'])
                ->values()
                ->all()
        );

        $this->newLine();
        $this->info('Calculation Bug Checks');
        $this->table(
            ['Check', 'Result', 'Evidence', 'Severity'],
            array_map(fn (array $row): array => [$row['check'], $row['result'], $row['evidence'], $row['severity']], $diagnostics['bug_checks'])
        );

        if (($diagnostics['warnings'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Diagnostic Warnings');
            foreach ($diagnostics['warnings'] as $warning) {
                $this->line(' - '.$warning);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function diagnosticTableRows(array $rows): array
    {
        return array_map(fn (array $row): array => [
            $row['label'],
            $row['rows'],
            $row['accuracy'] !== null ? number_format((float) $row['accuracy'], 1).'%' : 'n/a',
            $row['market_accuracy'] !== null ? number_format((float) $row['market_accuracy'], 1).'%' : 'n/a',
            $this->fmtNullable($row['spread_mae']),
            $this->fmtNullable($row['market_spread_mae']),
            $this->fmtNullable($row['total_mae']),
            $this->fmtNullable($row['market_total_mae']),
            $this->signedNullable($row['total_bias']),
        ], $rows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, string>>
     */
    private function bugChecks(Collection $rows): array
    {
        $winnerSpreadMismatches = $rows->filter(fn (array $row): bool => ((float) $row['predicted_spread'] > 0 && (float) $row['home_win_probability'] < 0.5)
            || ((float) $row['predicted_spread'] < 0 && (float) $row['home_win_probability'] > 0.5))->count();
        $actualMarginBadRows = $rows->filter(fn (array $row): bool => ! is_numeric($row['actual_margin']) || ! is_numeric($row['actual_total']))->count();
        $liveRows = $rows->filter(fn (array $row): bool => str_contains((string) $row['risk_flag_key'], 'live'))->count();
        $duplicateGames = $rows->groupBy('game_id')->filter(fn (Collection $group): bool => $group->count() > 1)->count();

        return [
            [
                'check' => 'Home/away mapping',
                'result' => $actualMarginBadRows === 0 ? 'pass' : 'fail',
                'evidence' => "{$actualMarginBadRows} row(s) lacked numeric actual margin/total.",
                'severity' => $actualMarginBadRows === 0 ? 'low' : 'high',
            ],
            [
                'check' => 'Winner inversion',
                'result' => $winnerSpreadMismatches === 0 ? 'pass' : 'fail',
                'evidence' => "{$winnerSpreadMismatches} row(s) had home win probability on the opposite side of spread sign.",
                'severity' => $winnerSpreadMismatches === 0 ? 'low' : 'high',
            ],
            [
                'check' => 'Spread sign',
                'result' => 'pass',
                'evidence' => 'Predicted spread uses home margin; market spread is inverted to market_home_margin before comparisons.',
                'severity' => 'low',
            ],
            [
                'check' => 'Market spread sign',
                'result' => 'pass',
                'evidence' => 'Market spread MAE uses actual_margin - (-vegas_spread).',
                'severity' => 'low',
            ],
            [
                'check' => 'Total calculation',
                'result' => 'pass',
                'evidence' => 'Actual total is home_score + away_score.',
                'severity' => 'low',
            ],
            [
                'check' => 'Duplicate rows',
                'result' => $duplicateGames === 0 ? 'pass' : 'warning',
                'evidence' => "{$duplicateGames} game id(s) appeared more than once in the report sample.",
                'severity' => $duplicateGames === 0 ? 'low' : 'medium',
            ],
            [
                'check' => 'Live rows excluded',
                'result' => $liveRows === 0 ? 'pass' : 'warning',
                'evidence' => "{$liveRows} row(s) carried live risk flags in the calibration sample.",
                'severity' => $liveRows === 0 ? 'low' : 'medium',
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    private function diagnosticWarnings(Collection $rows): array
    {
        $warnings = [];
        $baseline = $this->baselineComparison($rows);
        $model = $baseline[0];
        $market = $baseline[1];
        $confidence = $this->confidenceDistribution($rows);
        $recommendations = $this->groupRows($rows, fn (array $row): string => (string) $row['recommendation_type']);
        $lean = collect($recommendations)->firstWhere('label', 'lean');
        $noPlay = collect($recommendations)->firstWhere('label', 'no_play');

        if (($market['spread_mae'] ?? null) !== null && (float) $market['spread_mae'] < (float) $model['spread_mae']) {
            $warnings[] = 'Market spread MAE is better than model spread MAE; use market only as diagnostic baseline for now.';
        }

        if (($market['total_mae'] ?? null) !== null && (float) $market['total_mae'] < (float) $model['total_mae']) {
            $warnings[] = 'Market total MAE is better than model total MAE.';
        }

        if (($confidence['std_dev'] ?? 0) !== null && (float) $confidence['std_dev'] < 3.0) {
            $warnings[] = 'Confidence is compressed; do not treat current confidence labels as strong separation.';
        }

        if ($lean && $noPlay && (float) $lean['accuracy'] < (float) $noPlay['accuracy']) {
            $warnings[] = 'Lean bucket underperforms no_play bucket; do not promote leans until recommendation logic is fixed.';
        }

        return $warnings;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function strictExclusionReasonRows(): array
    {
        if (! isset($this->strictPregameExcludedRows)) {
            return [];
        }

        $counts = [];
        foreach ($this->strictPregameExcludedRows as $row) {
            foreach ((array) ($row['exclusion_reasons'] ?? []) as $reason) {
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }

        arsort($counts);

        return array_map(
            fn (string $reason, int $count): array => [$reason, (string) $count],
            array_keys($counts),
            array_values($counts)
        );
    }

    /**
     * @return list<string>
     */
    private function strictPregameExclusions(Prediction $prediction, ?PredictionFeatureSnapshot $snapshot): array
    {
        $reasons = [];
        $game = $prediction->game;

        if ($prediction->created_at === null) {
            $reasons[] = 'missing_prediction_timestamp';
        }

        if ($snapshot === null || $snapshot->generated_at === null) {
            $reasons[] = 'missing_feature_snapshot_timestamp';
        }

        if ($game === null || MlbGamePhase::scheduledStartAt($game) === null) {
            $reasons[] = 'missing_game_start_timestamp';
        }

        if ($game !== null && ! MlbGamePhase::isBacktestEligiblePregame($game, $snapshot?->generated_at ?? $prediction->created_at)) {
            $reasons[] = 'prediction_not_before_first_pitch';
        }

        if ($game !== null && in_array(MlbGamePhase::phase($game), [MlbGamePhase::POSTPONED, MlbGamePhase::SUSPENDED, MlbGamePhase::CANCELLED], true)) {
            $reasons[] = 'postponed_or_suspended';
        }

        if ($prediction->live_updated_at !== null || $prediction->live_win_probability !== null) {
            $reasons[] = 'live_prediction';
        }

        $marketSafety = (array) data_get($prediction->model_metadata, 'market_context.safety', []);
        if (($marketSafety['pregame_safe'] ?? false) !== true) {
            $reasons[] = 'missing_pregame_safe_market_context';
        }

        if (empty($marketSafety['odds_captured_at'])) {
            $reasons[] = 'missing_odds_timestamp';
        }

        return array_values(array_unique($reasons));
    }

    private function pitcherSourceBucket(string $homeSource, string $awaySource): string
    {
        $sources = strtolower($homeSource.' '.$awaySource);

        return match (true) {
            str_contains($sources, 'league_average') => 'league_average_fallback',
            str_contains($sources, 'team_recent_average') => 'team_recent_average_fallback',
            str_contains($sources, 'depth_chart') => 'depth_chart_fallback',
            str_contains($sources, 'probable_starter') => 'probable_starter',
            default => 'missing_or_unknown',
        };
    }

    private function favoriteBucket(array $row): string
    {
        $marketPick = $row['market_pick_side'] ?? null;
        if ($marketPick === null) {
            return 'market_unknown';
        }

        $modelPick = (string) $row['model_pick_side'];
        if (! in_array($modelPick, ['home', 'away'], true)) {
            return 'model_pickem';
        }

        if ($modelPick === $marketPick) {
            return $modelPick === 'home' ? 'home_favorite' : 'away_favorite';
        }

        return $modelPick === 'home' ? 'home_underdog' : 'away_underdog';
    }

    private function probabilityBucket(float $probability): string
    {
        return match (true) {
            $probability < 0.52 => '50-52%',
            $probability < 0.55 => '52-55%',
            $probability < 0.58 => '55-58%',
            $probability < 0.60 => '58-60%',
            $probability < 0.65 => '60-65%',
            default => '65%+',
        };
    }

    private function confidenceBucketLabel(float $confidence): string
    {
        return match (true) {
            $confidence < 52.0 => '50-52',
            $confidence < 55.0 => '52-55',
            $confidence < 58.0 => '55-58',
            $confidence < 61.0 => '58-61',
            default => '61+',
        };
    }

    private function spreadBucket(float $spread): string
    {
        return match (true) {
            $spread <= -3.0 => 'Away by 3+',
            $spread < -1.0 => 'Away by 1-3',
            $spread <= 1.0 => "Pick'em",
            $spread < 3.0 => 'Home by 1-3',
            default => 'Home by 3+',
        };
    }

    private function totalBucket(float $total): string
    {
        return match (true) {
            $total < 7.5 => 'Under 7.5',
            $total < 8.5 => '7.5-8.5',
            $total < 9.5 => '8.5-9.5',
            $total < 10.5 => '9.5-10.5',
            default => '10.5+',
        };
    }

    private function gapBucket(float $gap): string
    {
        return match (true) {
            $gap < 1.0 => '0-1',
            $gap < 2.0 => '1-2',
            default => '2+',
        };
    }

    private function signalScoreBucket(mixed $score): string
    {
        if (! is_numeric($score)) {
            return 'missing';
        }

        $score = (float) $score;

        return match (true) {
            $score < 40 => '0-39',
            $score < 55 => '40-54',
            $score < 70 => '55-69',
            default => '70+',
        };
    }

    private function edgeBucket(mixed $edge): string
    {
        if (! is_numeric($edge)) {
            return 'missing';
        }

        $edge = (float) $edge;

        return match (true) {
            $edge < -0.05 => 'negative',
            $edge < 0.0 => 'slightly_negative',
            $edge == 0.0 => 'zero',
            $edge < 0.03 => '0-3pp',
            $edge < 0.06 => '3-6pp',
            default => '6pp+',
        };
    }

    private function adjustmentBucket(float $adjustment, string $prefix): string
    {
        return match (true) {
            $adjustment <= -0.25 => "{$prefix}_suppress",
            $adjustment >= 0.25 => "{$prefix}_boost",
            default => "{$prefix}_neutral",
        };
    }

    private function marketAgreementBucket(array $row): string
    {
        if (($row['market_pick_side'] ?? null) === null) {
            return 'market_unknown';
        }

        if (! in_array($row['model_pick_side'], ['home', 'away'], true)) {
            return 'model_pickem';
        }

        if ($row['model_pick_side'] === $row['market_pick_side']) {
            return 'agree_on_winner';
        }

        return $row['model_pick_side'] === 'home' ? 'model_home_market_away' : 'model_away_market_home';
    }

    private function modelPickSide(float $predictedSpread, float $homeWinProbability): string
    {
        if ($predictedSpread > 0.0) {
            return 'home';
        }

        if ($predictedSpread < 0.0) {
            return 'away';
        }

        if ($homeWinProbability > 0.5) {
            return 'home';
        }

        if ($homeWinProbability < 0.5) {
            return 'away';
        }

        return 'pickem';
    }

    private function brier(float $homeProbability, bool $homeWon): float
    {
        return ($homeProbability - ($homeWon ? 1.0 : 0.0)) ** 2;
    }

    private function logLoss(float $homeProbability, bool $homeWon): float
    {
        $probability = max(0.001, min(0.999, $homeProbability));

        return -log($homeWon ? $probability : 1 - $probability);
    }

    private function pct(int|float $part, int|float $whole): ?float
    {
        if ((float) $whole <= 0.0) {
            return null;
        }

        return round(((float) $part / (float) $whole) * 100, 1);
    }

    private function latestFeatureSnapshot(Prediction $prediction): ?PredictionFeatureSnapshot
    {
        return PredictionFeatureSnapshot::query()
            ->where('prediction_table', $prediction->getTable())
            ->where('prediction_id', (int) $prediction->id)
            ->latest('generated_at')
            ->latest('id')
            ->first();
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
