<?php

namespace App\Actions\ESPN\MLB\Concerns;

use App\Models\MLB\Team;

trait ResolvesMlbBoxscoreTeams
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function boxscoreSection(array $gameData, string $section): array
    {
        $items = $gameData['boxscore'][$section] ?? null;

        return is_array($items) ? $items : [];
    }

    protected function resolveTeamFromBoxscore(array $teamData): ?Team
    {
        $teamEspnId = $teamData['team']['id'] ?? null;

        if (! $teamEspnId) {
            return null;
        }

        return Team::query()->where('espn_id', $teamEspnId)->first();
    }
}
