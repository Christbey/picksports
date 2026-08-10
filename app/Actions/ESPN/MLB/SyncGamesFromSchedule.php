<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncGamesFromSchedule;
use App\Actions\ESPN\MLB\Concerns\ResolvesMlbGameDateParts;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Support\MlbSeasonTypeResolver;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromSchedule extends AbstractSyncGamesFromSchedule
{
    use ResolvesMlbGameDateParts;

    protected const GAME_MODEL_CLASS = Game::class;

    protected function resolveTeams(GameData $dto, array $rawGame): array
    {
        return [
            Team::query()->where('espn_id', $dto->homeTeamEspnId)->first(),
            Team::query()->where('espn_id', $dto->awayTeamEspnId)->first(),
        ];
    }

    protected function gameAttributes(GameData $dto, array $rawGame, Model $homeTeam, Model $awayTeam): array
    {
        $dateParts = $this->resolveMlbGameDateParts($rawGame);

        return [
            'espn_event_id' => $dto->espnEventId,
            'espn_uid' => $rawGame['uid'] ?? null,
            'season' => $dto->season,
            'week' => $dto->week,
            'season_type' => MlbSeasonTypeResolver::normalize(
                seasonType: data_get($rawGame, 'season.type', $dto->seasonType),
                week: $dto->week,
                gameDate: $dateParts['game_date'],
                season: $dto->season,
            ),
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

    /**
     * @return array<string,mixed>
     */
    protected function partialGameAttributes(GameData $dto, array $rawGame, ?Model $homeTeam, ?Model $awayTeam): array
    {
        $attributes = parent::partialGameAttributes($dto, $rawGame, $homeTeam, $awayTeam);
        $dateParts = $this->resolveMlbGameDateParts($rawGame);

        $attributes['season_type'] = MlbSeasonTypeResolver::normalize(
            seasonType: data_get($rawGame, 'season.type', $dto->seasonType),
            week: $dto->week,
            gameDate: $dateParts['game_date'],
            season: $dto->season,
        );
        $attributes['game_date'] = $dateParts['game_date'];
        $attributes['game_time'] = $dateParts['game_time'];

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function existingGameAttributes(
        GameData $dto,
        array $rawGame,
        Model $homeTeam,
        Model $awayTeam,
        Model $existingGame,
    ): array {
        $attributes = parent::existingGameAttributes($dto, $rawGame, $homeTeam, $awayTeam, $existingGame);

        if ((string) $existingGame->status !== 'STATUS_FINAL') {
            return $attributes;
        }

        foreach (['home_score', 'away_score', 'home_linescores', 'away_linescores'] as $attribute) {
            if ($existingGame->{$attribute} !== null) {
                $attributes[$attribute] = $existingGame->{$attribute};
            }
        }

        return $attributes;
    }
}
