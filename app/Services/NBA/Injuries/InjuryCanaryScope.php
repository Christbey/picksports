<?php

namespace App\Services\NBA\Injuries;

use App\Models\NBA\Game;
use Carbon\CarbonImmutable;

/** An explicit two-team, expiring scope; an empty or invalid policy admits nobody. */
class InjuryCanaryScope
{
    public function allowsTeam(int $teamId): bool
    {
        $ids = config('nba.injuries.canary_team_ids', []);
        $until = config('nba.injuries.canary_expires_at');
        if (! is_array($ids) || $ids === [] || count($ids) > 2
            || ! is_string($until) || ! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $until)) {
            return false;
        }
        foreach ($ids as $id) {
            if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id) || (int) $id < 1) {
                return false;
            }
        }
        try {
            return CarbonImmutable::parse($until)->isFuture() && in_array($teamId, array_map('intval', $ids), true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function allowsGame(Game $game): bool
    {
        return $game->home_team_id !== $game->away_team_id
            && $this->allowsTeam((int) $game->home_team_id) && $this->allowsTeam((int) $game->away_team_id);
    }
}
