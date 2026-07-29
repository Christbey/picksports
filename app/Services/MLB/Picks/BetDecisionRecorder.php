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
        $candidate->loadMissing(['game', 'prediction']);
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
                'prediction_table' => $candidate->prediction?->getTable(),
                'prediction_id' => $candidate->prediction_id,
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
        $decision = BetDecision::query()
            ->where('source_table', $candidate->getTable())
            ->where('source_id', $candidate->id)
            ->first();

        if ($decision === null || $candidate->graded_at === null || $candidate->result_status === null) {
            return null;
        }

        return BetSettlement::query()->updateOrCreate(
            ['bet_decision_id' => $decision->id],
            [
                'result_status' => $candidate->result_status,
                'result_value' => $candidate->result_value,
                'profit_units' => $candidate->result_profit_units ?? 0.0,
                'closing_price' => $candidate->closing_price,
                'closing_line' => $candidate->closing_line,
                'clv' => $candidate->clv,
                'graded_at' => $candidate->graded_at,
                'settled_at' => now(),
                'metadata' => [
                    'source_table' => $candidate->getTable(),
                    'source_id' => $candidate->id,
                ],
            ],
        );
    }

    private function featureSnapshot(PickCandidate $candidate): ?PredictionFeatureSnapshot
    {
        if ($candidate->prediction_id === null) {
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
