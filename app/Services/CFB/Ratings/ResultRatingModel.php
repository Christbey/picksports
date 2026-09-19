<?php

namespace App\Services\CFB\Ratings;

/** A regularized, connected-opponent points model. No market lines or external ratings enter the fit. */
class ResultRatingModel
{
    public const VERSION = 'cfb-result-rating-v1';

    public function fit(array $games, int $season): array
    {
        $ratings = $counts = $scored = $allowed = $weights = $edges = $sourceIds = [];
        $homeAdvantage = 0.0;
        $seen = [];
        $totalWeight = $homeNumerator = $homeDenominator = $sumPoints = 0.0;
        foreach ($games as $game) {
            if (! $this->valid($game) || isset($seen[(int) $game['id']]) || ! in_array((int) $game['season'], [$season - 1, $season], true)) {
                continue;
            }
            $seen[(int) $game['id']] = true;
            $weight = (int) $game['season'] === $season ? 1.0 : .5;
            $h = (int) $game['home_team_id'];
            $a = (int) $game['away_team_id'];
            $margin = (float) $game['home_score'] - (float) $game['away_score'];
            $venue = empty($game['neutral_site']) ? 1.0 : 0.0;
            $edges[] = [$h, $a, $margin, $venue, $weight];
            foreach ([[$h, $game['home_score'], $game['away_score']], [$a, $game['away_score'], $game['home_score']]] as [$id, $for, $against]) {
                $ratings[$id] = 0.0;
                $counts[$id][$game['season']] = ($counts[$id][$game['season']] ?? 0) + 1;
                $weights[$id] = ($weights[$id] ?? 0) + $weight;
                $scored[$id] = ($scored[$id] ?? 0) + $weight * $for;
                $allowed[$id] = ($allowed[$id] ?? 0) + $weight * $against;
                $sourceIds[$id][] = $game['id'];
            }
            $totalWeight += $weight;
            $sumPoints += $weight * ($game['home_score'] + $game['away_score']);
            $homeNumerator += $weight * $venue * $margin;
            $homeDenominator += $weight * $venue;
        }
        if ($edges === []) {
            return ['version' => self::VERSION, 'status' => 'missing_results', 'teams' => []];
        }
        // Coordinate descent solves the convex ridge objective. Two effective
        // games of regularization stabilize tiny samples; neither side is defaulted to a strong rating.
        $adjacency = [];
        foreach ($edges as [$h, $a, $margin, $venue, $weight]) {
            $adjacency[$h][] = [$a, $margin, $venue, $weight];
            $adjacency[$a][] = [$h, -$margin, -$venue, $weight];
        }
        ksort($ratings);
        for ($iteration = 0; $iteration < 200; $iteration++) {
            $change = 0.0;
            foreach ($ratings as $id => $rating) {
                $numerator = 0.0;
                foreach ($adjacency[$id] as [$opponent, $margin, $venue, $weight]) {
                    $numerator += $weight * ($margin - $homeAdvantage * $venue + $ratings[$opponent]);
                }
                $next = $numerator / ($weights[$id] + 2.0);
                $change = max($change, abs($next - $rating));
                $ratings[$id] = $next;
            }
            $numerator = 0.0;
            foreach ($edges as [$h, $a, $margin, $venue, $weight]) {
                $numerator += $weight * $venue * ($margin - $ratings[$h] + $ratings[$a]);
            }
            $nextHome = $numerator / max(1, $homeDenominator + 20);
            $change = max($change, abs($nextHome - $homeAdvantage));
            $homeAdvantage = $nextHome;
            if ($change < .000001) {
                break;
            }
        }
        // Components cannot be compared without a chain of common opponents.
        $components = [];
        foreach (array_keys($ratings) as $id) {
            if (isset($components[$id])) {
                continue;
            }
            $queue = [$id];
            $components[$id] = $id;
            while ($queue) {
                $current = array_pop($queue);
                foreach ($adjacency[$current] as [$other]) {
                    if (! isset($components[$other])) {
                        $components[$other] = $id;
                        $queue[] = $other;
                    }
                }
            }
        }
        $leaguePoints = $sumPoints / (2 * $totalWeight);
        $teams = [];
        foreach ($ratings as $id => $rating) {
            $teams[$id] = ['rating' => round($rating, 6), 'component' => $components[$id],
                'current_games' => $counts[$id][$season] ?? 0, 'prior_games' => $counts[$id][$season - 1] ?? 0,
                'effective_games' => $weights[$id], 'source_game_ids' => $sourceIds[$id],
                'points_for' => round(($scored[$id] + 2 * $leaguePoints) / ($weights[$id] + 2), 6),
                'points_against' => round(($allowed[$id] + 2 * $leaguePoints) / ($weights[$id] + 2), 6)];
        }

        return ['version' => self::VERSION, 'status' => 'fitted', 'season' => $season,
            'games' => count($edges), 'home_advantage' => round($homeAdvantage, 6), 'teams' => $teams,
            'regularization_effective_games' => 2, 'prior_season_weight' => .5,
            'probability_status' => 'not_calibrated'];
    }

    public function predict(array $model, int $home, int $away, bool $neutral = false): ?array
    {
        $h = $model['teams'][$home] ?? null;
        $a = $model['teams'][$away] ?? null;
        if (! $h || ! $a || $h['component'] !== $a['component']) {
            return null;
        }
        $margin = $h['rating'] - $a['rating'] + ($neutral ? 0 : $model['home_advantage']);
        $total = max(abs($margin), ($h['points_for'] + $a['points_against'] + $a['points_for'] + $h['points_against']) / 2);

        return ['home_margin' => round($margin, 1), 'total' => round($total, 1),
            'home' => $h, 'away' => $a, 'version' => self::VERSION,
            'source' => 'independent_completed_results', 'probability_status' => 'not_calibrated'];
    }

    public static function usableEvidence(mixed $evidence): bool
    {
        if (! is_array($evidence) || ($evidence['status'] ?? null) !== 'observed_result_rating'
            || ($evidence['version'] ?? null) !== self::VERSION
            || ! is_int($evidence['run_id'] ?? null) || $evidence['run_id'] <= 0
            || ! is_string($evidence['input_hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $evidence['input_hash'])) {
            return false;
        }
        foreach (['home_margin', 'total'] as $key) {
            if (! is_numeric($evidence[$key] ?? null) || ! is_finite((float) $evidence[$key])) {
                return false;
            }
        }
        if ($evidence['total'] < abs($evidence['home_margin'])) {
            return false;
        }
        $counts = [];
        foreach (['home', 'away'] as $side) {
            foreach (['current_games', 'prior_games'] as $field) {
                if (! isset($evidence[$side][$field]) || ! is_int($evidence[$side][$field]) || $evidence[$side][$field] < 0) {
                    return false;
                }
            }
            $counts[] = $evidence[$side]['current_games'] + $evidence[$side]['prior_games'];
        }

        return min($counts) > 0 && ($evidence['minimum_team_games'] ?? null) === min($counts);
    }

    private function valid(array $game): bool
    {
        foreach (['id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score'] as $field) {
            if (! is_numeric($game[$field] ?? null) || ! is_finite((float) $game[$field])
                || (float) $game[$field] !== floor((float) $game[$field])) {
                return false;
            }
        }

        return (int) $game['id'] > 0 && (int) $game['home_team_id'] > 0 && (int) $game['away_team_id'] > 0
            && (int) $game['home_team_id'] !== (int) $game['away_team_id']
            && $game['home_score'] >= 0 && $game['away_score'] >= 0
            && (int) $game['home_score'] !== (int) $game['away_score'];
    }
}
