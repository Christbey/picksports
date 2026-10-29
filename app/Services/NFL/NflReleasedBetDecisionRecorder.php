<?php

namespace App\Services\NFL;

use App\Models\BetDecision;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\Predictions\NflPregameHorizon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NflReleasedBetDecisionRecorder
{
    public function __construct(private readonly NflCandidateDecisionPolicy $policy) {}

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
        $recommendations = $this->policy->candidateRecommendations($analysis);
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
            ->first(['id', 'model_run_id', 'snapshot_run_id', 'feature_hash']);
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
        $selection = $this->policy->selection($prediction->win_probability !== null ? (float) $prediction->win_probability : null, $analysis, $recommendation);
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

        $quote = $this->policy->entryQuote($game, $selection, $decidedAt, $gameStartAt);
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
                    'maximum_quote_age_minutes' => $this->policy->maximumQuoteAgeMinutes(),
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

        return $this->policy->candidateRecommendations($analysis)->keys()->all();
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
        $marketKey = $selection['market_key'] ?? $this->policy->marketKey($recommendation) ?? 'unknown';
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
                    'maximum_quote_age_minutes' => $this->policy->maximumQuoteAgeMinutes(),
                    'model_market_reference_line' => $line,
                    'hold_reason' => $reason,
                ],
                'decided_at' => $decidedAt,
                'locked_at' => $decidedAt,
                'game_start_at' => $gameStartAt,
            ],
        );
    }

    /** @return list<string> */
    private function regularSeasonTypes(): array
    {
        return NflPregameHorizon::seasonTypes();
    }
}
