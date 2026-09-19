<?php

namespace App\Services\CFB;

use App\Models\BetDecision;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Services\CFB\Predictions\CfbCanonicalSpreadValueSignalService;
use Illuminate\Support\Str;

/** Freeze decisions prospectively; recording a recommendation never places a wager. */
class CfbReleasedBetDecisionRecorder
{
    public function record(CanonicalPrediction $prediction, Game $game): ?BetDecision
    {
        $prediction->loadMissing(['markets', 'calculationRun.inputSnapshot', 'calculationRun.release']);
        $game->loadMissing(['sportEvent', 'homeTeam', 'awayTeam']);
        $now = now()->toImmutable();
        $start = $game->sportEvent?->starts_at;
        if ($prediction->sport !== 'cfb' || $prediction->phase !== 'pregame' || $prediction->publication_state !== 'published'
            || (int) $prediction->sport_event_id !== (int) $game->sport_event_id
            || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
            || ! $start || ! $now->lt($start) || ! $prediction->generated_at
            || ! $prediction->generated_at->betweenIncluded($now->subMinutes(15), $now)) {
            return null;
        }
        $hash = hash('sha256', 'cfb-canonical-spread-decision-v1|'.$prediction->id);
        if ($existing = BetDecision::query()->where('decision_hash', $hash)->first()) {
            return $existing;
        }
        $signal = app(CfbCanonicalSpreadValueSignalService::class)->forPrediction($prediction, $game);
        $snapshot = $prediction->calculationRun?->inputSnapshot;
        $safe = $snapshot && $snapshot->pregame_safety_status === 'verified' && $snapshot->captured_at
            && $snapshot->captured_at->lte($now) && $snapshot->captured_at->lt($start)
            && $prediction->generated_at->lt($start);
        $quote = data_get($signal, 'market_confirmation.best_quote');
        $probability = (array) data_get($signal, 'cover_probability_evidence', []);
        $reasons = array_values(array_unique([
            ...(array) data_get($signal, 'spread_assessment.risk_flags', []),
            ...(array) data_get($signal, 'market_confirmation.risk_flags', []),
            ...(array) ($probability['risk_flags'] ?? []),
            ...(! $signal ? ['market_signal_unavailable'] : []),
            ...(! $safe ? ['unverified_pregame_snapshot'] : []),
        ]));
        $bet = $safe && ($signal['has_playable_value'] ?? false) && $quote !== null && $reasons === [];
        if (! $bet && $reasons === []) {
            $reasons[] = 'point_edge_or_expected_value_below_threshold';
        }
        $side = data_get($signal, 'market_confirmation.side', data_get($signal, 'best.side', 'unknown'));
        $provisional = $safe && ($signal['decision_status'] ?? null) === 'provisional';
        $features = ['decision_policy_version' => $signal['decision_policy_version'] ?? null, 'canonical_prediction_id' => $prediction->id, 'prediction_public_id' => $prediction->public_id,
            'prediction_revision' => $prediction->revision, 'calculation_run_id' => $prediction->calculation_run_id,
            'calculation_release_id' => $prediction->calculationRun?->calculation_release_id,
            'release_version' => $prediction->calculationRun?->release?->semantic_version,
            'event_input_snapshot_id' => $snapshot?->id, 'content_hash' => $snapshot?->content_hash,
            'captured_at' => $snapshot?->captured_at?->toIso8601String(),
            'generated_at' => $prediction->generated_at->toIso8601String(),
            'cover_probability_evidence' => $probability, 'signal' => $signal];

        return BetDecision::query()->firstOrCreate(['decision_hash' => $hash], [
            'decision_run_id' => (string) Str::uuid(),
            'source_table' => $prediction->getTable(), 'source_id' => $prediction->id,
            'prediction_table' => $prediction->getTable(), 'prediction_id' => $prediction->id,
            'sport' => 'cfb', 'game_table' => $game->getTable(), 'game_id' => $game->id,
            'game_odds_snapshot_id' => $quote['snapshot_id'] ?? null,
            'market_type' => 'spread', 'market_key' => 'spreads', 'side' => $side,
            'line' => $quote['line'] ?? data_get($signal, 'best.market_line'),
            'price' => $quote['price'] ?? null, 'bookmaker' => $quote['bookmaker'] ?? null,
            'market_probability' => $quote['implied_probability'] ?? null,
            'no_vig_probability' => $quote['no_vig_probability'] ?? null,
            'model_probability' => $probability['cover_probability'] ?? null,
            'edge' => isset($probability['cover_probability'], $quote['no_vig_probability']) ? $probability['cover_probability'] - $quote['no_vig_probability'] : null,
            'projected_value' => $probability['expected_value_per_unit'] ?? null,
            'status' => $bet ? 'released_tracking_bet' : ($provisional ? 'provisional_candidate' : 'held_candidate'),
            'recommendation_label' => $bet ? 'model_bet' : ($provisional ? 'model_lean' : 'no_bet'),
            'is_public' => false, 'is_tracking_only' => true, 'is_bet' => $bet,
            'pregame_safe' => $safe, 'eligibility_reasons' => $reasons, 'risk_flags' => $reasons,
            'reason_codes' => ['cfb_canonical_prospective_decision'],
            'explanation' => ['authority' => 'cfb_canonical_prospective_decision', 'decision' => $bet ? 'bet' : ($provisional ? 'lean' : 'hold'),
                'execution_status' => 'not_recorded', 'point_edge' => data_get($signal, 'best.edge'),
                'decision_evidence_hash' => hash('sha256', json_encode([$features, $quote], JSON_THROW_ON_ERROR))],
            'feature_snapshot' => $features,
            'market_snapshot' => ['immutable_entry_quote' => $quote !== null, 'quote' => $quote,
                'confirmation' => data_get($signal, 'market_confirmation')],
            'decided_at' => $now, 'locked_at' => $now, 'game_start_at' => $start,
        ]);
    }
}
