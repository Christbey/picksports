<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractSyncGames;
use App\DataTransferObjects\ESPN\GameData;
use Illuminate\Database\Eloquent\Model;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = \App\Models\NBA\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NBA\Team::class;

    protected function buildGameAttributes(GameData $dto, array $gameData, Model $homeTeam, Model $awayTeam): array
    {
        $dateParts = GameData::extractDateParts($gameData['date'] ?? null);

        return [
            'espn_event_id' => $dto->espnEventId,
            'espn_uid' => $gameData['uid'] ?? null,
            'season' => $dto->season,
            'week' => $dto->week,
            'season_type' => $dto->seasonType,
            'game_date' => $dateParts['game_date'],
            'game_time' => $dateParts['game_time'],
            'name' => $dto->name,
            'short_name' => $dto->shortName,
            'home_team_id' => $homeTeam->getKey(),
            'away_team_id' => $awayTeam->getKey(),
            'home_score' => $dto->homeScore,
            'away_score' => $dto->awayScore,
            'home_linescores' => $dto->homeLinescores,
            'away_linescores' => $dto->awayLinescores,
            'status' => $dto->status,
            'period' => $dto->period,
            'game_clock' => $dto->gameClock,
            'venue_name' => $dto->venueName,
            'venue_city' => $dto->venueCity,
            'venue_state' => $dto->venueState,
            'broadcast_networks' => $dto->broadcastNetworks,
        ];
    }
}
