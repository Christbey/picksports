<?php

namespace App\Console\Commands\Sports;

use App\Models\BetDecision;
use App\Models\MarketQuote;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RecordShadowBetDecisionsCommand extends Command
{
    protected $signature = 'sports:record-shadow-bet-decisions
        {--sport=nba : Sport to process}
        {--artifact= : Restrict to one artifact UUID}
        {--minimum-edge=0.03 : Edge required after promotion}
        {--limit=0 : Optional output limit}';

    protected $description = 'Write challenger or promoted shadow outputs into immutable tracking decisions';

    public function handle(): int
    {
        $sport = strtolower((string) $this->option('sport'));
        $query = ShadowModelOutput::query()
            ->with(['artifact', 'featureSnapshot'])
            ->where('sport', $sport)
            ->when($this->option('artifact'), fn ($builder) => $builder->where('model_artifact_id', $this->option('artifact')))
            ->orderBy('id');

        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $created = 0;
        foreach ($query->get() as $shadow) {
            $snapshot = $shadow->featureSnapshot;
            $artifact = $shadow->artifact;
            $market = $this->marketDefinition($sport, $shadow->market_type);
            if (! $snapshot || ! $artifact || $market === null) {
                continue;
            }

            $referenceProbability = $this->referenceProbability($shadow, $market['observation_market']);
            $side = $this->side($market['observation_market'], $referenceProbability);
            $modelProbability = $referenceProbability === null
                ? null
                : $this->sideProbability($market['observation_market'], $side, $referenceProbability);
            $marketReference = $this->modelMarketReference(
                $snapshot,
                $market['observation_market'],
                $side,
            );
            $quoteSelection = $this->entryQuote(
                $sport,
                $shadow,
                $market['market_key'],
                $side,
                $snapshot->game_start_at,
                $marketReference,
            );
            $quote = $quoteSelection['quote'];
            $marketProbability = $this->probability($quote?->no_vig_probability);
            $edge = $modelProbability === null || $marketProbability === null
                ? null
                : $modelProbability - $marketProbability;
            $periodMoneyline = $this->isPeriodMoneyline($market['observation_market']);
            $periodProbabilities = $this->periodOutcomeProbabilities($shadow, $side);
            $projectedValue = $periodMoneyline
                ? ($periodProbabilities === null ? null : $this->expectedValue(
                    $periodProbabilities['win'],
                    $periodProbabilities['loss'],
                    is_numeric($quote?->price) ? (int) $quote->price : null,
                ))
                : $edge;
            $minimumEdge = max(0.0, (float) $this->option('minimum-edge'));
            $artifactPromotedAtDecisionTime = $artifact->status === 'promoted'
                && $artifact->promoted_at !== null
                && $artifact->promoted_at->lessThanOrEqualTo($shadow->generated_at);
            $recordedMarketPromotion = $this->recordedMarketPromotion(
                $shadow,
                $market['observation_market'],
            );
            $marketPromotedAtDecisionTime = $artifactPromotedAtDecisionTime
                && $artifact->isPromotedForMarket($market['observation_market'])
                && $recordedMarketPromotion !== false;
            $observedPregame = $snapshot->availability_status === 'observed_pregame';
            $featuresBeforeStart = $snapshot->game_start_at !== null
                && $snapshot->generated_at->lessThan($snapshot->game_start_at)
                && ($snapshot->features_available_at === null
                    || $snapshot->features_available_at->lessThanOrEqualTo($snapshot->game_start_at));
            $pregameSafe = (bool) $snapshot->pregame_safe
                && $observedPregame
                && $featuresBeforeStart
                && (bool) $quote?->is_pregame;
            $uncertainty = $this->probability(
                data_get($shadow->explanation, 'challenger_outputs.uncertainty')
                    ?? data_get($snapshot->outputs, 'challenger_uncertainty')
                    ?? data_get($snapshot->model_metadata, 'shadow_inference.challenger_outputs.uncertainty'),
            );
            $maximumUncertainty = $this->isPeriodMoneyline($market['observation_market'])
                ? config('mlb_ml.period_models.maximum_uncertainty')
                : config("{$sport}_ml.shadow.max_uncertainty");
            $maximumUncertainty = is_numeric($maximumUncertainty)
                ? max(0.0, (float) $maximumUncertainty)
                : null;
            $uncertaintyRequired = (bool) data_get($shadow->explanation, 'multi_market_contract', false);
            $uncertaintyEligible = (! $uncertaintyRequired || $uncertainty !== null)
                && ($maximumUncertainty === null
                    || ($uncertainty !== null && $uncertainty <= $maximumUncertainty));
            $isBet = $marketPromotedAtDecisionTime
                && $pregameSafe
                && $modelProbability !== null
                && $edge !== null
                && $edge >= $minimumEdge
                && (! $periodMoneyline || ($projectedValue !== null && $projectedValue > 0))
                && $uncertaintyEligible;
            $eligibilityReasons = array_values(array_filter([
                $artifactPromotedAtDecisionTime ? null : 'artifact_not_promoted_at_decision_time',
                $artifactPromotedAtDecisionTime
                    && ! $marketPromotedAtDecisionTime
                    ? 'market_model_not_promoted_at_decision_time'
                    : null,
                $snapshot->pregame_safe ? null : 'feature_snapshot_not_pregame_safe',
                $observedPregame ? null : 'historical_reconstruction_not_bet_eligible',
                $featuresBeforeStart ? null : 'feature_snapshot_not_before_game_start',
                $quote ? null : 'pregame_market_quote_missing',
                $quoteSelection['failure_reason'],
                $quote && ! $quote->is_pregame ? 'market_quote_not_pregame' : null,
                $modelProbability !== null ? null : 'model_probability_missing',
                $quote && $marketProbability === null ? 'market_probability_missing' : null,
                $uncertaintyRequired && $uncertainty === null ? 'model_uncertainty_missing' : null,
                $maximumUncertainty !== null
                    && $uncertainty !== null
                    && $uncertainty > $maximumUncertainty
                    ? 'model_uncertainty_above_threshold'
                    : null,
                $edge !== null && $edge < $minimumEdge ? 'edge_below_threshold' : null,
                $periodMoneyline
                    && $quote
                    && ($projectedValue === null || $projectedValue <= 0)
                    ? 'expected_value_nonpositive'
                    : null,
            ]));
            $decisionHash = hash('sha256', implode('|', [
                $artifact->id,
                $shadow->id,
                $market['market_type'],
                $market['market_key'],
                $side,
            ]));

            $decision = BetDecision::query()->firstOrCreate(
                ['decision_hash' => $decisionHash],
                [
                    'decision_run_id' => (string) Str::uuid(),
                    'model_run_id' => $shadow->inference_run_id,
                    'model_artifact_id' => $artifact->id,
                    'shadow_model_output_id' => $shadow->id,
                    'prediction_feature_snapshot_id' => $snapshot->id,
                    'game_odds_snapshot_id' => $quote?->game_odds_snapshot_id,
                    'source_table' => 'shadow_model_outputs',
                    'source_id' => $shadow->id,
                    'sport' => $sport,
                    'game_table' => $shadow->game_table,
                    'game_id' => $shadow->game_id,
                    'prediction_table' => $shadow->prediction_table,
                    'prediction_id' => $shadow->prediction_id,
                    'market_type' => $market['market_type'],
                    'market_key' => $market['market_key'],
                    'side' => $side,
                    'line' => $quote?->line,
                    'price' => $quote?->price,
                    'bookmaker' => $quote?->bookmaker_key,
                    'market_probability' => $quote?->implied_probability,
                    'no_vig_probability' => $quote?->no_vig_probability,
                    'model_probability' => $modelProbability,
                    'blend_probability' => $modelProbability,
                    'edge' => $edge,
                    'projected_value' => $projectedValue,
                    'confidence' => $modelProbability === null ? null : abs($modelProbability - 0.5) * 2,
                    'status' => $isBet ? 'tracking_bet' : 'shadow_no_bet',
                    'recommendation_label' => $isBet ? 'shadow_bet' : 'no_bet',
                    'is_public' => false,
                    'is_tracking_only' => true,
                    'is_bet' => $isBet,
                    'pregame_safe' => $pregameSafe,
                    'eligibility_reasons' => $eligibilityReasons,
                    'risk_flags' => [],
                    'reason_codes' => $isBet
                        ? [$periodMoneyline ? 'promoted_model_positive_ev' : 'promoted_model_edge']
                        : ['shadow_model_observation'],
                    'explanation' => [
                        'decision' => $isBet ? 'bet' : 'no_bet',
                        'observation_market' => $market['observation_market'],
                        'market_display_type' => $market['display_type'],
                        'artifact_status' => $artifact->status,
                        'artifact_promoted_at' => $artifact->promoted_at?->toIso8601String(),
                        'artifact_promoted_at_decision_time' => $artifactPromotedAtDecisionTime,
                        'recorded_market_promotion' => $recordedMarketPromotion,
                        'market_promoted_at_decision_time' => $marketPromotedAtDecisionTime,
                        'model_probability' => $modelProbability,
                        'market_probability' => $marketProbability,
                        'edge' => $edge,
                        'expected_value' => $projectedValue,
                        'win_probability' => $periodProbabilities['win'] ?? null,
                        'loss_probability' => $periodProbabilities['loss'] ?? null,
                        'tie_push_probability' => $periodProbabilities['tie'] ?? null,
                        'minimum_edge' => $minimumEdge,
                        'model_market_reference_line' => $marketReference['line'],
                        'model_market_reference_bookmaker' => $marketReference['bookmaker'],
                        'market_line_match_tolerance' => $marketReference['line'] === null
                            ? null
                            : 0.001,
                        'model_uncertainty' => $uncertainty,
                        'maximum_model_uncertainty' => $maximumUncertainty,
                        'uncertainty_gate_enabled' => $uncertaintyRequired || $maximumUncertainty !== null,
                        'why_not_bet' => $eligibilityReasons,
                    ],
                    'feature_snapshot' => [
                        'snapshot_run_id' => $snapshot->snapshot_run_id,
                        'feature_hash' => $snapshot->feature_hash,
                        'features_available_at' => $snapshot->features_available_at?->toIso8601String(),
                        'availability_status' => $snapshot->availability_status,
                    ],
                    'market_snapshot' => $quote?->toArray(),
                    'decided_at' => $shadow->generated_at,
                    'locked_at' => $shadow->generated_at,
                    'game_start_at' => $snapshot->game_start_at,
                ],
            );

            $created += $decision->wasRecentlyCreated ? 1 : 0;
        }

        $this->info("Recorded {$created} new immutable shadow decision(s).");

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     observation_market: string,
     *     market_type: string,
     *     display_type: string,
     *     market_key: string
     * }|null
     */
    private function marketDefinition(string $sport, string $marketType): ?array
    {
        $normalized = match (ModelArtifact::normalizeMarketType($marketType)) {
            'run_line' => 'spread',
            default => ModelArtifact::normalizeMarketType($marketType),
        };

        return match ($normalized) {
            'win_probability' => [
                'observation_market' => 'win_probability',
                'market_type' => 'moneyline',
                'display_type' => 'moneyline',
                'market_key' => 'h2h',
            ],
            'spread' => [
                'observation_market' => 'spread',
                'market_type' => 'spread',
                'display_type' => $sport === 'mlb' ? 'run_line' : 'spread',
                'market_key' => 'spreads',
            ],
            'total' => [
                'observation_market' => 'total',
                'market_type' => 'total',
                'display_type' => 'total',
                'market_key' => 'totals',
            ],
            'first_3_moneyline' => [
                'observation_market' => 'first_3_moneyline',
                'market_type' => 'first_3_moneyline',
                'display_type' => 'first_3_moneyline',
                'market_key' => 'h2h_1st_3_innings',
            ],
            'first_5_moneyline' => [
                'observation_market' => 'first_5_moneyline',
                'market_type' => 'first_5_moneyline',
                'display_type' => 'first_5_moneyline',
                'market_key' => 'h2h_1st_5_innings',
            ],
            default => null,
        };
    }

    private function referenceProbability(ShadowModelOutput $shadow, string $marketType): ?float
    {
        $value = match ($marketType) {
            'win_probability' => data_get($shadow->explanation, 'challenger_outputs.win_probability')
                ?? $shadow->challenger_output,
            'spread' => data_get($shadow->explanation, 'challenger_outputs.home_cover_probability'),
            'total' => data_get($shadow->explanation, 'challenger_outputs.over_probability'),
            'first_3_moneyline', 'first_5_moneyline' => data_get(
                $shadow->explanation,
                'challenger_outputs.conditional_home_win_probability',
            ) ?? $shadow->challenger_output,
            default => null,
        };

        return $this->probability($value);
    }

    private function side(string $marketType, ?float $probability): string
    {
        return match ($marketType) {
            'total' => $probability !== null && $probability < 0.5 ? 'under' : 'over',
            default => $probability !== null && $probability < 0.5 ? 'away' : 'home',
        };
    }

    private function sideProbability(string $marketType, string $side, float $probability): float
    {
        $primarySide = $marketType === 'total' ? 'over' : 'home';

        return $side === $primarySide ? $probability : 1.0 - $probability;
    }

    /**
     * @param  array{line: ?float, bookmaker: ?string}  $marketReference
     * @return array{quote: ?MarketQuote, failure_reason: ?string}
     */
    private function entryQuote(
        string $sport,
        ShadowModelOutput $shadow,
        string $marketKey,
        string $side,
        mixed $gameStartAt,
        array $marketReference,
    ): array {
        $query = MarketQuote::query()
            ->where('sport', $sport)
            ->where('game_id', $shadow->game_id)
            ->where('market_key', $marketKey)
            ->where('side', $side)
            ->where('is_pregame', true)
            ->where('captured_at', '<=', $shadow->generated_at)
            ->when($gameStartAt, fn ($builder) => $builder->where('captured_at', '<=', $gameStartAt));

        if ($marketKey === 'h2h' || str_starts_with($marketKey, 'h2h_')) {
            return [
                'quote' => $query
                    ->latest('captured_at')
                    ->latest('id')
                    ->first(),
                'failure_reason' => null,
            ];
        }

        if ($marketReference['line'] === null) {
            return [
                'quote' => null,
                'failure_reason' => 'model_market_line_quote_missing',
            ];
        }

        $hasSideQuote = (clone $query)->exists();
        $matchingQuotes = (clone $query)->whereBetween('line', [
            $marketReference['line'] - 0.001,
            $marketReference['line'] + 0.001,
        ]);
        $quote = null;
        if ($marketReference['bookmaker'] !== null) {
            $bookmaker = $marketReference['bookmaker'];
            $quote = (clone $matchingQuotes)
                ->where(function ($builder) use ($bookmaker): void {
                    $builder->where('bookmaker_key', $bookmaker)
                        ->orWhere('bookmaker_title', $bookmaker);
                })
                ->latest('captured_at')
                ->latest('id')
                ->first();
        }
        $quote ??= $matchingQuotes
            ->latest('captured_at')
            ->latest('id')
            ->first();

        return [
            'quote' => $quote,
            'failure_reason' => $quote !== null
                ? null
                : ($hasSideQuote ? 'model_market_line_mismatch' : 'model_market_line_quote_missing'),
        ];
    }

    /**
     * @return array{line: ?float, bookmaker: ?string}
     */
    private function modelMarketReference(
        PredictionFeatureSnapshot $snapshot,
        string $marketType,
        string $side,
    ): array {
        $bookmaker = $this->stringValue(
            data_get($snapshot->market_context, 'bookmaker')
                ?? data_get($snapshot->market_context, 'bookmaker_key')
                ?? data_get($snapshot->market_context, 'safety.bookmaker'),
        );

        if ($marketType === 'total') {
            return [
                'line' => $this->firstNumber([
                    data_get($snapshot->features, 'feature_market_total'),
                    data_get($snapshot->features, 'market__total'),
                    data_get($snapshot->features, 'market_total'),
                    data_get($snapshot->market_context, 'market_total'),
                    data_get($snapshot->outputs, 'market_total'),
                ]),
                'bookmaker' => $bookmaker,
            ];
        }

        if ($marketType !== 'spread') {
            return ['line' => null, 'bookmaker' => $bookmaker];
        }

        $homeMargin = $this->firstNumber([
            data_get($snapshot->features, 'feature_market_home_spread'),
            data_get($snapshot->features, 'market__home_spread'),
            data_get($snapshot->features, 'market_home_margin'),
            data_get($snapshot->market_context, 'market_home_margin'),
            data_get($snapshot->outputs, 'market_spread'),
        ]);
        if ($homeMargin === null) {
            $bookmakerHomeLine = $this->firstNumber([
                data_get($snapshot->features, 'market_bookmaker_home_line'),
                data_get($snapshot->features, 'market__bookmaker_home_line'),
                data_get($snapshot->market_context, 'bookmaker_home_line'),
                data_get($snapshot->market_context, 'vegas_spread'),
                data_get($snapshot->outputs, 'bookmaker_home_spread'),
            ]);
            $homeMargin = $bookmakerHomeLine === null ? null : -$bookmakerHomeLine;
        }

        return [
            'line' => $homeMargin === null
                ? null
                : ($side === 'home' ? -$homeMargin : $homeMargin),
            'bookmaker' => $bookmaker,
        ];
    }

    private function recordedMarketPromotion(ShadowModelOutput $shadow, string $marketType): ?bool
    {
        $value = data_get($shadow->explanation, "market_promotion.{$marketType}")
            ?? data_get(
                $shadow->featureSnapshot?->model_metadata,
                "shadow_inference.market_promotion.{$marketType}",
            );

        return $value === null ? null : (bool) $value;
    }

    private function probability(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $probability = (float) $value;

        return $probability >= 0.0 && $probability <= 1.0 ? $probability : null;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNumber(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @return array{win: float, loss: float, tie: float}|null
     */
    private function periodOutcomeProbabilities(ShadowModelOutput $shadow, string $side): ?array
    {
        if (! $this->isPeriodMoneyline($shadow->market_type)) {
            return null;
        }

        $home = $this->probability(data_get(
            $shadow->explanation,
            'challenger_outputs.home_win_probability',
        ));
        $away = $this->probability(data_get(
            $shadow->explanation,
            'challenger_outputs.away_win_probability',
        ));
        $tie = $this->probability(data_get(
            $shadow->explanation,
            'challenger_outputs.tie_probability',
        ));
        if ($home === null || $away === null || $tie === null) {
            return null;
        }

        return [
            'win' => $side === 'home' ? $home : $away,
            'loss' => $side === 'home' ? $away : $home,
            'tie' => $tie,
        ];
    }

    private function expectedValue(float $win, float $loss, ?int $price): ?float
    {
        if ($price === null || $price === 0) {
            return null;
        }

        $profit = $price > 0 ? $price / 100 : 100 / abs($price);

        return ($win * $profit) - $loss;
    }

    private function isPeriodMoneyline(string $marketType): bool
    {
        return in_array(
            ModelArtifact::normalizeMarketType($marketType),
            ['first_3_moneyline', 'first_5_moneyline'],
            true,
        );
    }
}
