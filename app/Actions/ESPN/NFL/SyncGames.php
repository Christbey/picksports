<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncGames;
use App\Models\NFL\Game;
use App\Models\NFL\Team;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UNIQUE_GAME_KEY = 'espn_event_id';

    protected function normalizeGamePayload(array $payload): array
    {
        $normalized = parent::normalizeGamePayload($payload);
        // Summary responses put the venue in gameInfo, not header.competitions.
        $venue = data_get($payload, 'gameInfo.venue');
        if (is_array($venue) && ! empty($venue)) {
            $normalized['competitions'][0]['venue'] = $venue;
        }

        return $normalized;
    }
}
