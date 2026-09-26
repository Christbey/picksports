<?php

namespace App\Services\NFL;

use App\Models\BetDecision;
use App\Models\MarketQuote;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\Predictions\NflPregameHorizon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class NflReleasedBetDecisionRecorder
{
    /** @return Collection<int, BetDecision> */
    public function record(Prediction $prediction): Collection
    {
        return $this->recordWithCoverage($prediction)['decisions'];
    }

    /**
     * @return array{
     *     decisions: Collection<int, BetDecision>,
     *     candidate_market_keys: list<string>,
     *     released_market_keys: list<string>,
     *     missing_market_keys: list<string>,
     *     hold_reasons: array<string, string>
     * }
     */
    public function recordWithCoverage(Prediction $prediction): array
    {
        $prediction->loadMissing(['game.homeTeam', 'game.awayTeam', 'game.sportEvent']);
        $game = $prediction->game;
        $analysis = (array) data_get($prediction->model_metadata, 'analysis_layer', []);
        $recommendations = $this->candidateRecommendations($analysis);
        $candidateMarketKeys = $recommendations->keys()->all();

        if (! $game instanceof Game
            || ! in_array((string) $game->season_type, $this->regularSeasonTypes(), true)
            || ! in_array((string) $game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
            || $recommendations->isEmpty()) {
            return $this->coverageResult(collect(), []);
        }

        $decidedAt = now()->toImmutable();
        $gameStartAt = $game->sportEvent?->starts_at?->toImmutable()
            ?? $game->game_date?->toImmutable();

        if ($decidedAt === null || $gameStartAt === null || ! $decidedAt->lt($gameStartAt)) {
            return $this->coverageResult(collect(), $candidateMarketKeys);
        }

        $featureSnapshot = PredictionFeatureSnapshot::query()
            ->where('sport', 'nfl')
            ->where('prediction_table', $prediction->getTable())
            ->where('prediction_id', $prediction->getKey())
            ->where('pregame_safe', true)
            ->where('generated_at', '<=', $decidedAt)
            ->where('generated_at', '<', $gameStartAt)
            ->latest('generated_at')
            ->latest('id')
            ->first();
        $decisions = $recommendations
            ->map(fn (array $recommendation): BetDecision => $this->recordMarket(
                $prediction,
                $game,
                $analysis,
                $recommendation,
                $decidedAt,
                CarbonImmutable::instance($gameStartAt),
                $featureSnapshot,
            ))
            ->values();

        return $this->coverageResult($decisions, $candidateMarketKeys);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $recommendation
     */
    private function recordMarket(
        Prediction $prediction,
        Game $game,
        array $analysis,
        array $recommendation,
        CarbonImmutable $decidedAt,
        CarbonImmutable $gameStartAt,
        ?PredictionFeatureSnapshot $featureSnapshot,
    ): BetDecision {
        $selection = $this->selection($prediction, $analysis, $recommendation);
        if ($selection === null) {
            return $this->recordHold(
                $prediction,
                $game,
                $analysis,
                $recommendation,
                $decidedAt,
                $gameStartAt,
                $featureSnapshot,
                'unsupported_candidate_selection',
            );
        }

        if ($featureSnapshot === null) {
            return $this->recordHold(
                $prediction,
                $game,
                $analysis,
                $recommendation,
                $decidedAt,
                $gameStartAt,
                null,
                'pregame_feature_snapshot_missing',
                $selection,
            );
        }

        $decisionHash = hash('sha256', implode('|', [
            $prediction->getTable(),
            $prediction->getKey(),
            $selection['market_key'],
        ]));
        $existing = BetDecision::query()->where('decision_hash', $decisionHash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $quote = $this->entryQuote($game, $selection, $decidedAt, $gameStartAt);
        if ($quote === null) {
            return $this->recordHold(
                $prediction,
                $game,
                $analysis,
                $recommendation,
                $decidedAt,
                $gameStartAt,
                $featureSnapshot,
                'exact_fresh_paired_quote_missing',
                $selection,
            );
        }

        $marketProbability = is_numeric($quote->no_vig_probability)
            ? (float) $quote->no_vig_probability
            : null;

        return BetDecision::query()->firstOrCreate(
            ['decision_hash' => $decisionHash],
            [
                'decision_run_id' => (string) Str::uuid(),
                'model_run_id' => $featureSnapshot?->model_run_id,
                'prediction_feature_snapshot_id' => $featureSnapshot?->getKey(),
                'game_odds_snapshot_id' => $quote->game_odds_snapshot_id,
                'source_table' => $prediction->getTable(),
                'source_id' => $prediction->getKey(),
                'sport' => 'nfl',
                'game_table' => $game->getTable(),
                'game_id' => $game->getKey(),
                'prediction_table' => $prediction->getTable(),
                'prediction_id' => $prediction->getKey(),
                'market_type' => $selection['market_type'],
                'market_key' => $selection['market_key'],
                'side' => $selection['side'],
                'line' => $quote->line,
                'price' => $quote->price,
                'bookmaker' => $quote->bookmaker_key ?? $quote->bookmaker_title,
                'market_probability' => $quote->implied_probability,
                'no_vig_probability' => $marketProbability,
                'model_probability' => $selection['model_probability'],
                'blend_probability' => $selection['model_probability'],
                'edge' => $selection['edge'],
                'score' => is_numeric($recommendation['score'] ?? null)
                    ? (int) $recommendation['score']
                    : null,
                'confidence' => $selection['market_type'] === 'moneyline' && is_numeric($prediction->confidence_score)
                    ? ((float) $prediction->confidence_score / 100)
                    : null,
                'status' => 'released_tracking_bet',
                'recommendation_label' => 'model_bet',
                'is_public' => false,
                'is_tracking_only' => true,
                'is_bet' => true,
                'pregame_safe' => true,
                'eligibility_reasons' => [],
                'risk_flags' => array_values((array) ($analysis['risk_flags'] ?? [])),
                'reason_codes' => array_values((array) ($analysis['reason_codes'] ?? [])),
                'explanation' => [
                    'decision' => 'bet',
                    'execution_status' => 'not_recorded',
                    'authority' => 'nfl_deterministic_analysis_and_pro_signal_consensus',
                    'selection' => $selection,
                    'recommendation' => $recommendation,
                ],
                'feature_snapshot' => [
                    'prediction_feature_snapshot_id' => $featureSnapshot?->getKey(),
                    'snapshot_run_id' => $featureSnapshot?->snapshot_run_id,
                    'feature_hash' => $featureSnapshot?->feature_hash,
                    'prediction_id' => $prediction->getKey(),
                    'model_version' => $prediction->model_version,
                    'feature_version' => $prediction->feature_version,
                    'blend_version' => $prediction->blend_version,
                    'prediction_updated_at' => $prediction->updated_at?->toIso8601String(),
                    'analysis_layer' => $analysis,
                ],
                'market_snapshot' => [
                    ...$quote->toArray(),
                    'immutable_entry_quote' => true,
                    'maximum_quote_age_minutes' => $this->maximumQuoteAgeMinutes(),
                    'model_market_reference_line' => $selection['line'],
                ],
                'decided_at' => $decidedAt,
                'locked_at' => $decidedAt,
                'game_start_at' => $gameStartAt,
            ],
        );
    }

    /** @return list<string> */
    public function candidateMarketKeys(Prediction $prediction): array
    {
        $analysis = (array) data_get($prediction->model_metadata, 'analysis_layer', []);

        return $this->candidateRecommendations($analysis)->keys()->all();
    }

    /**
     * Model/data consensus identifies candidates, not released wagers. Recording
     * additionally requires an eligible game, pregame snapshot and exact quote.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function candidateRecommendations(array $analysis): Collection
    {
        $proSignal = (array) ($analysis['pro_signal_layer'] ?? []);

        if (($analysis['applied'] ?? false) !== true
            || ($analysis['bet_classification'] ?? null) !== 'bet'
            || data_get($analysis, 'eligibility.eligible') !== true
            || ($proSignal['tier'] ?? null) !== 'official_candidate') {
            return collect();
        }

        return collect((array) ($proSignal['recommended_markets'] ?? []))
            ->filter(fn (mixed $recommendation): bool => is_array($recommendation)
                && ($recommendation['tier'] ?? null) === 'official_candidate')
            ->filter(fn (array $recommendation): bool => $this->marketKey($recommendation) !== null)
            ->filter(function (array $recommendation) use ($analysis): bool {
                if ($this->marketKey($recommendation) !== 'spreads') {
                    return true;
                }
                $edge = data_get($analysis, 'calculated_edge.spread_points');

                return is_numeric($edge) && is_finite((float) $edge)
                    && abs((float) $edge) >= (float) config('nfl.predictions.analysis_layer.min_spread_edge', 2.0);
            })
            ->unique(fn (array $recommendation): string => $this->marketKey($recommendation))
            ->keyBy(fn (array $recommendation): string => $this->marketKey($recommendation));
    }

    /**
     * @param  Collection<int, BetDecision>  $decisions
     * @param  list<string>  $candidateMarketKeys
     * @return array{
     *     decisions: Collection<int, BetDecision>,
     *     candidate_market_keys: list<string>,
     *     released_market_keys: list<string>,
     *     missing_market_keys: list<string>,
     *     hold_reasons: array<string, string>
     * }
     */
    private function coverageResult(Collection $decisions, array $candidateMarketKeys): array
    {
        $released = $decisions
            ->filter(fn (BetDecision $decision): bool => $decision->status === 'released_tracking_bet'
                && $decision->is_bet
                && $decision->pregame_safe)
            ->pluck('market_key')
            ->unique()
            ->values()
            ->all();
        $holdReasons = $decisions
            ->reject(fn (BetDecision $decision): bool => $decision->status === 'released_tracking_bet')
            ->mapWithKeys(fn (BetDecision $decision): array => [
                $decision->market_key => (string) data_get(
                    $decision->explanation,
                    'hold_reason',
                    'candidate_decision_missing',
                ),
            ])
            ->all();

        return [
            'decisions' => $decisions,
            'candidate_market_keys' => $candidateMarketKeys,
            'released_market_keys' => $released,
            'missing_market_keys' => array_values(array_diff($candidateMarketKeys, $released)),
            'hold_reasons' => $holdReasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $recommendation
     * @param  array{market_type:string,market_key:string,side:string,line:float|null,edge:float|null,model_probability:float|null}|null  $selection
     */
    private function recordHold(
        Prediction $prediction,
        Game $game,
        array $analysis,
        array $recommendation,
        CarbonImmutable $decidedAt,
        CarbonImmutable $gameStartAt,
        ?PredictionFeatureSnapshot $featureSnapshot,
        string $reason,
        ?array $selection = null,
    ): BetDecision {
        $marketKey = $selection['market_key'] ?? $this->marketKey($recommendation) ?? 'unknown';
        $marketType = $selection['market_type'] ?? match ($marketKey) {
            'h2h' => 'moneyline',
            'spreads' => 'spread',
            'totals' => 'total',
            default => 'unknown',
        };
        $side = $selection['side'] ?? 'unknown';
        $line = $selection['line'] ?? null;
        $decisionHash = hash('sha256', implode('|', [
            $prediction->getTable(),
            $prediction->getKey(),
            $marketKey,
            'hold',
            $reason,
            $side,
            $line ?? 'null',
        ]));

        return BetDecision::query()->firstOrCreate(
            ['decision_hash' => $decisionHash],
            [
                'decision_run_id' => (string) Str::uuid(),
                'model_run_id' => $featureSnapshot?->model_run_id,
                'prediction_feature_snapshot_id' => $featureSnapshot?->getKey(),
                'source_table' => $prediction->getTable(),
                'source_id' => $prediction->getKey(),
                'sport' => 'nfl',
                'game_table' => $game->getTable(),
                'game_id' => $game->getKey(),
                'prediction_table' => $prediction->getTable(),
                'prediction_id' => $prediction->getKey(),
                'market_type' => $marketType,
                'market_key' => $marketKey,
                'side' => $side,
                'line' => $line,
                'model_probability' => $selection['model_probability'] ?? null,
                'blend_probability' => $selection['model_probability'] ?? null,
                'edge' => $selection['edge'] ?? null,
                'score' => is_numeric($recommendation['score'] ?? null)
                    ? (int) $recommendation['score']
                    : null,
                'confidence' => $marketType === 'moneyline' && is_numeric($prediction->confidence_score)
                    ? ((float) $prediction->confidence_score / 100)
                    : null,
                'status' => 'held_candidate',
                'recommendation_label' => 'no_bet',
                'is_public' => false,
                'is_tracking_only' => true,
                'is_bet' => false,
                'pregame_safe' => true,
                'eligibility_reasons' => [$reason],
                'risk_flags' => array_values((array) ($analysis['risk_flags'] ?? [])),
                'reason_codes' => array_values(array_unique([
                    ...(array) ($analysis['reason_codes'] ?? []),
                    'released_candidate_hold',
                ])),
                'explanation' => [
                    'decision' => 'hold',
                    'hold_reason' => $reason,
                    'execution_status' => 'not_released',
                    'authority' => 'nfl_deterministic_analysis_and_pro_signal_consensus',
                    'selection' => $selection,
                    'recommendation' => $recommendation,
                ],
                'feature_snapshot' => [
                    'prediction_feature_snapshot_id' => $featureSnapshot?->getKey(),
                    'snapshot_run_id' => $featureSnapshot?->snapshot_run_id,
                    'feature_hash' => $featureSnapshot?->feature_hash,
                    'prediction_id' => $prediction->getKey(),
                    'model_version' => $prediction->model_version,
                    'feature_version' => $prediction->feature_version,
                    'blend_version' => $prediction->blend_version,
                    'prediction_updated_at' => $prediction->updated_at?->toIso8601String(),
                    'analysis_layer' => $analysis,
                ],
                'market_snapshot' => [
                    'immutable_entry_quote' => false,
                    'maximum_quote_age_minutes' => $this->maximumQuoteAgeMinutes(),
                    'model_market_reference_line' => $line,
                    'hold_reason' => $reason,
                ],
                'decided_at' => $decidedAt,
                'locked_at' => $decidedAt,
                'game_start_at' => $gameStartAt,
            ],
        );
    }

    /** @param array<string, mixed> $recommendation */
    private function marketKey(array $recommendation): ?string
    {
        return match (strtolower((string) ($recommendation['market'] ?? ''))) {
            'winner', 'moneyline', 'h2h' => 'h2h',
            'spread', 'spreads' => 'spreads',
            'total', 'totals' => 'totals',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $recommendation
     * @return array{market_type:string,market_key:string,side:string,line:float|null,edge:float|null,model_probability:float|null}|null
     */
    private function selection(Prediction $prediction, array $analysis, array $recommendation): ?array
    {
        $market = strtolower((string) ($recommendation['market'] ?? ''));
        $spreadEdge = $this->number(data_get($analysis, 'calculated_edge.spread_points'));
        $marketSpread = $this->number(data_get($analysis, 'calculated_edge.market_spread'));
        $totalEdge = $this->number(data_get($analysis, 'calculated_edge.total_points'));
        $marketTotal = $this->number(data_get($analysis, 'calculated_edge.market_total'));
        $homeProbability = $this->number($prediction->win_probability);

        if ($market === 'winner') {
            if (! (bool) config('nfl.betting.moneyline.play_enabled', false) || $homeProbability === null) {
                return null;
            }

            $side = $homeProbability >= 0.5 ? 'home' : 'away';

            return [
                'market_type' => 'moneyline',
                'market_key' => 'h2h',
                'side' => $side,
                'line' => null,
                'edge' => null,
                'model_probability' => $side === 'home' ? $homeProbability : 1 - $homeProbability,
            ];
        }

        if ($market === 'spread' && $spreadEdge !== null && $marketSpread !== null) {
            $side = $spreadEdge >= 0 ? 'home' : 'away';

            return [
                'market_type' => 'spread',
                'market_key' => 'spreads',
                'side' => $side,
                'line' => $side === 'home' ? -$marketSpread : $marketSpread,
                'edge' => abs($spreadEdge),
                'model_probability' => null,
            ];
        }

        if ($market === 'total' && $totalEdge !== null && $marketTotal !== null) {
            return [
                'market_type' => 'total',
                'market_key' => 'totals',
                'side' => $totalEdge >= 0 ? 'over' : 'under',
                'line' => $marketTotal,
                'edge' => abs($totalEdge),
                'model_probability' => null,
            ];
        }

        return null;
    }

    /** @param array{market_type:string,market_key:string,side:string,line:float|null,edge:float|null,model_probability:float|null} $selection */
    private function entryQuote(
        Game $game,
        array $selection,
        CarbonImmutable $decidedAt,
        CarbonImmutable $gameStartAt,
    ): ?MarketQuote {
        $freshAfter = $decidedAt->subMinutes($this->maximumQuoteAgeMinutes());
        $query = MarketQuote::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->getKey())
            ->where('market_key', $selection['market_key'])
            ->where('side', $selection['side'])
            ->where('is_pregame', true)
            ->whereNotNull('price')
            ->where('captured_at', '>=', $freshAfter)
            ->where('captured_at', '<=', $decidedAt)
            ->where('captured_at', '<', $gameStartAt);

        if ($selection['market_type'] !== 'moneyline') {
            if ($selection['line'] === null) {
                return null;
            }

            $query->whereBetween('line', [$selection['line'] - 0.001, $selection['line'] + 0.001]);
        }

        return $query
            ->latest('captured_at')
            ->latest('id')
            ->get()
            ->first(fn (MarketQuote $quote): bool => $this->quoteObservedAt($quote)->betweenIncluded($freshAfter, $decidedAt)
                && $this->hasPairedQuote($quote, $freshAfter, $decidedAt, $gameStartAt));
    }

    private function hasPairedQuote(
        MarketQuote $quote,
        CarbonImmutable $freshAfter,
        CarbonImmutable $decidedAt,
        CarbonImmutable $gameStartAt,
    ): bool {
        $oppositeSide = match ($quote->side) {
            'home' => 'away',
            'away' => 'home',
            'over' => 'under',
            'under' => 'over',
            default => null,
        };

        if ($oppositeSide === null) {
            return false;
        }

        return MarketQuote::query()
            ->where('game_odds_snapshot_id', $quote->game_odds_snapshot_id)
            ->where('market_key', $quote->market_key)
            ->where('side', $oppositeSide)
            ->where('bookmaker_key', $quote->bookmaker_key)
            ->where('bookmaker_title', $quote->bookmaker_title)
            ->whereNotNull('price')
            ->get()
            ->contains(fn (MarketQuote $opposite): bool => $this->pairedLinesMatch($quote, $opposite)
                && $this->quoteObservedAt($opposite)->betweenIncluded($freshAfter, $decidedAt)
                && $this->quoteObservedAt($opposite)->lt($gameStartAt));
    }

    private function pairedLinesMatch(MarketQuote $quote, MarketQuote $opposite): bool
    {
        if ($quote->market_key === 'h2h') {
            return true;
        }

        if (! is_numeric($quote->line) || ! is_numeric($opposite->line)) {
            return false;
        }

        return $quote->market_key === 'spreads'
            ? abs((float) $quote->line + (float) $opposite->line) <= 0.001
            : abs((float) $quote->line - (float) $opposite->line) <= 0.001;
    }

    private function maximumQuoteAgeMinutes(): int
    {
        return max(1, (int) config('nfl.predictions.pregame_market.maximum_quote_age_minutes', 60));
    }

    private function quoteObservedAt(MarketQuote $quote): CarbonImmutable
    {
        $providerObservedAt = data_get($quote->metadata, 'provider_observed_at');
        if (is_string($providerObservedAt) && $providerObservedAt !== '') {
            try {
                return CarbonImmutable::parse($providerObservedAt);
            } catch (Throwable) {
                // Fall through to the persisted ingestion timestamp.
            }
        }

        return CarbonImmutable::instance($quote->captured_at);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** @return list<string> */
    private function regularSeasonTypes(): array
    {
        return NflPregameHorizon::seasonTypes();
    }
}
