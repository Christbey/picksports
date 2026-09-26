<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\Player;

class CfbPlayerIdentityResolver
{
    public function resolve(array $payload, Game $game): void
    {
        $teams = collect([$game->homeTeam, $game->awayTeam])->keyBy('espn_id');
        foreach ($payload['boxscore']['players'] ?? [] as $row) {
            $team = $teams->get(data_get($row, 'team.id'));
            if (! $team) {
                throw new \DomainException('Player source is not a game participant');
            }
            foreach ($row['statistics'] ?? [] as $category) {
                foreach ($category['athletes'] ?? [] as $row) {
                    $athlete = $row['athlete'] ?? [];
                    $id = (string) ($athlete['id'] ?? '');
                    if ((int) $id <= 0) {
                        continue;
                    } // Team credits are retained on team stats.
                    $name = $athlete['displayName'] ?? $athlete['fullName'] ?? null;
                    if (! ctype_digit($id) || ! $name) {
                        throw new \DomainException('Unresolvable source athlete');
                    }
                    Player::firstOrCreate(['espn_id' => $id], ['team_id' => $team->id, 'full_name' => $name,
                        'first_name' => $athlete['firstName'] ?? null, 'last_name' => $athlete['lastName'] ?? null,
                        'position' => data_get($athlete, 'position.abbreviation')]);
                }
            }
        }
    }
}
