<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncGamesFromSchedule;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Support\CFB\CfbWeek;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromSchedule extends AbstractSyncGamesFromSchedule
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
            'week' => $this->productWeek($dto, $rawGame),
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
            'week' => $this->productWeek($dto, $rawGame),
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
            'neutral_site' => (bool) data_get($rawGame, 'competitions.0.neutralSite', false),
            'conference_game' => (bool) data_get($rawGame, 'competitions.0.conferenceCompetition', false),
        ];
    }

    protected function effectiveStatus(GameData $dto, array $rawGame): string
    {
        return $dto->status;
    }

    protected function extraPartialGameAttributes(GameData $dto, array $rawGame, ?Model $homeTeam, ?Model $awayTeam): array
    {
        return [
            'week' => $this->productWeek($dto, $rawGame),
        ];
    }

    private function productWeek(GameData $dto, array $rawGame): int
    {
        $dateParts = GameData::extractDateParts($rawGame['date'] ?? $dto->gameDate);

        return CfbWeek::productWeekForGame(
            $dto->season,
            $dto->seasonType,
            $dto->week,
            $dateParts['game_date'],
            $dateParts['game_time'],
        );
    }
}
