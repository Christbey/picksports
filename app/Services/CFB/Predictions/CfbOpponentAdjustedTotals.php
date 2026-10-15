<?php

namespace App\Services\CFB\Predictions;

/** Fixed regularization; no fit or coefficient selection uses the target game's result. */
class CfbOpponentAdjustedTotals
{
    public const VERSION = 'cfb-opponent-scoring-v1';

    public function fit(array $rows, int $season): array
    {
        $games = collect($rows)->filter(fn ($r) => $this->valid($r, $season))->unique('id')->values();
        if ($games->count() < 100) {
            return ['status' => 'insufficient_training_games', 'games' => $games->count()];
        }
        $weightedPoints = $weightSum = 0.0;
        $edges = $counts = [];
        foreach ($games as $r) {
            $w = (int) $r['season'] === $season ? 1.0 : 0.5;
            $h = (int) $r['home_team_id'];
            $a = (int) $r['away_team_id'];
            $weightedPoints += $w * ($r['home_score'] + $r['away_score']);
            $weightSum += 2 * $w;
            $edges[$h][] = [$a, (float) $r['home_score'], (float) $r['away_score'], $w];
            $edges[$a][] = [$h, (float) $r['away_score'], (float) $r['home_score'], $w];
            $counts[$h] = ($counts[$h] ?? 0) + 1;
            $counts[$a] = ($counts[$a] ?? 0) + 1;
        }
        $mean = $weightedPoints / $weightSum;
        $offense = $defense = array_fill_keys(array_keys($edges), 0.0);
        for ($iteration = 0; $iteration < 100; $iteration++) {
            $nextOffense = $nextDefense = [];
            foreach ($edges as $team => $matches) {
                $off = $def = 0.0;
                $weight = 4.0;
                foreach ($matches as [$opponent, $scored, $allowed, $w]) {
                    $off += $w * ($scored - $mean - $defense[$opponent]);
                    $def += $w * ($allowed - $mean - $offense[$opponent]);
                    $weight += $w;
                }
                $nextOffense[$team] = $off / $weight;
                $nextDefense[$team] = $def / $weight;
            }
            $offense = $nextOffense;
            $defense = $nextDefense;
        }

        return ['status' => 'fitted', 'version' => self::VERSION, 'games' => $games->count(),
            'league_team_points' => $mean, 'regularization_games' => 4, 'prior_season_weight' => 0.5,
            'offense' => $offense, 'defense' => $defense, 'counts' => $counts];
    }

    private function valid(array $row, int $season): bool
    {
        foreach (['id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score'] as $key) {
            if (! is_numeric($row[$key] ?? null) || ! is_finite((float) $row[$key]) || floor((float) $row[$key]) !== (float) $row[$key]) {
                return false;
            }
        }

        return $row['id'] > 0 && $row['home_team_id'] > 0 && $row['away_team_id'] > 0
            && $row['home_team_id'] != $row['away_team_id'] && $row['home_score'] >= 0 && $row['away_score'] >= 0
            && in_array((int) $row['season'], [$season - 1, $season], true);
    }

    public function predict(array $model, int $home, int $away): ?array
    {
        if (($model['status'] ?? null) !== 'fitted' || ! isset($model['offense'][$home], $model['offense'][$away])) {
            return null;
        }
        $homePoints = max(0, $model['league_team_points'] + $model['offense'][$home] + $model['defense'][$away]);
        $awayPoints = max(0, $model['league_team_points'] + $model['offense'][$away] + $model['defense'][$home]);

        return ['home_points' => round($homePoints, 1), 'away_points' => round($awayPoints, 1),
            'total' => round($homePoints + $awayPoints, 1), 'league_total_prior' => round(2 * $model['league_team_points'], 2),
            'training_games' => $model['games'], 'version' => self::VERSION,
            'pace_status' => 'not_applied_without_verified_possession_evidence'];
    }
}
