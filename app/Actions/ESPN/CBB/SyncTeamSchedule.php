<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncGamesFromSchedule;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use Illuminate\Database\Eloquent\Model;

class SyncTeamSchedule extends AbstractSyncGamesFromSchedule
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected function resolveTeams(GameData $dto, array $rawGame): array
    {
        return [
            Team::query()->where('espn_id', $dto->homeTeamEspnId)->first(),
            Team::query()->where('espn_id', $dto->awayTeamEspnId)->first(),
        ];
    }

    protected function shouldUpdateExistingGame(Model $existingGame, GameData $dto, array $rawGame): bool
    {
        return ! in_array($existingGame->status, GameData::finalStatuses(), true);
    }

    protected function existingGameAttributes(
        GameData $dto,
        array $rawGame,
        Model $homeTeam,
        Model $awayTeam,
        Model $existingGame
    ): array {
        return [
            'status' => $this->effectiveStatus($dto, $rawGame),
            'home_score' => $dto->homeScore,
            'away_score' => $dto->awayScore,
            'home_linescores' => $dto->homeLinescores,
            'away_linescores' => $dto->awayLinescores,
            'period' => $dto->period,
            'game_clock' => $dto->gameClock,
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
            'status' => $this->effectiveStatus($dto, $rawGame),
            'period' => $dto->period,
            'game_clock' => $dto->gameClock,
            'venue_name' => $dto->venueName,
            'venue_city' => $dto->venueCity,
            'venue_state' => $dto->venueState,
            'broadcast_networks' => $dto->broadcastNetworks,
        ];
    }

    protected function effectiveStatus(GameData $dto, array $rawGame): string
    {
        $gameDate = GameData::extractDateParts($rawGame['date'] ?? null)['game_date'];

        if ($gameDate && $gameDate < now()->format('Y-m-d') && $dto->status === 'STATUS_SCHEDULED') {
            return 'STATUS_FINAL';
        }

        return $dto->status;
    }
}
