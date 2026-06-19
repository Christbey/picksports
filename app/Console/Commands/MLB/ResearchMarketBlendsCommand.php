<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Prediction;
use App\Services\MLB\MlbPredictionRecommendationService;
use App\Support\Odds\AmericanOdds;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ResearchMarketBlendsCommand extends Command
{
    protected $signature = 'mlb:research-market-blends
        {--season= : Filter by season}
        {--feature-version=core-v3 : Filter to a single feature_version (use "any" to include all)}
        {--limit=2500 : Limit number of most recent graded predictions to inspect}
        {--from= : Start game date in YYYY-MM-DD}
        {--to= : End game date in YYYY-MM-DD}
        {--strict-pregame : Run performance tables only on rows with pregame-safe market context}
        {--json : Output structured JSON}';

    protected $description = 'Research MLB market-aware shadow probability blends without changing stored predictions.';

    /** @var list<float> */
    private array $weights = [1.0, 0.75, 0.5, 0.25, 0.1, 0.0];

    public function handle(MlbPredictionRecommendationService $recommendations): int
    {
        $rows = $this->loadRows($recommendations);
        $report = $this->buildReport($rows);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('MLB Market-Aware Shadow Model Research');
        $this->line('Shadow model: mlb_market_aware_shadow_v1');
        $this->line('Rows: '.$report['summary']['rows'].' | Market rows: '.$report['summary']['market_rows'].' | Strict market rows: '.$report['summary']['strict_market_rows']);
        $this->line('Strict pregame safe: '.($report['summary']['strict_pregame_safe'] ? 'yes' : 'no'));
        $this->line('Analysis rows: '.$report['summary']['analysis_rows'].' | Analysis mode: '.$report['summary']['analysis_mode']);
        $this->newLine();

        $this->info('Market-Aware Blend Grid');
        $this->table(
            ['Model Weight', 'Market Weight', 'Rows', 'Accuracy', 'Brier', 'Log Loss', 'Calibration Gap', 'Notes'],
            $report['market_aware_blend_grid']
        );

        $this->newLine();
        $this->info('Strict Pregame Market Blend Grid');
        $this->table(
            ['Model Weight', 'Market Weight', 'Rows', 'Accuracy', 'Brier', 'Log Loss', 'Calibration Gap', 'Notes'],
            $report['strict_pregame_market_blend_grid']
        );

        $this->newLine();
        $this->info('Blend Performance By Month');
        $this->table(
            ['Month', 'Rows', 'Pure Model Acc', '25% Model / 75% Market Acc', '50/50 Acc', 'Pure Market Acc', 'Best Brier', 'Notes'],
            $report['blend_performance_by_month']
        );

        $this->newLine();
        $this->info('Model-Market Disagreement Deep Dive');
        $this->table(
            ['Bucket', 'Rows', 'Model Accuracy', 'Market Accuracy', 'Blend Accuracy', 'Brier Model', 'Brier Blend', 'Notes'],
            $report['model_market_disagreement_deep_dive']
        );

        $this->newLine();
        $this->info('Research Candidate Rule Comparison');
        $this->table(
            ['Rule', 'Rows', 'Accuracy', 'Brier', 'Log Loss', 'Avg Edge', 'Notes'],
            $report['research_candidate_rule_comparison']
        );

        $this->newLine();
        $this->info('Public Recommendation Buckets');
        $this->table(['Type', 'Rows'], $report['public_recommendation_buckets']);

        $this->newLine();
        $this->info('Candidate Recommendation Buckets');
        $this->table(['Type', 'Rows'], $report['candidate_recommendation_buckets']);

        $this->newLine();
        $this->info('Promotion Block Reasons');
        $this->table(['Reason', 'Rows'], $report['promotion_block_reasons']);

        $this->newLine();
        $this->info('Total Bias Correction Grid');
        $this->table(
            ['Total Version', 'Rows', 'MAE', 'Bias', 'Beats Market MAE?', 'Notes'],
            $report['total_bias_correction_grid']
        );

        $this->newLine();
        $this->info('Confidence Recalibration Research');
        $this->table(
            ['Version', 'Range', 'Buckets', 'Accuracy', 'Brier', 'Monotonicity', 'Notes'],
            $report['confidence_recalibration_research']
        );

        $this->newLine();
        $this->info('Market Blend Exclusions');
        $this->table(['Reason', 'Rows'], $report['market_blend_exclusions']);

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }

        if (! $report['summary']['strict_pregame_safe']) {
            $this->newLine();
            $this->warn('Market-aware blend results are not strict-pregame safe.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadRows(MlbPredictionRecommendationService $recommendations): Collection
    {
        return $this->predictionQuery()
            ->get()
            ->map(function (Prediction $prediction) use ($recommendations): ?array {
                $game = $prediction->game;
                if (! $game || ! is_numeric($game->home_score) || ! is_numeric($game->away_score)) {
                    return null;
                }

                $h2hPrices = $this->extractH2hPrices($prediction);
                $marketProbabilities = AmericanOdds::noVigProbabilities($h2hPrices['home'], $h2hPrices['away']);
                $marketHomeProbability = $marketProbabilities['home'];
                $marketAwayProbability = $marketProbabilities['away'];
                $homeProbability = (float) $prediction->win_probability;
                $actualMargin = (float) $game->home_score - (float) $game->away_score;
                $actualTotal = (float) $game->home_score + (float) $game->away_score;
                $homeWon = $actualMargin > 0;
                $modelPickSide = $this->sideFromHomeProbability($homeProbability);
                $marketPickSide = $this->marketPickSide($marketProbabilities, $h2hPrices);
                $marketTotal = $this->extractMarketTotal($prediction);
                $recommendation = $recommendations->forPrediction($prediction);
                $public = (array) ($recommendation['public'] ?? $recommendation);
                $candidate = (array) ($recommendation['candidate'] ?? $recommendation['pregame_recommendation'] ?? $recommendation);
                $promotion = (array) ($recommendation['promotion'] ?? []);

                return [
                    'prediction_id' => (int) $prediction->id,
                    'game_id' => (int) $game->id,
                    'game_date' => $game->game_date?->toDateString(),
                    'month' => $game->game_date?->format('M') ?? 'unknown',
                    'game_time' => $game->game_time,
                    'status' => (string) $game->status,
                    'home_won' => $homeWon,
                    'actual_total' => $actualTotal,
                    'home_probability' => $homeProbability,
                    'model_pick_side' => $modelPickSide,
                    'model_pick_won' => $this->pickWon($modelPickSide, $homeWon),
                    'market_home_probability' => $marketHomeProbability,
                    'market_away_probability' => $marketAwayProbability,
                    'market_pick_side' => $marketPickSide,
                    'market_pick_won' => $marketPickSide === null ? null : $this->pickWon($marketPickSide, $homeWon),
                    'predicted_spread' => (float) $prediction->predicted_spread,
                    'market_home_margin' => is_numeric($prediction->vegas_spread) ? -1 * (float) $prediction->vegas_spread : null,
                    'predicted_total' => (float) $prediction->predicted_total,
                    'market_total' => $marketTotal,
                    'confidence_score' => (float) $prediction->confidence_score,
                    'candidate_recommendation_type' => (string) ($candidate['recommendation_type'] ?? 'no_play'),
                    'candidate_score' => $candidate['score'] ?? null,
                    'candidate_risk_flags' => array_values((array) ($candidate['risk_flags'] ?? [])),
                    'candidate_reason_codes' => array_values((array) ($candidate['reason_codes'] ?? [])),
                    'raw_edge' => $candidate['raw_edge'] ?? null,
                    'no_vig_edge' => $candidate['no_vig_edge'] ?? null,
                    'home_pitcher_source' => (string) data_get($prediction->model_metadata, 'pitcher_inputs.home_source', 'unknown'),
                    'away_pitcher_source' => (string) data_get($prediction->model_metadata, 'pitcher_inputs.away_source', 'unknown'),
                    'park_adjustment' => (float) data_get($prediction->model_metadata, 'park_context.total_adjustment', 0.0),
                    'weather_adjustment' => (float) data_get($prediction->model_metadata, 'actual_weather.total_adjustment', 0.0),
                    'odds_updated_at' => $game->odds_updated_at,
                    'public_recommendation_type' => (string) ($public['recommendation_type'] ?? 'no_play'),
                    'safety_flags' => $this->safetyFlags($prediction),
                    'promotion_status' => (string) ($promotion['status'] ?? 'unknown'),
                    'promotion_blocked' => ($promotion['status'] ?? null) === 'blocked',
                    'promotion_block_reasons' => array_values((array) ($promotion['block_reasons'] ?? [])),
                ];
            })
            ->filter()
            ->values();
    }

    private function predictionQuery(): Builder
    {
        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('graded_at')
            ->whereNotNull('win_probability')
            ->whereNotNull('predicted_total')
            ->latest('graded_at');

        if ($this->option('season')) {
            $query->whereHas('game', fn (Builder $gameQuery) => $gameQuery->where('season', (int) $this->option('season')));
        }

        if ($this->option('from')) {
            $query->whereHas('game', fn (Builder $gameQuery) => $gameQuery->whereDate('game_date', '>=', (string) $this->option('from')));
        }

        if ($this->option('to')) {
            $query->whereHas('game', fn (Builder $gameQuery) => $gameQuery->whereDate('game_date', '<=', (string) $this->option('to')));
        }

        $featureVersion = (string) $this->option('feature-version');
        if ($featureVersion !== '' && strtolower($featureVersion) !== 'any') {
            $query->where('feature_version', $featureVersion);
        }

        return $query->limit(max(1, (int) $this->option('limit')));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function buildReport(Collection $rows): array
    {
        $marketRows = $this->marketRows($rows);
        $strictMarketRows = $this->strictMarketRows($marketRows);
        $analysisRows = $this->option('strict-pregame') ? $strictMarketRows : $marketRows;
        $exclusions = $this->exclusionRows($rows);
        $analysisMode = $this->option('strict-pregame') ? 'strict_pregame_market_rows' : 'all_market_rows_flagged';
        $analysisPregameSafe = $analysisRows->isNotEmpty()
            && $analysisRows->every(fn (array $row): bool => $row['safety_flags'] === []);

        return [
            'report_type' => 'mlb_market_aware_shadow_research',
            'shadow_model_version' => 'mlb_market_aware_shadow_v1',
            'production_model_version' => (string) $this->option('feature-version'),
            'season' => $this->option('season') ? (int) $this->option('season') : null,
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'rows' => $rows->count(),
                'market_rows' => $marketRows->count(),
                'strict_market_rows' => $strictMarketRows->count(),
                'analysis_rows' => $analysisRows->count(),
                'analysis_mode' => $analysisMode,
                'analysis_pregame_safe' => $analysisPregameSafe,
                'public_recommendations_enabled' => false,
                'public_promoted_rows' => 0,
                'strict_pregame_safe' => collect($exclusions)->every(fn (array $row): bool => (int) $row[1] === 0),
                'notes' => 'Report-only shadow research. Stored MLB predictions and public recommendations are unchanged.',
            ],
            'market_aware_blend_grid' => $this->blendGrid($analysisRows),
            'strict_pregame_market_blend_grid' => $this->blendGrid($strictMarketRows),
            'blend_performance_by_month' => $this->blendPerformanceByMonth($analysisRows),
            'model_market_disagreement_deep_dive' => $this->disagreementDeepDive($analysisRows),
            'research_candidate_rule_comparison' => $this->candidateRuleComparison($analysisRows),
            'public_recommendation_buckets' => $this->bucketCounts($rows, 'public_recommendation_type'),
            'candidate_recommendation_buckets' => $this->bucketCounts($rows, 'candidate_recommendation_type'),
            'promotion_block_reasons' => $this->promotionBlockReasonRows($rows),
            'candidate_samples' => $this->candidateSamples($rows),
            'total_bias_correction_grid' => $this->totalBiasCorrectionGrid($rows),
            'total_bias_breakdowns' => $this->totalBiasBreakdowns($rows),
            'confidence_recalibration_research' => $this->confidenceResearch($analysisRows),
            'market_blend_exclusions' => $exclusions,
            'warnings' => $this->warnings($exclusions, $analysisMode, $analysisRows->count(), $strictMarketRows->count()),
        ];
    }

    /**
     * @param  array<int, array<int, string|int>>  $exclusions
     * @return list<string>
     */
    private function warnings(array $exclusions, string $analysisMode, int $analysisRows, int $strictRows): array
    {
        $counts = collect($exclusions)->mapWithKeys(fn (array $row): array => [(string) $row[0] => (int) $row[1]]);
        $warnings = [];

        if ($analysisMode === 'all_market_rows_flagged' && $counts->some(fn (int $count): bool => $count > 0)) {
            $warnings[] = 'Market-aware blend results include rows that are not strict-pregame safe; use --strict-pregame for promotion-quality research.';
        }

        if (($counts['missing_odds_timestamp'] ?? 0) > 0) {
            $warnings[] = 'Strict warning: odds timestamps are incomplete, so market-aware blend rows cannot be treated as proven pregame-safe.';
        }

        if (($counts['odds_after_first_pitch'] ?? 0) > 0) {
            $warnings[] = 'Strict warning: some odds timestamps are after first pitch, so those rows are market benchmarks only and not valid pregame evidence.';
        }

        if ($analysisMode === 'strict_pregame_market_rows' && $analysisRows === 0) {
            $warnings[] = 'No strict-pregame-safe market rows were available for the selected scope.';
        }

        if ($strictRows === 0) {
            $warnings[] = 'Strict pregame market sample is empty; do not use this report to validate public MLB recommendations.';
        }

        return $warnings;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function marketRows(Collection $rows): Collection
    {
        return $rows
            ->filter(fn (array $row): bool => $row['market_home_probability'] !== null && $row['market_away_probability'] !== null)
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string|int>>
     */
    private function bucketCounts(Collection $rows, string $key): array
    {
        return $rows
            ->countBy(fn (array $row): string => (string) ($row[$key] ?? 'unknown'))
            ->sortKeys()
            ->map(fn (int $count, string $bucket): array => [$bucket, $count])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string|int>>
     */
    private function promotionBlockReasonRows(Collection $rows): array
    {
        $reasons = $rows
            ->flatMap(fn (array $row): array => (array) ($row['promotion_block_reasons'] ?? []))
            ->filter()
            ->countBy()
            ->sortKeys()
            ->map(fn (int $count, string $reason): array => [$reason, $count])
            ->values();

        return $reasons->isEmpty() ? [['none', 0]] : $reasons->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function candidateSamples(Collection $rows): array
    {
        return $rows
            ->filter(fn (array $row): bool => ($row['candidate_recommendation_type'] ?? 'no_play') !== 'no_play')
            ->take(10)
            ->map(fn (array $row): array => [
                'prediction_id' => $row['prediction_id'],
                'game_id' => $row['game_id'],
                'game_date' => $row['game_date'],
                'public_recommendation_type' => $row['public_recommendation_type'],
                'candidate_recommendation_type' => $row['candidate_recommendation_type'],
                'promotion_blocked' => $row['promotion_blocked'],
                'block_reasons' => $row['promotion_block_reasons'],
                'raw_edge' => $row['raw_edge'],
                'no_vig_edge' => $row['no_vig_edge'],
                'score' => $row['candidate_score'],
                'risk_flags' => $row['candidate_risk_flags'],
                'reason_codes' => $row['candidate_reason_codes'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function strictMarketRows(Collection $rows): Collection
    {
        return $rows
            ->filter(fn (array $row): bool => $row['safety_flags'] === [])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string|int>>
     */
    private function exclusionRows(Collection $rows): array
    {
        $counts = [
            'missing_market_odds' => 0,
            'missing_odds_timestamp' => 0,
            'odds_after_first_pitch' => 0,
            'stale_odds' => 0,
            'missing_game_start_time' => 0,
            'missing_prediction_timestamp' => 0,
            'live_only_row' => 0,
            'postponed_suspended_cancelled' => 0,
        ];

        foreach ($rows as $row) {
            foreach ((array) ($row['safety_flags'] ?? []) as $flag) {
                $counts[$flag] = ($counts[$flag] ?? 0) + 1;
            }
        }

        return collect($counts)
            ->map(fn (int $count, string $reason): array => [$reason, $count])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function blendGrid(Collection $rows): array
    {
        return collect($this->weights)
            ->map(function (float $weight) use ($rows): array {
                $metrics = $this->blendMetrics($rows, $weight);
                $note = match ($weight) {
                    1.0 => 'Pure model',
                    0.0 => 'Pure market benchmark',
                    0.25 => 'Market-heavy shadow candidate',
                    default => 'Research only',
                };

                return [
                    number_format($weight, 2),
                    number_format(1 - $weight, 2),
                    (string) $metrics['rows'],
                    $this->pct($metrics['accuracy']),
                    $this->fmt($metrics['brier'], 4),
                    $this->fmt($metrics['log_loss'], 4),
                    $this->fmt($metrics['calibration_gap'], 4),
                    $note,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function blendPerformanceByMonth(Collection $rows): array
    {
        return $rows
            ->groupBy(fn (array $row): string => (string) $row['month'])
            ->map(function (Collection $monthRows, string $month): array {
                $monthRows = $monthRows->values();
                $bestBrier = collect($this->weights)
                    ->map(fn (float $weight): ?float => $this->blendMetrics($monthRows, $weight)['brier'])
                    ->filter(fn (?float $value): bool => $value !== null)
                    ->min();

                return [
                    $month,
                    (string) $monthRows->count(),
                    $this->pct($this->blendMetrics($monthRows, 1.0)['accuracy']),
                    $this->pct($this->blendMetrics($monthRows, 0.25)['accuracy']),
                    $this->pct($this->blendMetrics($monthRows, 0.5)['accuracy']),
                    $this->pct($this->blendMetrics($monthRows, 0.0)['accuracy']),
                    $this->fmt($bestBrier, 4),
                    $monthRows->count() < 50 ? 'Small sample' : 'Evaluate walk-forward stability',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function disagreementDeepDive(Collection $rows): array
    {
        $groups = [
            'Agree on winner' => fn (array $row): bool => $row['market_pick_side'] !== null && $row['market_pick_side'] === $row['model_pick_side'],
            'Model home / Market away' => fn (array $row): bool => $row['model_pick_side'] === 'home' && $row['market_pick_side'] === 'away',
            'Model away / Market home' => fn (array $row): bool => $row['model_pick_side'] === 'away' && $row['market_pick_side'] === 'home',
            'Spread gap 0-1' => fn (array $row): bool => $this->spreadGap($row) !== null && $this->spreadGap($row) < 1.0,
            'Spread gap 1-2' => fn (array $row): bool => $this->spreadGap($row) !== null && $this->spreadGap($row) >= 1.0 && $this->spreadGap($row) < 2.0,
            'Spread gap 2+' => fn (array $row): bool => $this->spreadGap($row) !== null && $this->spreadGap($row) >= 2.0,
            'Probability gap 0-2%' => fn (array $row): bool => $this->probabilityGap($row) !== null && $this->probabilityGap($row) < 0.02,
            'Probability gap 2-5%' => fn (array $row): bool => $this->probabilityGap($row) !== null && $this->probabilityGap($row) >= 0.02 && $this->probabilityGap($row) < 0.05,
            'Probability gap 5-10%' => fn (array $row): bool => $this->probabilityGap($row) !== null && $this->probabilityGap($row) >= 0.05 && $this->probabilityGap($row) < 0.10,
            'Probability gap 10%+' => fn (array $row): bool => $this->probabilityGap($row) !== null && $this->probabilityGap($row) >= 0.10,
        ];

        return collect($groups)
            ->map(function (callable $filter, string $bucket) use ($rows): array {
                $group = $rows->filter($filter)->values();
                $blend = $this->blendMetrics($group, 0.25);
                $model = $this->blendMetrics($group, 1.0);

                return [
                    $bucket,
                    (string) $group->count(),
                    $this->pct($model['accuracy']),
                    $this->pct($this->marketAccuracy($group)),
                    $this->pct($blend['accuracy']),
                    $this->fmt($model['brier'], 4),
                    $this->fmt($blend['brier'], 4),
                    $group->count() < 30 ? 'Small sample' : ($blend['brier'] !== null && $model['brier'] !== null && $blend['brier'] < $model['brier'] ? 'Blend improves Brier' : 'Needs review'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function candidateRuleComparison(Collection $rows): array
    {
        $rules = [
            'Pure model' => [
                'rows' => $rows,
                'weight' => 1.0,
                'notes' => 'Existing directional model only',
            ],
            'Market agreement only' => [
                'rows' => $rows->filter(fn (array $row): bool => $row['market_pick_side'] !== null && $row['market_pick_side'] === $row['model_pick_side'])->values(),
                'weight' => 1.0,
                'notes' => 'Model and market favorite agree',
            ],
            '25% model / 75% market' => [
                'rows' => $rows,
                'weight' => 0.25,
                'notes' => 'Market-heavy prediction benchmark',
            ],
            'Consensus + edge' => [
                'rows' => $rows->filter(fn (array $row): bool => $row['market_pick_side'] !== null
                    && $row['market_pick_side'] === $row['model_pick_side']
                    && $this->pitchersConfirmed($row)
                    && ! $this->hasMajorRiskFlags($row)
                    && (float) ($row['no_vig_edge'] ?? 0.0) > 0.0)->values(),
                'weight' => 0.25,
                'notes' => 'Strict research candidate only',
            ],
            'Disagreement suppressed' => [
                'rows' => $rows->filter(fn (array $row): bool => $row['market_pick_side'] === null || $row['market_pick_side'] === $row['model_pick_side'])->values(),
                'weight' => 0.25,
                'notes' => 'Disagreement rows tracking only',
            ],
        ];

        return collect($rules)
            ->map(function (array $definition, string $rule): array {
                /** @var Collection<int, array<string, mixed>> $ruleRows */
                $ruleRows = $definition['rows'];
                $metrics = $this->blendMetrics($ruleRows, (float) $definition['weight']);
                $avgEdge = $ruleRows->whereNotNull('no_vig_edge')->isNotEmpty()
                    ? round((float) $ruleRows->whereNotNull('no_vig_edge')->avg('no_vig_edge'), 4)
                    : null;

                return [
                    $rule,
                    (string) $ruleRows->count(),
                    $this->pct($metrics['accuracy']),
                    $this->fmt($metrics['brier'], 4),
                    $this->fmt($metrics['log_loss'], 4),
                    $this->fmt($avgEdge, 4),
                    (string) $definition['notes'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function totalBiasCorrectionGrid(Collection $rows): array
    {
        $totalRows = $rows->filter(fn (array $row): bool => $row['market_total'] !== null)->values();
        $marketMae = $totalRows->isNotEmpty()
            ? (float) $totalRows->map(fn (array $row): float => abs((float) $row['actual_total'] - (float) $row['market_total']))->avg()
            : null;

        $modelRows = collect([
            ['Current model', 0.0],
            ['Model -0.50', 0.50],
            ['Model -0.75', 0.75],
            ['Model -1.00', 1.00],
            ['Model -1.25', 1.25],
            ['Model -1.50', 1.50],
        ])->map(function (array $definition) use ($totalRows, $marketMae): array {
            [$label, $subtract] = $definition;
            $mae = $totalRows->isNotEmpty()
                ? (float) $totalRows->map(fn (array $row): float => abs((float) $row['actual_total'] - ((float) $row['predicted_total'] - (float) $subtract)))->avg()
                : null;
            $bias = $totalRows->isNotEmpty()
                ? (float) $totalRows->map(fn (array $row): float => ((float) $row['predicted_total'] - (float) $subtract) - (float) $row['actual_total'])->avg()
                : null;

            return [
                (string) $label,
                (string) $totalRows->count(),
                $this->fmt($mae, 2),
                $this->signed($bias, 2),
                $marketMae !== null && $mae !== null && $mae < $marketMae ? 'yes' : 'no',
                'Report-only total correction',
            ];
        });

        $marketRow = [[
            'Market total',
            (string) $totalRows->count(),
            $this->fmt($marketMae, 2),
            $totalRows->isNotEmpty() ? $this->signed((float) $totalRows->map(fn (array $row): float => (float) $row['market_total'] - (float) $row['actual_total'])->avg(), 2) : 'n/a',
            'baseline',
            'Market benchmark',
        ]];

        return $modelRows->merge($marketRow)->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array<int, array<int, string>>>
     */
    private function totalBiasBreakdowns(Collection $rows): array
    {
        $totalRows = $rows->filter(fn (array $row): bool => $row['market_total'] !== null)->values();

        return [
            'month' => $this->totalBreakdown($totalRows, fn (array $row): string => (string) $row['month']),
            'park_adjustment_bucket' => $this->totalBreakdown($totalRows, fn (array $row): string => $this->adjustmentBucket((float) $row['park_adjustment'], 'park')),
            'weather_adjustment_bucket' => $this->totalBreakdown($totalRows, fn (array $row): string => $this->adjustmentBucket((float) $row['weather_adjustment'], 'weather')),
            'predicted_total_bucket' => $this->totalBreakdown($totalRows, fn (array $row): string => $this->totalBucket((float) $row['predicted_total'])),
            'market_total_gap_bucket' => $this->totalBreakdown($totalRows, fn (array $row): string => $this->gapBucket(abs((float) $row['predicted_total'] - (float) $row['market_total']))),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): string  $groupBy
     * @return array<int, array<int, string>>
     */
    private function totalBreakdown(Collection $rows, callable $groupBy): array
    {
        return $rows
            ->groupBy($groupBy)
            ->map(function (Collection $group, string $bucket): array {
                $mae = (float) $group->map(fn (array $row): float => abs((float) $row['actual_total'] - (float) $row['predicted_total']))->avg();
                $bias = (float) $group->map(fn (array $row): float => (float) $row['predicted_total'] - (float) $row['actual_total'])->avg();

                return [$bucket, (string) $group->count(), $this->fmt($mae, 2), $this->signed($bias, 2)];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function confidenceResearch(Collection $rows): array
    {
        $versions = [
            'Current model confidence' => fn (array $row): float => (float) $row['confidence_score'],
            'Market probability confidence' => fn (array $row): float => $this->confidenceFromProbability((float) $row['market_home_probability']),
            'Market-aware blended confidence' => fn (array $row): float => $this->confidenceFromProbability($this->blendHomeProbability($row, 0.25)),
            'Agreement-adjusted confidence' => fn (array $row): float => $row['market_pick_side'] === $row['model_pick_side']
                ? $this->confidenceFromProbability($this->blendHomeProbability($row, 0.25))
                : min(52.0, $this->confidenceFromProbability($this->blendHomeProbability($row, 0.25))),
        ];

        return collect($versions)
            ->map(function (callable $confidenceResolver, string $version) use ($rows): array {
                $values = $rows->map($confidenceResolver)->values();
                $modelWeight = $version === 'Current model confidence' ? 1.0 : 0.25;
                $bucketRows = $this->confidenceBuckets($rows, $confidenceResolver, $modelWeight);

                return [
                    $version,
                    $values->isEmpty() ? 'n/a' : number_format((float) $values->min(), 1).' - '.number_format((float) $values->max(), 1),
                    (string) count($bucketRows),
                    $this->pct($this->blendMetrics($rows, $modelWeight)['accuracy']),
                    $this->fmt($this->blendMetrics($rows, $modelWeight)['brier'], 4),
                    $this->confidenceMonotonicity($bucketRows) ? 'monotonic' : 'not monotonic',
                    $rows->count() < 100 ? 'Small sample' : 'Research only',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): float  $confidenceResolver
     * @return array<int, array{bucket:string,accuracy:?float}>
     */
    private function confidenceBuckets(Collection $rows, callable $confidenceResolver, float $modelWeight): array
    {
        return $rows
            ->groupBy(function (array $row) use ($confidenceResolver): string {
                $confidence = $confidenceResolver($row);

                return match (true) {
                    $confidence < 52.5 => '50-52.4',
                    $confidence < 55.0 => '52.5-54.9',
                    $confidence < 57.5 => '55-57.4',
                    default => '57.5+',
                };
            })
            ->map(fn (Collection $group, string $bucket): array => [
                'bucket' => $bucket,
                'accuracy' => $this->blendMetrics($group->values(), $modelWeight)['accuracy'],
            ])
            ->sortBy('bucket')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{bucket:string,accuracy:?float}>  $bucketRows
     */
    private function confidenceMonotonicity(array $bucketRows): bool
    {
        $previous = null;

        foreach ($bucketRows as $row) {
            if ($row['accuracy'] === null) {
                continue;
            }

            if ($previous !== null && $row['accuracy'] < $previous) {
                return false;
            }

            $previous = $row['accuracy'];
        }

        return true;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{rows:int,accuracy:?float,brier:?float,log_loss:?float,calibration_gap:?float}
     */
    private function blendMetrics(Collection $rows, float $modelWeight): array
    {
        if ($rows->isEmpty()) {
            return ['rows' => 0, 'accuracy' => null, 'brier' => null, 'log_loss' => null, 'calibration_gap' => null];
        }

        $scored = $rows->map(function (array $row) use ($modelWeight): array {
            $probability = $this->blendHomeProbability($row, $modelWeight);

            return [
                'pick_won' => $this->pickWon($this->sideFromHomeProbability($probability), (bool) $row['home_won']),
                'brier' => $this->brier($probability, (bool) $row['home_won']),
                'log_loss' => $this->logLoss($probability, (bool) $row['home_won']),
                'calibration_gap' => abs($probability - ((bool) $row['home_won'] ? 1.0 : 0.0)),
            ];
        });

        return [
            'rows' => $rows->count(),
            'accuracy' => $this->percent($scored->where('pick_won', true)->count(), $scored->count()),
            'brier' => round((float) $scored->avg('brier'), 4),
            'log_loss' => round((float) $scored->avg('log_loss'), 4),
            'calibration_gap' => round((float) $scored->avg('calibration_gap'), 4),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function marketAccuracy(Collection $rows): ?float
    {
        $marketRows = $rows->filter(fn (array $row): bool => $row['market_pick_won'] !== null)->values();

        return $marketRows->isEmpty() ? null : $this->percent($marketRows->where('market_pick_won', true)->count(), $marketRows->count());
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function blendHomeProbability(array $row, float $modelWeight): float
    {
        return max(0.0, min(1.0, ((float) $row['home_probability'] * $modelWeight) + ((float) $row['market_home_probability'] * (1 - $modelWeight))));
    }

    /**
     * @return list<string>
     */
    private function safetyFlags(Prediction $prediction): array
    {
        $game = $prediction->game;
        $flags = [];
        $prices = $this->extractH2hPrices($prediction);

        if ($prices['home'] === null || $prices['away'] === null) {
            $flags[] = 'missing_market_odds';
        }

        if ($game?->odds_updated_at === null) {
            $flags[] = 'missing_odds_timestamp';
        }

        $start = $this->gameStartAt($prediction);
        if ($start === null) {
            $flags[] = 'missing_game_start_time';
        }

        if ($game?->odds_updated_at !== null && $start !== null && $game->odds_updated_at->gt($start)) {
            $flags[] = 'odds_after_first_pitch';
        }

        if ($game?->odds_updated_at !== null && $this->oddsAreStale($game->odds_updated_at, $start)) {
            $flags[] = 'stale_odds';
        }

        if ($prediction->created_at === null) {
            $flags[] = 'missing_prediction_timestamp';
        }

        if ($prediction->live_win_probability !== null || $prediction->live_updated_at !== null) {
            $flags[] = 'live_only_row';
        }

        if (in_array((string) $game?->status, [config('mlb.statuses.postponed'), config('mlb.statuses.suspended'), config('mlb.statuses.canceled')], true)) {
            $flags[] = 'postponed_suspended_cancelled';
        }

        return array_values(array_unique($flags));
    }

    private function oddsAreStale(CarbonInterface $oddsUpdatedAt, ?Carbon $start): bool
    {
        $staleHours = (int) config('mlb.signals.odds_stale_hours', 12);

        if ($start !== null) {
            return $oddsUpdatedAt->lt($start->copy()->subHours($staleHours));
        }

        return $oddsUpdatedAt->lt(now()->subHours($staleHours));
    }

    private function gameStartAt(Prediction $prediction): ?Carbon
    {
        $game = $prediction->game;
        if (! $game?->game_date || ! $game->game_time) {
            return null;
        }

        return Carbon::parse($game->game_date->toDateString().' '.$game->game_time, config('app.timezone'));
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

        $homeTeam = $this->normalizeTeamName((string) $prediction->game?->homeTeam?->display_name ?: trim(((string) $prediction->game?->homeTeam?->location).' '.((string) $prediction->game?->homeTeam?->name)));
        $awayTeam = $this->normalizeTeamName((string) $prediction->game?->awayTeam?->display_name ?: trim(((string) $prediction->game?->awayTeam?->location).' '.((string) $prediction->game?->awayTeam?->name)));
        $prices = ['home' => null, 'away' => null];

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'h2h') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (! is_numeric($outcome['price'] ?? null)) {
                        continue;
                    }

                    $name = $this->normalizeTeamName((string) ($outcome['name'] ?? ''));
                    if ($name === $homeTeam || str_contains($homeTeam, $name) || str_contains($name, $homeTeam)) {
                        $prices['home'] = (int) $outcome['price'];
                    }
                    if ($name === $awayTeam || str_contains($awayTeam, $name) || str_contains($name, $awayTeam)) {
                        $prices['away'] = (int) $outcome['price'];
                    }
                }
            }
        }

        return $prices;
    }

    private function extractMarketTotal(Prediction $prediction): ?float
    {
        $metadataTotal = data_get($prediction->model_metadata, 'market_context.market_total');
        if (is_numeric($metadataTotal)) {
            return (float) $metadataTotal;
        }

        foreach (($prediction->game?->odds_data['bookmakers'] ?? []) as $bookmaker) {
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
     * @param  array{home:?float,away:?float}  $marketProbabilities
     * @param  array{home:?int,away:?int}  $prices
     */
    private function marketPickSide(array $marketProbabilities, array $prices): ?string
    {
        if ($marketProbabilities['home'] !== null && $marketProbabilities['away'] !== null) {
            if (abs((float) $marketProbabilities['home'] - (float) $marketProbabilities['away']) < 0.0001) {
                return null;
            }

            return $marketProbabilities['home'] >= $marketProbabilities['away'] ? 'home' : 'away';
        }

        if ($prices['home'] !== null && $prices['away'] !== null) {
            if ((int) $prices['home'] === (int) $prices['away']) {
                return null;
            }

            return $prices['home'] <= $prices['away'] ? 'home' : 'away';
        }

        return null;
    }

    private function sideFromHomeProbability(float $homeProbability): string
    {
        return $homeProbability >= 0.5 ? 'home' : 'away';
    }

    private function pickWon(string $side, bool $homeWon): bool
    {
        return $side === 'home' ? $homeWon : ! $homeWon;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function spreadGap(array $row): ?float
    {
        return $row['market_home_margin'] === null ? null : abs((float) $row['predicted_spread'] - (float) $row['market_home_margin']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function probabilityGap(array $row): ?float
    {
        return $row['market_home_probability'] === null ? null : abs((float) $row['home_probability'] - (float) $row['market_home_probability']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function pitchersConfirmed(array $row): bool
    {
        return $row['home_pitcher_source'] === 'probable_starter' && $row['away_pitcher_source'] === 'probable_starter';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasMajorRiskFlags(array $row): bool
    {
        return array_intersect((array) ($row['candidate_risk_flags'] ?? []), [
            'stale_odds',
            'missing_odds_timestamp',
            'model_market_disagreement_unvalidated',
            'moneyline_price_missing',
            'no_moneyline_market_value',
        ]) !== [];
    }

    private function confidenceFromProbability(float $probability): float
    {
        return round(50 + (abs($probability - 0.5) * 100), 2);
    }

    private function normalizeTeamName(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
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

    private function adjustmentBucket(float $value, string $prefix): string
    {
        if ($value >= 0.25) {
            return "{$prefix}_boost";
        }

        if ($value <= -0.25) {
            return "{$prefix}_suppress";
        }

        return "{$prefix}_neutral";
    }

    private function gapBucket(float $gap): string
    {
        return match (true) {
            $gap < 1.0 => '0-1',
            $gap < 2.0 => '1-2',
            default => '2+',
        };
    }

    private function percent(int $part, int $total): float
    {
        return round($part / max(1, $total) * 100, 1);
    }

    private function pct(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value, 1).'%';
    }

    private function fmt(?float $value, int $decimals): string
    {
        return $value === null ? 'n/a' : number_format($value, $decimals);
    }

    private function signed(?float $value, int $decimals): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return ($value >= 0 ? '+' : '').number_format($value, $decimals);
    }

    private function brier(float $probability, bool $homeWon): float
    {
        return ($probability - ($homeWon ? 1.0 : 0.0)) ** 2;
    }

    private function logLoss(float $probability, bool $homeWon): float
    {
        $probability = min(0.999, max(0.001, $probability));

        return -log($homeWon ? $probability : 1 - $probability);
    }
}
