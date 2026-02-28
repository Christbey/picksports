<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncGamesFromSchedule;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\MLB\Team;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromSchedule extends AbstractSyncGamesFromSchedule
{
    protected const GAME_MODEL_CLASS = \App\Models\MLB\Game::class;

    protected function resolveTeams(GameData $dto, array $rawGame): array
    {
        return [
            Team::query()->where('espn_id', $dto->homeTeamEspnId)->first(),
            Team::query()->where('espn_id', $dto->awayTeamEspnId)->first(),
        ];
    }

    protected function gameAttributes(GameData $dto, array $rawGame, Model $homeTeam, Model $awayTeam): array
    {
        $dateParts = GameData::extractDateParts($rawGame['date'] ?? null);

        return [
            'espn_event_id' => $dto->espnEventId,
            'espn_uid' => $rawGame['uid'] ?? null,
            'season' => $dto->season,
            'week' => $dto->week,
            'season_type' => $dto->seasonType,
            'game_date' => $dateParts['game_date'],
            'game_time' => $dateParts['game_time'],
            'name' => $dto->name,
            'short_name' => $dto->shortName,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'home_score' => $dto->homeScore,
            'away_score' => $dto->awayScore,
            'home_linescores' => $dto->homeLinescores,
            'away_linescores' => $dto->awayLinescores,
            'status' => $dto->status,
            'inning' => $dto->period,
            'inning_half' => null,
            'balls' => null,
            'strikes' => null,
            'outs' => null,
            'venue_name' => $dto->venueName,
            'venue_city' => $dto->venueCity,
            'venue_state' => $dto->venueState,
            'broadcast_networks' => $dto->broadcastNetworks,
        ];
    }
}
