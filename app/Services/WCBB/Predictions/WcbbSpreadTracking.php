<?php

namespace App\Services\WCBB\Predictions;

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\CanonicalPrediction;
use App\Models\PredictionEvaluation;
use App\Models\WCBB\Game;
use App\Services\Predictions\CanonicalShadowInputs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WcbbSpreadTracking
{
    public function record(CanonicalPrediction $prediction, Game $game): void
    {
        $forecast = app(CanonicalShadowInputs::class)->fromPrediction($prediction);
        $market = data_get($prediction->calculationRun?->inputSnapshot?->inputs, 'pregame_market');
        $spread = data_get($market, 'consensus.spread');
        $margin = $forecast?->outputs['predicted_spread'] ?? null;
        $start = $prediction->sportEvent?->starts_at;
        $now = now();

        if ($forecast === null || ! $start?->isFuture() || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
            || ! is_numeric($margin) || data_get($market, 'available') !== true || ! is_numeric($spread['line'] ?? null)) {
            return;
        }

        $edge = (float) $margin + (float) $spread['line'];
        if (abs($edge) < max(0.001, (float) config('wcbb.betting.edge_thresholds.spread', 2.5))) {
            return;
        }

        $quote = $edge > 0 ? $spread : ($spread['opposite_quote'] ?? []);
        if (! isset($quote['line'], $quote['price'], $quote['observed_at'])
            || ! CarbonImmutable::parse($quote['observed_at'])->betweenIncluded($now->toImmutable()->subHours(36), $now)) {
            return;
        }

        BetDecision::query()->firstOrCreate(
            ['decision_hash' => hash('sha256', 'wcbb-canonical-spread-v1|'.$prediction->id)],
            [
                'decision_run_id' => (string) Str::uuid(),
                'source_table' => $prediction->getTable(),
                'source_id' => $prediction->id,
                'prediction_table' => $prediction->getTable(),
                'prediction_id' => $prediction->id,
                'sport' => 'wcbb',
                'game_table' => $game->getTable(),
                'game_id' => $game->id,
                'game_odds_snapshot_id' => $market['game_odds_snapshot_id'],
                'market_type' => 'spread',
                'market_key' => 'spreads',
                'side' => $quote['side'],
                'line' => $quote['line'],
                'price' => $quote['price'],
                'bookmaker' => $quote['bookmaker_key'],
                'status' => 'tracking_only',
                'recommendation_label' => 'model_lean',
                'is_public' => false,
                'is_tracking_only' => true,
                'is_bet' => false,
                'pregame_safe' => true,
                'feature_snapshot' => ['home_margin' => $margin, 'edge_points' => abs($edge)],
                'market_snapshot' => ['quote' => $quote],
                'decided_at' => $now,
                'locked_at' => $now,
                'game_start_at' => $start,
            ],
        );
    }

    public function signal(CanonicalPrediction $prediction, Game $game): ?array
    {
        if ($prediction->publication_state !== 'published'
            || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
            || ! $prediction->sportEvent?->starts_at?->isFuture()) {
            return null;
        }

        $decision = $prediction->trackedWcbbSpread;
        $observedAt = data_get($decision?->market_snapshot, 'quote.observed_at');
        if ($decision === null || ! $decision->pregame_safe || ! is_string($observedAt)
            || ! CarbonImmutable::parse($observedAt)->betweenIncluded(now()->toImmutable()->subHours(36), now())) {
            return null;
        }

        return [
            'decision_policy_version' => 'wcbb-canonical-spread-v1',
            'decision_status' => 'provisional',
            'has_playable_value' => false,
            'play_count' => 0,
            'best' => [
                'type' => 'spread',
                'side' => $decision->side,
                'market_line' => (float) $decision->line,
                'price' => (int) $decision->price,
                'bookmaker' => $decision->bookmaker,
                'edge' => (float) data_get($decision->feature_snapshot, 'edge_points'),
                'grade' => 'Tracking only',
            ],
        ];
    }

    public function settle(Game $game, PredictionEvaluation $evaluation): void
    {
        $margin = (float) $evaluation->actuals['home_margin'];
        $decisions = BetDecision::query()
            ->where('sport', 'wcbb')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->id)
            ->where('prediction_table', (new CanonicalPrediction)->getTable())
            ->where('market_type', 'spread')
            ->where('is_tracking_only', true)
            ->where('is_bet', false)
            ->where('pregame_safe', true)
            ->whereColumn('decided_at', '<', 'game_start_at')
            ->whereIn('side', ['home', 'away'])
            ->whereNotNull('line')
            ->get();

        foreach ($decisions as $decision) {
            $cover = ($decision->side === 'home' ? $margin : -$margin) + (float) $decision->line;
            $status = abs($cover) < 0.001 ? 'push' : ($cover > 0 ? 'win' : 'loss');
            DB::transaction(function () use ($decision, $evaluation, $status, $cover): void {
                BetDecision::query()->lockForUpdate()->findOrFail($decision->id);
                $settlement = BetSettlement::query()->firstOrNew(['bet_decision_id' => $decision->id]);
                if ((int) data_get($settlement->metadata, 'sport_event_result_id') >= (int) $evaluation->sport_event_result_id) {
                    return;
                }

                $settlement->fill([
                    'result_status' => $status,
                    'result_value' => $cover,
                    'profit_units' => 0,
                    'graded_at' => now(),
                    'settled_at' => now(),
                    'metadata' => ['sport_event_result_id' => $evaluation->sport_event_result_id, 'tracking_only' => true],
                ])->save();
            });
        }
    }
}
