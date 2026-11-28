<?php

namespace App\Support\CFB;

use App\Support\ScoreObservation;

/** A witnessed administrative boundary, not an inference from final status or end of feed. */
final class ObservedTerminalBoundary
{
    public static function forHalf(object $play, int $sourcePeriod, int $homeId, int $awayId): bool
    {
        $period = $play->period ?? null;
        if ($homeId <= 0 || $awayId <= 0 || $homeId === $awayId
            || ! in_array($sourcePeriod, [1, 2, 3, 4], true)
            || ! in_array($period, [2, 4, '2', '4'], true)
            || (int) $period !== ($sourcePeriod <= 2 ? 2 : 4)
            || ! in_array($play->clock ?? null, ['0:00', '00:00'], true)
            || ScoreObservation::known($play->home_score ?? null) === null
            || ScoreObservation::known($play->away_score ?? null) === null) {
            return false;
        }
        $type = strtolower(trim((string) ($play->play_type ?? '')));
        $types = (int) $period === 2
            ? ['end of period', 'end period', 'end of half', 'halftime']
            : ['end of period', 'end period', 'end of game'];
        if (! in_array($type, $types, true)) {
            return false;
        }
        $actor = $play->possession_team_id ?? null;
        if ($actor === null) {
            // Administrative events may be neutral; the originating play's offense is separately checked.
            return true;
        }

        return (is_int($actor) || (is_string($actor) && preg_match('/^[0-9]+$/D', $actor) === 1))
            && in_array((string) $actor, [(string) $homeId, (string) $awayId], true);
    }
}
