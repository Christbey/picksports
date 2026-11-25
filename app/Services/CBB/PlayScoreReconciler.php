<?php

namespace App\Services\CBB;

use App\DataTransferObjects\ESPN\CbbPlayData;
use App\Models\CBB\Game;

class PlayScoreReconciler
{
    /** Reconstruct only from explicit scoring events that reconcile to both final scores. */
    public function reconcile(array $plays, Game $game): ?array
    {
        $first = $plays[0] ?? [];
        $last = $plays === [] ? [] : $plays[array_key_last($plays)];
        if ($game->home_score === null || $game->away_score === null
            || ! isset($first['homeScore'], $first['awayScore'])
            || ! is_numeric($first['homeScore']) || ! is_numeric($first['awayScore'])
            || ($first['period']['number'] ?? null) != 1
            || ($first['clock']['displayValue'] ?? null) !== '20:00'
            || ($first['homeScore'] ?? null) != 0 || ($first['awayScore'] ?? null) != 0
            || ($last['period']['number'] ?? 0) < 2
            || ! in_array($last['clock']['displayValue'] ?? null, ['0:00', '00:00'], true)) {
            return null;
        }
        $homeId = (string) $game->homeTeam?->espn_id;
        $awayId = (string) $game->awayTeam?->espn_id;
        if ($homeId === '' || $awayId === '' || $homeId === $awayId) {
            return null;
        }
        $home = 0;
        $away = 0;
        foreach ($plays as $index => &$play) {
            $dto = CbbPlayData::fromEspnResponse($play, $index);
            if ($dto->madeShot) {
                $points = $play['scoreValue'] ?? null;
                if (! is_numeric($points) || (int) $points != $points || ! in_array((int) $points, [1, 2, 3], true)) {
                    return null;
                }
                if ($dto->possessionTeamEspnId === $homeId) {
                    $home += (int) $points;
                } elseif ($dto->possessionTeamEspnId === $awayId) {
                    $away += (int) $points;
                } else {
                    return null;
                }
            }
            $play['homeScore'] = $home;
            $play['awayScore'] = $away;
        }
        unset($play);

        return $home === (int) $game->home_score && $away === (int) $game->away_score ? $plays : null;
    }
}
