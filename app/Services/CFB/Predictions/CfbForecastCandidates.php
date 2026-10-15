<?php

namespace App\Services\CFB\Predictions;

/** Fixed candidates are separate from the published independent projection and from betting approval. */
class CfbForecastCandidates
{
    public const VERSION = 'cfb-candidates-v1';

    public function build(float $homeLine, float $total, array $evidence, array $market): array
    {
        $models = ['independent' => ['home_line' => $homeLine, 'total' => $total, 'uses_market' => false]];
        $adjusted = data_get($evidence, 'opponent_adjusted_total.total');
        if (($evidence['status'] ?? null) === 'available' && is_numeric($adjusted)) {
            $models['opponent_adjusted_total'] = ['home_line' => $homeLine, 'total' => max(abs($homeLine), (float) $adjusted), 'uses_market' => false];
        }
        $rating = $evidence['result_rating'] ?? null;
        if (($evidence['status'] ?? null) === 'available' && is_numeric($rating['home_margin'] ?? null) && is_numeric($rating['total'] ?? null)) {
            $models['result_rating'] = ['home_line' => -(float) $rating['home_margin'], 'total' => (float) $rating['total'], 'uses_market' => false];
        }
        $line = data_get($market, 'spread.line');
        $marketTotal = data_get($market, 'total.line');
        if (is_numeric($line) || is_numeric($marketTotal)) {
            $models['market_half_blend'] = ['home_line' => is_numeric($line) ? round(($homeLine + $line) / 2, 3) : null,
                'total' => is_numeric($marketTotal) ? round(($total + $marketTotal) / 2, 3) : null,
                'uses_market' => true, 'market_weight' => 0.5];
            $models['market'] = ['home_line' => is_numeric($line) ? (float) $line : null,
                'total' => is_numeric($marketTotal) ? (float) $marketTotal : null, 'uses_market' => true];
        }

        return ['version' => self::VERSION, 'status' => 'shadow_only', 'validated' => false, 'betting_approved' => false,
            'models' => $models, 'evidence' => $evidence, 'market' => $market,
            'limitations' => ['Candidate projections are not approved selections.', 'No automatic promotion or probability calibration.',
                'Historical comparisons are descriptive and do not establish prospective performance.']];
    }

    public function grade(array $suite, int $homeScore, int $awayScore): array
    {
        $margin = $homeScore - $awayScore;
        $total = $homeScore + $awayScore;
        $grades = [];
        foreach ($suite['models'] ?? [] as $name => $model) {
            $line = $model['home_line'] ?? null;
            $projection = $model['total'] ?? null;
            $market = $suite['market'];
            $spreadEdge = is_numeric($line) && is_numeric(data_get($market, 'spread.line')) ? $market['spread']['line'] - $line : null;
            $totalEdge = is_numeric($projection) && is_numeric(data_get($market, 'total.line')) ? $projection - $market['total']['line'] : null;
            $spreadValue = $spreadEdge === null || abs($spreadEdge) < .000001 ? null : ($margin + $market['spread']['line']) * ($spreadEdge > 0 ? 1 : -1);
            $totalValue = $totalEdge === null || abs($totalEdge) < .000001 ? null : ($total - $market['total']['line']) * ($totalEdge > 0 ? 1 : -1);
            $grades[$name] = [
                'home_margin' => $line === null ? null : -$line, 'total' => $projection,
                'winner_correct' => $line === null || abs($line) < .000001 || $margin === 0 ? null : ($line < 0) === ($margin > 0),
                'spread_absolute_error' => $line === null ? null : abs($margin + $line),
                'total_absolute_error' => $projection === null ? null : abs($total - $projection),
                'total_signed_error' => $projection === null ? null : $total - $projection,
                'spread' => $this->settle($spreadValue, $spreadEdge, $market['spread'] ?? null, $spreadEdge !== null && $spreadEdge < 0),
                'over_under' => $this->settle($totalValue, $totalEdge, $market['total'] ?? null, $totalEdge !== null && $totalEdge < 0),
            ];
        }

        return $grades;
    }

    private function settle(?float $value, ?float $edge, ?array $quote, bool $opposite): array
    {
        $selected = $opposite ? ($quote['opposite_quote'] ?? null) : $quote;
        $result = $value === null ? null : (abs($value) < .000001 ? 'push' : ($value > 0 ? 'win' : 'loss'));
        $price = $selected['price'] ?? null;
        $profit = match ($result) {
            'push' => 0.0, 'loss' => -1.0,
            'win' => is_numeric($price) && abs($price) >= 100 ? ($price > 0 ? $price / 100 : 100 / abs($price)) : null,
            default => null,
        };

        return ['edge_points' => $edge, 'result' => $result, 'selection' => $result === null ? null : ($selected['side'] ?? null),
            'line' => $selected['line'] ?? null, 'price' => $price, 'bookmaker' => $selected['bookmaker'] ?? null,
            'quote_id' => $selected['quote_id'] ?? null, 'profit_units' => $profit,
            'record_type' => 'descriptive_direction_not_approved_bet'];
    }
}
