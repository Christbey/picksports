<?php

namespace App\Services\NFL;

final class NflQuarterbackStatsSummary
{
    /**
     * Both local PlayerStat models and projected nflverse rows use these fields.
     * The caller owns regular-season/as-of filtering and source-specific defaults.
     *
     * @param  iterable<object>  $rows
     * @return array<string, int|float>
     */
    public function summarize(iterable $rows, ?int $currentTeamId = null): array
    {
        $games = $currentTeamGames = $attempts = $yards = $touchdowns = $interceptions = $sacks = $rushYards = 0;

        foreach ($rows as $row) {
            $games++;
            $currentTeamGames += $currentTeamId !== null && ($row->team_id ?? null) == $currentTeamId ? 1 : 0;
            $attempts += (int) ($row->passing_attempts ?? 0);
            $yards += (int) ($row->passing_yards ?? 0);
            $touchdowns += (int) ($row->passing_touchdowns ?? 0);
            $interceptions += (int) ($row->interceptions_thrown ?? 0);
            $sacks += (int) ($row->sacks_taken ?? 0);
            $rushYards += (int) ($row->rushing_yards ?? 0);
        }

        $dropbacks = $attempts + $sacks;

        return [
            'games' => $games,
            ...($currentTeamId !== null ? ['current_team_games' => $currentTeamGames] : []),
            'attempts' => $attempts,
            'yards' => $yards,
            'touchdowns' => $touchdowns,
            'interceptions' => $interceptions,
            'sacks' => $sacks,
            'rush_yards' => $rushYards,
            'yards_per_attempt' => $attempts > 0 ? $yards / $attempts : 0.0,
            'td_rate' => $attempts > 0 ? $touchdowns / $attempts : 0.0,
            'int_rate' => $attempts > 0 ? $interceptions / $attempts : 0.0,
            'sack_rate' => $dropbacks > 0 ? $sacks / $dropbacks : 0.0,
            'rush_yards_per_game' => $games > 0 ? $rushYards / $games : 0.0,
        ];
    }
}
