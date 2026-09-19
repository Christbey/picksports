<?php

namespace App\Services\CFB\Predictions;

use App\Models\BetDecision;
use App\Models\CFB\Game;
use Carbon\CarbonImmutable;

/** Read-only grading from frozen decisions; never recreates recommendations from today's model. */
class CfbFrozenDecisionPerformance
{
    public function __construct(private CfbStoredPregameQuote $quotes) {}

    public function grade(BetDecision $decision, ?Game $game): array
    {
        $row = ['decision_id' => $decision->id, 'game_id' => $decision->game_id,
            'market_type' => $decision->market_type, 'side' => $decision->side,
            'entry_line' => is_numeric($decision->line) ? (float) $decision->line : null,
            'entry_price' => is_numeric($decision->price) ? (int) $decision->price : null,
            'entry_bookmaker' => $decision->bookmaker, 'decision_status' => $decision->status,
            'release_version' => data_get($decision->feature_snapshot, 'release_version'),
            'canonical_prediction_id' => data_get($decision->feature_snapshot, 'canonical_prediction_id'),
            'model_edge_points' => data_get($decision->explanation, 'point_edge'),
            'large_spread_bucket' => $decision->market_type === 'spread' ? app(CfbFrozenBaselineComparison::class)
                ->spreadBuckets(is_numeric($decision->line) ? (float) $decision->line : null)['exclusive'] : null,
            'cohort' => $decision->is_bet ? ($decision->is_tracking_only ? 'tracked_recommendation' : 'recorded_bet') : 'counterfactual_no_bet',
            'decided_at' => $decision->decided_at?->toIso8601String(),
            'grade' => null, 'profit_units' => null, 'clv' => null, 'missing' => []];
        $kickoff = $game?->sportEvent?->starts_at ?? $decision->game_start_at;
        if (! $game || ! $kickoff || ! $decision->decided_at || ! $decision->pregame_safe
            || ! $decision->locked_at || ! $decision->decision_hash
            || $decision->decided_at->gte($kickoff) || $decision->locked_at->gte($kickoff)
            || $decision->created_at?->gte($kickoff)) {
            return [...$row, 'missing' => ['not_a_verified_frozen_pregame_decision']];
        }
        $market = match ($decision->market_type) {
            'spread' => 'spreads', 'total' => 'totals', 'moneyline' => 'h2h', default => null,
        };
        if ($market === null) {
            return [...$row, 'missing' => ['unsupported_market']];
        }
        if ($game->status === 'STATUS_FINAL' && is_numeric($game->home_score) && is_numeric($game->away_score)
            && $game->home_score >= 0 && $game->away_score >= 0 && $game->home_score != $game->away_score) {
            $homeMargin = (float) $game->home_score - (float) $game->away_score;
            $total = (float) $game->home_score + (float) $game->away_score;
            $selected = match ($decision->market_type) {
                'spread' => is_numeric($decision->line) ? match ($decision->side) {
                    'home' => $homeMargin + $decision->line, 'away' => -$homeMargin + $decision->line, default => null,
                } : null,
                'total' => is_numeric($decision->line) ? match ($decision->side) {
                    'over' => $total - $decision->line, 'under' => $decision->line - $total, default => null,
                } : null,
                'moneyline' => match ($decision->side) {
                    'home' => $homeMargin, 'away' => -$homeMargin, default => null
                },
            };
            if ($selected !== null) {
                $row['grade'] = abs($selected) < .000001 ? 'push' : ($selected > 0 ? 'win' : 'loss');
                $row['result_margin'] = $selected;
                $row['final_score'] = ['home' => $game->home_score, 'away' => $game->away_score];
                $price = $row['entry_price'];
                if ($price !== null && abs($price) >= 100) {
                    $row['profit_units'] = match ($row['grade']) {
                        'push' => 0.0, 'loss' => -1.0, 'win' => $price > 0 ? $price / 100 : 100 / abs($price),
                    };
                } else {
                    $row['missing'][] = 'valid_entry_price';
                }
            } else {
                $row['missing'][] = 'valid_entry_line_or_side';
            }
        } else {
            $row['missing'][] = 'valid_final_result';
        }
        if (CarbonImmutable::now()->lt($kickoff)) {
            $row['missing'][] = 'closing_not_yet_defined';

            return $row;
        }
        $quote = $this->quotes->latest($game->id, $market, (string) $decision->side, $kickoff);
        if (! $quote) {
            $row['missing'][] = 'stored_pregame_closing_quote';

            return $row;
        }
        $row['closing'] = ['quote_id' => $quote->id, 'line' => $quote->line === null ? null : (float) $quote->line,
            'price' => $quote->price, 'bookmaker' => $quote->bookmaker_key,
            'captured_at' => $quote->captured_at->toIso8601String(), 'stored_at' => $quote->created_at->toIso8601String(), 'policy' => 'last_stored_pregame_quote'];
        if ($decision->market_type === 'moneyline') {
            $entry = $this->implied($row['entry_price']);
            $closing = $this->implied($quote->price);
            $row['clv'] = $entry === null || $closing === null ? null : $closing - $entry;
            $row['clv_units'] = 'implied_probability_including_vig';
        } elseif ($row['entry_line'] !== null && $quote->line !== null) {
            $row['clv'] = $decision->market_type === 'total' && $decision->side === 'over'
                ? (float) $quote->line - $row['entry_line'] : $row['entry_line'] - (float) $quote->line;
            $row['clv_units'] = 'points';
        }
        if ($row['clv'] === null) {
            $row['missing'][] = 'comparable_entry_and_closing_values';
        }

        return $row;
    }

    public function summarize(array $rows): array
    {
        $grades = array_count_values(array_values(array_filter(array_column($rows, 'grade'))));
        $priced = array_values(array_filter($rows, fn ($r) => $r['profit_units'] !== null));
        $clv = array_values(array_filter(array_column($rows, 'clv'), fn ($value) => $value !== null));
        $wins = $grades['win'] ?? 0;
        $losses = $grades['loss'] ?? 0;

        return ['decisions' => count($rows), 'wins' => $wins, 'losses' => $losses, 'pushes' => $grades['push'] ?? 0,
            'graded' => array_sum($grades), 'win_rate_excluding_pushes' => $wins + $losses ? $wins / ($wins + $losses) : null,
            'priced_decisions' => count($priced), 'profit_units' => $priced ? array_sum(array_column($priced, 'profit_units')) : null,
            'roi_per_unit_staked' => $priced ? array_sum(array_column($priced, 'profit_units')) / count($priced) : null,
            'clv_observations' => count($clv), 'mean_clv' => $clv ? array_sum($clv) / count($clv) : null,
            'missing' => array_count_values(array_merge([], ...array_column($rows, 'missing')))];
    }

    private function implied(mixed $price): ?float
    {
        if (! is_numeric($price) || abs((float) $price) < 100) {
            return null;
        }

        return $price > 0 ? 100 / ($price + 100) : abs($price) / (abs($price) + 100);
    }
}
