<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Prediction;
use App\Services\MLB\MlbPredictionRecommendationService;
use App\Support\Odds\AmericanOdds;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ValidateRecommendationReadinessCommand extends Command
{
    protected $signature = 'mlb:validate-recommendation-readiness
        {--season= : Filter by season}
        {--feature-version=core-v3 : Filter to a single feature_version (use "any" to include all)}
        {--limit=2500 : Limit number of most recent graded predictions to inspect}
        {--from= : Start game date in YYYY-MM-DD}
        {--to= : End game date in YYYY-MM-DD}
        {--min-rows= : Override configured minimum graded row count}
        {--strict-pregame : Require pregame-safe market metadata}
        {--json : Output structured JSON}';

    protected $description = 'Validate whether MLB candidate recommendations are ready for public promotion.';

    public function handle(MlbPredictionRecommendationService $recommendations): int
    {
        $rows = $this->loadRows($recommendations);
        $report = $this->buildReport($rows);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('MLB Recommendation Readiness');
        $this->line('Status: '.$report['status'].' | Ready: '.($report['ready'] ? 'yes' : 'no'));
        $this->line('Rows: '.$report['summary']['rows'].' | Candidate rows: '.$report['summary']['candidate_rows']);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Model winner accuracy', $this->pct($report['summary']['model_winner_accuracy'])],
                ['Home baseline', $this->pct($report['summary']['home_baseline_accuracy'])],
                ['Market baseline', $this->nullablePct($report['summary']['market_baseline_accuracy'])],
                ['Candidate winner accuracy', $this->nullablePct($report['summary']['candidate_winner_accuracy'])],
                ['Public promoted rows', (string) $report['summary']['public_promoted_rows']],
                ['Total bias vs market', $this->signedNullable($report['summary']['total_bias_vs_market'])],
                ['Confidence std dev', $this->nullableNumber($report['summary']['confidence_std_dev'])],
            ]
        );

        if ($report['block_reasons'] !== []) {
            $this->newLine();
            $this->warn('Promotion is blocked');
            foreach ($report['block_reasons'] as $reason) {
                $this->line(' - '.$reason);
            }
        }

        $this->newLine();
        $this->info('Candidate Buckets');
        $this->table(['Bucket', 'Rows', 'Winner %'], $report['candidate_buckets']);

        if ($report['candidate_samples'] !== []) {
            $this->newLine();
            $this->info('Candidate Samples');
            $this->table(
                ['Prediction', 'Game', 'Date', 'Type', 'Model', 'Market', 'Won', 'Score', 'Reasons', 'Risks'],
                $report['candidate_samples']
            );
        }

        $this->newLine();
        $this->info('Market Agreement');
        $this->table(['Bucket', 'Rows', 'Model %', 'Market %'], $report['market_agreement']);

        $this->newLine();
        $this->info('Probability Shrinkage Research');
        $this->table(['Model Weight', 'Rows', 'Winner %', 'Brier', 'Log Loss'], $report['probability_shrinkage']);

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

                if ($this->option('strict-pregame') && ! (bool) data_get($prediction->model_metadata, 'market_context.safety.pregame_safe', false)) {
                    return null;
                }

                $actualMargin = (float) $game->home_score - (float) $game->away_score;
                $homeWon = $actualMargin > 0;
                $homeWinProbability = (float) $prediction->win_probability;
                $modelPickSide = $homeWinProbability >= 0.5 ? 'home' : 'away';
                $h2hPrices = $this->extractH2hPrices($prediction);
                $marketProbabilities = AmericanOdds::noVigProbabilities($h2hPrices['home'], $h2hPrices['away']);
                $marketPickSide = $this->marketPickSide($marketProbabilities, $h2hPrices);
                $marketTotal = $this->extractMarketTotal($prediction);
                $recommendation = $recommendations->forPrediction($prediction);
                $public = (array) ($recommendation['public'] ?? $recommendation);
                $candidate = (array) ($recommendation['candidate'] ?? $recommendation['pregame_recommendation'] ?? $recommendation);
                $promotion = (array) ($recommendation['promotion'] ?? []);

                return [
                    'prediction_id' => (int) $prediction->id,
                    'game_id' => (int) $game->id,
                    'game' => (string) ($game->short_name ?: $game->name ?: ''),
                    'game_date' => $game->game_date?->toDateString(),
                    'home_won' => $homeWon,
                    'winner_correct' => (bool) $prediction->winner_correct,
                    'home_win_probability' => $homeWinProbability,
                    'model_pick_side' => $modelPickSide,
                    'market_home_probability' => $marketProbabilities['home'],
                    'market_pick_side' => $marketPickSide,
                    'market_pick_won' => $marketPickSide === null ? null : ($marketPickSide === 'home' ? $homeWon : ! $homeWon),
                    'market_total' => $marketTotal,
                    'predicted_total' => (float) $prediction->predicted_total,
                    'confidence_score' => (float) $prediction->confidence_score,
                    'candidate_recommendation_type' => (string) ($candidate['recommendation_type'] ?? 'no_play'),
                    'candidate_score' => $candidate['score'] ?? null,
                    'candidate_no_bet_reason' => $candidate['no_bet_reason'] ?? null,
                    'candidate_reason_codes' => array_values((array) ($candidate['reason_codes'] ?? [])),
                    'candidate_risk_flags' => array_values((array) ($candidate['risk_flags'] ?? [])),
                    'public_recommendation_type' => (string) ($public['recommendation_type'] ?? 'no_play'),
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
            ->whereNotNull('predicted_total')
            ->whereNotNull('win_probability')
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
        $candidateRows = $rows->filter(fn (array $row): bool => in_array($row['candidate_recommendation_type'], ['bet', 'lean'], true))->values();
        $publicRows = $rows->filter(fn (array $row): bool => in_array($row['public_recommendation_type'], ['bet', 'lean'], true))->values();
        $marketRows = $rows->filter(fn (array $row): bool => $row['market_pick_won'] !== null)->values();
        $marketTotalRows = $rows->filter(fn (array $row): bool => $row['market_total'] !== null)->values();
        $confidenceStdDev = $this->stdDev($rows->pluck('confidence_score')->map(fn (mixed $value): float => (float) $value));

        $summary = [
            'rows' => $rows->count(),
            'candidate_rows' => $candidateRows->count(),
            'public_promoted_rows' => $publicRows->count(),
            'model_winner_accuracy' => $this->accuracy($rows, 'winner_correct'),
            'home_baseline_accuracy' => $rows->isNotEmpty() ? $this->percent($rows->where('home_won', true)->count(), $rows->count()) : null,
            'market_baseline_accuracy' => $marketRows->isNotEmpty() ? $this->percent($marketRows->where('market_pick_won', true)->count(), $marketRows->count()) : null,
            'candidate_winner_accuracy' => $candidateRows->isNotEmpty() ? $this->accuracy($candidateRows, 'winner_correct') : null,
            'total_bias_vs_market' => $marketTotalRows->isNotEmpty()
                ? round((float) $marketTotalRows->map(fn (array $row): float => (float) $row['predicted_total'] - (float) $row['market_total'])->avg(), 2)
                : null,
            'confidence_std_dev' => $confidenceStdDev,
        ];

        $blockReasons = $this->blockReasons($summary, $rows, $candidateRows);

        return [
            'report_type' => 'mlb_recommendation_readiness',
            'season' => $this->option('season') ? (int) $this->option('season') : null,
            'feature_version' => (string) $this->option('feature-version'),
            'strict_pregame' => (bool) $this->option('strict-pregame'),
            'generated_at' => now()->toIso8601String(),
            'ready' => $blockReasons === [],
            'status' => $blockReasons === [] ? 'pass' : 'fail',
            'summary' => $summary,
            'block_reasons' => $blockReasons,
            'candidate_buckets' => $this->bucketTable($rows, 'candidate_recommendation_type'),
            'candidate_samples' => $this->candidateSamples($candidateRows),
            'public_buckets' => $this->bucketTable($rows, 'public_recommendation_type'),
            'market_agreement' => $this->marketAgreementTable($rows),
            'probability_shrinkage' => $this->probabilityShrinkageTable($rows),
            'total_bias_research' => $this->totalBiasResearch($marketTotalRows),
            'promotion_block_reason_counts' => $this->promotionBlockReasonCounts($rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, array<string, mixed>>  $candidateRows
     * @return list<string>
     */
    private function blockReasons(array $summary, Collection $rows, Collection $candidateRows): array
    {
        $minRows = (int) ($this->option('min-rows') ?: config('mlb.signals.recommendation_readiness.min_rows', 1000));
        $minCandidateRows = (int) config('mlb.signals.recommendation_readiness.min_candidate_rows', 50);
        $minCandidateAccuracy = (float) config('mlb.signals.recommendation_readiness.min_candidate_accuracy', 52.5);
        $maxTotalBias = (float) config('mlb.signals.recommendation_readiness.max_total_bias_vs_market', 0.5);
        $minConfidenceStdDev = (float) config('mlb.signals.recommendation_readiness.min_confidence_std_dev', 2.0);
        $reasons = [];

        if ((int) $summary['rows'] < $minRows) {
            $reasons[] = 'graded_sample_below_minimum';
        }

        if ((int) $summary['candidate_rows'] < $minCandidateRows) {
            $reasons[] = 'candidate_sample_below_minimum';
        }

        if ($summary['model_winner_accuracy'] !== null && $summary['home_baseline_accuracy'] !== null && $summary['model_winner_accuracy'] <= $summary['home_baseline_accuracy']) {
            $reasons[] = 'model_underperforms_home_baseline';
        }

        if ($summary['model_winner_accuracy'] !== null && $summary['market_baseline_accuracy'] !== null && $summary['model_winner_accuracy'] < $summary['market_baseline_accuracy']) {
            $reasons[] = 'model_underperforms_market_baseline';
        }

        if ($summary['candidate_winner_accuracy'] === null || $summary['candidate_winner_accuracy'] < $minCandidateAccuracy) {
            $reasons[] = 'candidate_bucket_underperforms_threshold';
        }

        if ($summary['total_bias_vs_market'] !== null && abs((float) $summary['total_bias_vs_market']) > $maxTotalBias) {
            $reasons[] = 'total_bias_vs_market_too_high';
        }

        if ($summary['confidence_std_dev'] !== null && (float) $summary['confidence_std_dev'] < $minConfidenceStdDev) {
            $reasons[] = 'confidence_distribution_too_compressed';
        }

        $disagreementRows = $rows->filter(fn (array $row): bool => $row['market_pick_side'] !== null && $row['market_pick_side'] !== $row['model_pick_side'])->values();
        if ($disagreementRows->isNotEmpty() && $this->accuracy($disagreementRows, 'winner_correct') < 50.0) {
            $reasons[] = 'model_market_disagreement_underperforms';
        }

        if ($candidateRows->isNotEmpty()) {
            $noPlayRows = $rows->where('candidate_recommendation_type', 'no_play')->values();
            if ($noPlayRows->isNotEmpty() && $this->accuracy($candidateRows, 'winner_correct') <= $this->accuracy($noPlayRows, 'winner_correct')) {
                $reasons[] = 'candidate_bucket_not_better_than_no_play';
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function bucketTable(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (array $row): string => (string) ($row[$field] ?? 'no_play'))
            ->map(fn (Collection $group, string $bucket): array => [
                $bucket,
                (string) $group->count(),
                $this->pct($this->accuracy($group->values(), 'winner_correct')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidateRows
     * @return array<int, array<int, string>>
     */
    private function candidateSamples(Collection $candidateRows): array
    {
        return $candidateRows
            ->take(10)
            ->map(fn (array $row): array => [
                (string) $row['prediction_id'],
                $row['game_id'].' '.$row['game'],
                (string) ($row['game_date'] ?? ''),
                (string) $row['candidate_recommendation_type'],
                (string) $row['model_pick_side'],
                (string) ($row['market_pick_side'] ?? 'none'),
                $row['winner_correct'] ? 'yes' : 'no',
                (string) ($row['candidate_score'] ?? ''),
                implode(',', array_slice((array) ($row['candidate_reason_codes'] ?? []), 0, 4)),
                implode(',', array_slice((array) ($row['candidate_risk_flags'] ?? []), 0, 4)),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function marketAgreementTable(Collection $rows): array
    {
        return $rows
            ->filter(fn (array $row): bool => $row['market_pick_side'] !== null)
            ->groupBy(fn (array $row): string => $row['market_pick_side'] === $row['model_pick_side'] ? 'model_market_agree' : "model_{$row['model_pick_side']}_market_{$row['market_pick_side']}")
            ->map(fn (Collection $group, string $bucket): array => [
                $bucket,
                (string) $group->count(),
                $this->pct($this->accuracy($group->values(), 'winner_correct')),
                $this->pct($this->percent($group->where('market_pick_won', true)->count(), $group->count())),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function probabilityShrinkageTable(Collection $rows): array
    {
        $marketRows = $rows->filter(fn (array $row): bool => $row['market_home_probability'] !== null)->values();

        return collect([1.0, 0.75, 0.5, 0.25])
            ->map(function (float $modelWeight) use ($marketRows): array {
                $scored = $marketRows->map(function (array $row) use ($modelWeight): array {
                    $probability = ((float) $row['home_win_probability'] * $modelWeight) + ((float) $row['market_home_probability'] * (1 - $modelWeight));

                    return [
                        'pick_won' => $probability >= 0.5 ? (bool) $row['home_won'] : ! (bool) $row['home_won'],
                        'brier' => $this->brier($probability, (bool) $row['home_won']),
                        'log_loss' => $this->logLoss($probability, (bool) $row['home_won']),
                    ];
                });

                return [
                    number_format($modelWeight, 2),
                    (string) $scored->count(),
                    $scored->isNotEmpty() ? $this->pct($this->percent($scored->where('pick_won', true)->count(), $scored->count())) : 'n/a',
                    $scored->isNotEmpty() ? number_format((float) $scored->avg('brier'), 4) : 'n/a',
                    $scored->isNotEmpty() ? number_format((float) $scored->avg('log_loss'), 4) : 'n/a',
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function totalBiasResearch(Collection $rows): array
    {
        return collect([0.0, 0.5, 1.0, 1.25, 1.5])
            ->map(fn (float $shrink): array => [
                'subtract_runs' => $shrink,
                'rows' => $rows->count(),
                'bias_vs_market' => $rows->isNotEmpty()
                    ? round((float) $rows->map(fn (array $row): float => ((float) $row['predicted_total'] - $shrink) - (float) $row['market_total'])->avg(), 2)
                    : null,
            ])
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function promotionBlockReasonCounts(Collection $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['promotion_block_reasons'] ?? []) as $reason) {
                $reason = (string) $reason;
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
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

    private function normalizeTeamName(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    private function accuracy(Collection $rows, string $field): ?float
    {
        return $rows->isEmpty() ? null : $this->percent($rows->where($field, true)->count(), $rows->count());
    }

    private function percent(int $part, int $total): float
    {
        return round($part / max(1, $total) * 100, 1);
    }

    private function pct(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value, 1).'%';
    }

    private function nullablePct(?float $value): string
    {
        return $this->pct($value);
    }

    private function nullableNumber(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value, 2);
    }

    private function signedNullable(?float $value): string
    {
        return $value === null ? 'n/a' : ($value >= 0 ? '+' : '').number_format($value, 2);
    }

    /**
     * @param  Collection<int, float>  $values
     */
    private function stdDev(Collection $values): ?float
    {
        if ($values->isEmpty()) {
            return null;
        }

        $mean = (float) $values->avg();

        return round(sqrt((float) $values->map(fn (float $value): float => ($value - $mean) ** 2)->avg()), 2);
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
