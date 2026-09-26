<?php

namespace App\Services\NFL;

/** Relative matchup strength; not a calibrated point or yardage prediction. */
final class NflRushingMatchup
{
    public function edges(array $home, array $away): ?array
    {
        foreach ([$home, $away] as $profile) {
            foreach (['off_rush_yards_per_attempt', 'def_rush_yards_allowed_per_attempt'] as $key) {
                if (! is_numeric($profile[$key] ?? null) || ! is_finite((float) $profile[$key])) {
                    return null;
                }
            }
            foreach (['off_rush_attempts', 'def_rush_attempts'] as $key) {
                if (! is_numeric($profile[$key] ?? null) || ! is_finite((float) $profile[$key]) || $profile[$key] <= 0) {
                    return null;
                }
            }
        }
        $homeOff = (float) $home['off_rush_yards_per_attempt'];
        $awayOff = (float) $away['off_rush_yards_per_attempt'];
        $homeAllowed = (float) $home['def_rush_yards_allowed_per_attempt'];
        $awayAllowed = (float) $away['def_rush_yards_allowed_per_attempt'];
        // A common league reference cancels in home-minus-away. Center the
        // displayed edges on this matchup's mean, without inventing a league rate.
        $reference = ($homeOff + $awayOff + $homeAllowed + $awayAllowed) / 4;

        return [
            'formula' => 'offense_plus_opponent_weakness_v1',
            'reference_yards_per_attempt' => $reference,
            'home' => ($homeOff - $reference) + ($awayAllowed - $reference),
            'away' => ($awayOff - $reference) + ($homeAllowed - $reference),
            // Totals are deliberately not changed in this spread-only correction.
            'legacy_home' => $homeOff - $awayAllowed,
            'legacy_away' => $awayOff - $homeAllowed,
        ];
    }
}
