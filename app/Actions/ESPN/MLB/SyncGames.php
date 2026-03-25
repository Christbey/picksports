<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncGames;
use App\Actions\ESPN\MLB\Concerns\ResolvesMlbGameDateParts;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Support\MlbSeasonTypeResolver;
use Illuminate\Database\Eloquent\Model;

class SyncGames extends AbstractSyncGames
{
    use ResolvesMlbGameDateParts;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected function buildGameAttributes(GameData $dto, array $gameData, ?Model $homeTeam, ?Model $awayTeam): array
    {
        $dateParts = $this->resolveMlbGameDateParts($gameData);
        [$homeCompetitor, $awayCompetitor] = $this->resolveCompetitors($gameData);

        return [
            'espn_event_id' => $dto->espnEventId,
            'espn_uid' => $gameData['uid'] ?? null,
            'season' => $dto->season,
            'week' => $dto->week,
            'season_type' => MlbSeasonTypeResolver::normalize(
                seasonType: data_get($gameData, 'season.type', $dto->seasonType),
                week: $dto->week,
                gameDate: $dateParts['game_date'],
                season: $dto->season,
            ),
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
            'inning' => $dto->period,
            'inning_half' => null,
            'balls' => null,
            'strikes' => null,
            'outs' => null,
            'probable_home_pitcher_espn_id' => $this->probablePitcherEspnId($homeCompetitor),
            'probable_away_pitcher_espn_id' => $this->probablePitcherEspnId($awayCompetitor),
            'venue_name' => $dto->venueName,
            'venue_city' => $dto->venueCity,
            'venue_state' => $dto->venueState,
            'broadcast_networks' => $dto->broadcastNetworks,
        ];
    }

    /**
     * @param  array<string, mixed>  $competitor
     */
    private function probablePitcherEspnId(array $competitor): ?string
    {
        $probables = $competitor['probables'] ?? null;

        if (! is_array($probables)) {
            return null;
        }

        foreach ($probables as $probable) {
            $playerId = data_get($probable, 'playerId');
            if (is_scalar($playerId) && (string) $playerId !== '') {
                return (string) $playerId;
            }

            $athleteId = data_get($probable, 'athlete.id');
            if (is_scalar($athleteId) && (string) $athleteId !== '') {
                return (string) $athleteId;
            }
        }

        return null;
    }
}
