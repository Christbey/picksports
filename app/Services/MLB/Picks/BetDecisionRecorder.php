<?php

namespace App\Services\MLB\Picks;

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\GameOddsSnapshot;
use App\Models\MLB\PickCandidate;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Support\Str;

class BetDecisionRecorder
{
    public function record(PickCandidate $candidate): BetDecision
    {
        $candidate->loadMissing('game');
        if ($candidate->canonical_prediction_id === null) {
            $candidate->loadMissing('prediction');
        }
        $featureSnapshot = $this->featureSnapshot($candidate);
        $oddsSnapshot = $this->oddsSnapshot($candidate);
        $eligibilityReasons = $candidate->performanceExclusionReasons();
        $decidedAt = $candidate->generated_at ?? now();
        $decisionHash = $candidate->decision_hash ?: hash('sha256', json_encode([
            $candidate->generation_run_id,
            $candidate->id,
            $candidate->market_type,
            $candidate->side,
            $candidate->line,
            $candidate->price,
        ]));

        return BetDecision::query()->firstOrCreate(
            ['decision_hash' => $decisionHash],
            [
                'decision_run_id' => $candidate->generation_run_id ?: (string) Str::uuid(),
                'model_run_id' => $featureSnapshot?->model_run_id,
                'prediction_feature_snapshot_id' => $featureSnapshot?->id,
                'game_odds_snapshot_id' => $oddsSnapshot?->id,
                'source_table' => $candidate->getTable(),
                'source_id' => $candidate->id,
                'sport' => 'mlb',
                'game_table' => $candidate->game?->getTable() ?? 'mlb_games',
                'game_id' => $candidate->game_id,
                'prediction_table' => $candidate->canonical_prediction_id !== null ? 'predictions' : $candidate->prediction?->getTable(),
                'prediction_id' => $candidate->canonical_prediction_id ?? $candidate->prediction_id,
                'market_type' => $candidate->market_type,
                'market_key' => $candidate->market_key,
                'side' => $candidate->side,
                'line' => $candidate->line,
                'price' => $candidate->price,
                'bookmaker' => $candidate->book,
                'market_probability' => $candidate->market_probability,
                'no_vig_probability' => $candidate->no_vig_probability,
                'model_probability' => $candidate->model_probability,
                'blend_probability' => $candidate->blend_probability,
                'edge' => $candidate->edge_no_vig ?? $candidate->edge_raw,
                'projected_value' => $candidate->projected_value,
                'score' => $candidate->score,
                'confidence' => $candidate->confidence,
                'status' => $candidate->status,
                'recommendation_label' => $candidate->recommendation_label,
                'is_public' => $candidate->is_public,
                'is_tracking_only' => $candidate->is_tracking_only,
                'is_bet' => $candidate->is_bet,
                'pregame_safe' => $eligibilityReasons === [],
                'eligibility_reasons' => $eligibilityReasons,
                'risk_flags' => $candidate->risk_flags,
                'reason_codes' => $candidate->reason_codes,
                'feature_snapshot' => $candidate->feature_snapshot,
                'market_snapshot' => $candidate->market_snapshot,
                'decided_at' => $decidedAt,
                'locked_at' => $candidate->locked_at,
                'game_start_at' => $candidate->game_start_at,
            ],
        );
    }

    public function settle(PickCandidate $candidate): ?BetSettlement
    {
        return app(MlbPickGradingService::class)->gradeCandidate($candidate->id);
    }

    /** Called only inside the grader's game → candidate → decision → receipt transaction. */
    public function writeSettlement(PickCandidate $candidate, BetDecision $decision, ?BetSettlement $receipt,
        array $result, array $evidence, string $fingerprint): BetSettlement
    {
        $metadata = (array) $receipt?->metadata;
        if ($receipt !== null) {
            $previous = $receipt->toArray();
            // Keep prior provenance verbatim, with history stored separately to avoid recursive copies.
            unset($previous['metadata']['mlb_candidate_corrections']);
            $metadata['mlb_candidate_corrections'] = [...(array) ($metadata['mlb_candidate_corrections'] ?? []), [
                'corrected_at' => now()->toIso8601String(), 'reason' => 'explicit_mlb_candidate_authoritative_result_reconciliation',
                'previous_settlement' => $previous,
            ]];
        }
        $metadata = [...$metadata, ...$evidence,
            'source_table' => $candidate->getTable(), 'source_id' => $candidate->id,
            'mlb_candidate_result_fingerprint' => $fingerprint,
            'shadow_profit_units' => $result['profit'],
        ];
        $values = ['result_status' => $result['status'], 'result_value' => $result['value'],
            'profit_units' => $decision->is_bet ? $result['profit'] : 0.0, 'graded_at' => $candidate->graded_at,
            'metadata' => $metadata];
        if ($receipt === null) {
            return BetSettlement::query()->create(['bet_decision_id' => $decision->id, ...$values,
                'closing_price' => $candidate->closing_price, 'closing_line' => $candidate->closing_line,
                'clv' => $candidate->clv, 'settled_at' => now()]);
        }
        // Initial closing observation, CLV and first settlement time never change on outcome correction.
        $receipt->fill($values)->save();

        return $receipt;
    }

    private function featureSnapshot(PickCandidate $candidate): ?PredictionFeatureSnapshot
    {
        if ($candidate->canonical_prediction_id !== null || $candidate->prediction_id === null) {
            return null;
        }

        return PredictionFeatureSnapshot::query()
            ->where('prediction_table', 'mlb_predictions')
            ->where('prediction_id', $candidate->prediction_id)
            ->where('generated_at', '<=', $candidate->generated_at ?? now())
            ->latest('generated_at')
            ->first();
    }

    private function oddsSnapshot(PickCandidate $candidate): ?GameOddsSnapshot
    {
        return GameOddsSnapshot::query()
            ->where('game_table', 'mlb_games')
            ->where('game_id', $candidate->game_id)
            ->where('captured_at', '<=', $candidate->generated_at ?? now())
            ->latest('captured_at')
            ->first();
    }
}
