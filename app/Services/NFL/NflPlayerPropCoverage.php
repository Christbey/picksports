<?php

namespace App\Services\NFL;

use Illuminate\Support\Facades\DB;

class NflPlayerPropCoverage
{
    public function quoteFingerprint(object $prop): string
    {
        return hash('sha256', json_encode([
            $prop->market, $prop->bookmaker, (float) $prop->line,
            $prop->over_price === null ? null : (int) $prop->over_price,
            $prop->under_price === null ? null : (int) $prop->under_price,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,int> */
    public function forGame(int $gameId, int $staleHours = 12): array
    {
        $counts = ['quotes' => 0, 'eligible_quotes' => 0, 'scored_quotes' => 0, 'held_quotes' => 0,
            'no_edge_quotes' => 0, 'data_hold_quotes' => 0, 'unprocessed_quotes' => 0, 'stale_quotes' => 0];
        foreach (DB::table('nfl_player_props')->where('game_id', $gameId)->cursor() as $prop) {
            $counts['quotes']++;
            $eligible = is_numeric($prop->line) && ($prop->over_price !== null || $prop->under_price !== null);
            $counts['eligible_quotes'] += (int) $eligible;
            if ($prop->fetched_at === null || now()->parse($prop->fetched_at)->lt(now()->subHours($staleHours))) {
                $counts['stale_quotes']++;
            }
            $metadata = json_decode($prop->confidence_decomposition ?? '{}', true);
            $state = data_get($metadata, 'analysis_disposition', []);
            $evaluatedAt = $state['evaluated_at'] ?? null;
            $matches = ($state['quote_fingerprint'] ?? null) === $this->quoteFingerprint($prop)
                && is_string($evaluatedAt)
                && now()->parse($evaluatedAt)->gte(now()->subHours($staleHours))
                && ($prop->fetched_at === null || now()->parse($evaluatedAt)->gte(now()->parse($prop->fetched_at)));
            if ($matches && ($state['status'] ?? null) === 'hold' && filled($state['reason'] ?? null)) {
                $counts['held_quotes']++;
                $counts[($state['reason'] ?? '') === 'no_edge' ? 'no_edge_quotes' : 'data_hold_quotes']++;
            } elseif ($matches && ($state['status'] ?? null) === 'scored'
                && in_array(strtolower((string) $prop->recommended_side), ['over', 'under'], true)
                && $prop->confidence_score !== null && $prop->predicted_over_probability !== null
                && $prop->market_over_probability !== null && $prop->edge_probability !== null
                && $prop->data_quality_score !== null) {
                $counts['scored_quotes']++;
            } else {
                $counts['unprocessed_quotes']++;
            }
        }

        return $counts;
    }
}
