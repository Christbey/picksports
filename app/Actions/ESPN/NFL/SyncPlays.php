<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\FootballPlayData;
use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = FootballPlayData::class;

    protected const USE_EVENT_ID_AS_COMPETITION_ID = true;

    protected const SKIP_EMPTY_PLAY_ID = true;

    protected function fetchPlaysPayload(string $eventId): ?array
    {
        $response = $this->espnService->getPlays($eventId, $eventId);
        $plays = $response['items'] ?? null;
        if (! is_array($plays)) {
            return null;
        }

        if (isset($response['count']) && (int) $response['count'] > count($plays)) {
            throw new \RuntimeException('Incomplete NFL play feed; preserving stored plays.');
        }

        $game = Game::query()->where('espn_event_id', $eventId)->first();
        if ($game?->status === 'STATUS_FINAL' && ! collect($plays)->contains(fn (array $play): bool => (int) data_get($play, 'period.number', 0) >= 4
            && isset($play['homeScore'], $play['awayScore'])
            && (int) $play['homeScore'] === (int) $game->home_score
            && (int) $play['awayScore'] === (int) $game->away_score
        )) {
            throw new \RuntimeException('NFL play feed has not reached the final score; preserving stored plays.');
        }

        return $plays;
    }
}
