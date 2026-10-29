<?php

namespace App\Services\BettingRecommendations;

use App\Application\Sports\ReadModels\PredictionDeliveryContext;
use App\Models\BetDecision;
use App\Models\CanonicalPrediction;
use Carbon\CarbonImmutable;

class CanonicalBettingRecommendations
{
    /** @return list<array<string, mixed>> */
    public function forPrediction(PredictionDeliveryContext $context): array
    {
        $prediction = $context->source;
        $game = $context->game;
        if (! $prediction instanceof CanonicalPrediction
            || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
            || ! $prediction->sportEvent?->starts_at?->isFuture()) {
            return [];
        }

        return BetDecision::query()
            ->where('sport', $context->sport)
            ->where('prediction_table', $prediction->getTable())
            ->where('prediction_id', $prediction->getKey())
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->getKey())
            ->whereIn('market_type', ['spread', 'total', 'moneyline'])
            ->where('decided_at', '<=', now())
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->with('oddsSnapshot')->get()->unique('market_type')
            ->filter(fn (BetDecision $decision): bool => $decision->is_public
                && ! $decision->is_tracking_only && $decision->is_bet && $decision->pregame_safe
                && ! in_array($decision->status, ['held_candidate', 'provisional_candidate', 'shadow_no_bet', 'tracking_only', 'withdrawn', 'superseded'], true)
                && ! in_array($decision->recommendation_label, ['no_bet', 'tracking_only', 'model_lean'], true)
                && $decision->price !== null && abs((float) $decision->price) >= 100
                && $decision->game_start_at?->isFuture()
                && $decision->decided_at?->gte($prediction->published_at)
                && $decision->decided_at?->lt($decision->game_start_at)
                && $this->hasFreshQuote($decision))
            ->map(fn (BetDecision $decision): ?array => $this->recommendation($decision, $context))
            ->filter()->values()->all();
    }

    private function hasFreshQuote(BetDecision $decision): bool
    {
        $observedAt = data_get($decision->market_snapshot, 'quote.captured_at')
            ?? data_get($decision->market_snapshot, 'captured_at')
            ?? $decision->oddsSnapshot?->captured_at
            ?? $decision->decided_at;

        if ($observedAt === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($observedAt)->betweenIncluded(now()->subHours(36), now());
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    private function recommendation(BetDecision $decision, PredictionDeliveryContext $context): ?array
    {
        $side = (string) $decision->side;
        $type = (string) $decision->market_type;
        if (! in_array($side, $type === 'total' ? ['over', 'under'] : ['home', 'away'], true)
            || ($type !== 'moneyline' && ! is_numeric($decision->line))) {
            return null;
        }
        $team = $side === 'home' ? $context->game->homeTeam : $context->game->awayTeam;
        $teamName = $team?->display_name ?? $team?->name ?? $team?->school ?? ucfirst($side);
        $line = is_numeric($decision->line) ? (float) $decision->line : null;
        $label = match ($type) {
            'moneyline' => "Bet {$teamName} ML",
            'total' => 'Bet '.ucfirst($side).' '.number_format($line, 1),
            default => "Bet {$teamName} ".($line > 0 ? '+' : '').number_format($line, 1),
        };

        $modelHomeLine = $context->predictedSpread !== null ? -$context->predictedSpread : null;
        $marketHomeLine = $type === 'spread' ? ($side === 'home' ? $line : -$line) : null;
        $edge = match ($type) {
            'spread' => $modelHomeLine !== null ? $line - ($side === 'home' ? $modelHomeLine : -$modelHomeLine) : null,
            'total' => $context->predictedTotal !== null
                ? ($side === 'over' ? $context->predictedTotal - $line : $line - $context->predictedTotal) : null,
            default => is_numeric($decision->edge) ? (float) $decision->edge * 100 : null,
        };

        return [
            'type' => $type, 'recommendation' => $label,
            'model_probability' => is_numeric($decision->model_probability) ? (float) $decision->model_probability : null,
            'market_implied_probability' => is_numeric($decision->market_probability) ? (float) $decision->market_probability : null,
            'no_vig_implied_probability' => is_numeric($decision->no_vig_probability) ? (float) $decision->no_vig_probability : null,
            'edge' => $edge !== null ? round($edge, 2) : null,
            'confidence' => is_numeric($decision->confidence) ? (float) $decision->confidence * ((float) $decision->confidence <= 1 ? 100 : 1) : null,
            'odds' => (float) $decision->price, 'line' => $line,
            'market_line' => $type === 'spread' ? $marketHomeLine : $line,
            'model_line' => $type === 'spread' ? $modelHomeLine : ($type === 'total' ? $context->predictedTotal : null),
            'model_home_line' => $type === 'spread' ? $modelHomeLine : null,
            'market_home_line' => $marketHomeLine,
            'odds_updated_at' => data_get($decision->market_snapshot, 'quote.captured_at')
                ?? data_get($decision->market_snapshot, 'captured_at')
                ?? $decision->oddsSnapshot?->captured_at?->toIso8601String()
                ?? $decision->decided_at?->toIso8601String(),
            'bookmaker' => $decision->bookmaker, 'bet_decision_id' => $decision->id,
            'prediction_source' => 'canonical', 'prediction_id' => $context->source->public_id,
            'is_playable' => true, 'grade' => 'Playable',
            'decision_status' => $decision->status,
            'pick_side' => $decision->side, 'score' => $decision->score,
            'reason_codes' => (array) $decision->reason_codes,
            'risk_flags' => (array) $decision->risk_flags,
        ];
    }
}
