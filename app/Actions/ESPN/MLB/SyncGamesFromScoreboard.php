<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\MLB\UpdateLivePrediction;
use App\DataTransferObjects\ESPN\MLBGameData;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;

    protected const SYNC_ORPHANED_SCHEDULED_GAMES = true;

    protected function gameDtoFromResponse(array $eventData): MLBGameData
    {
        return MLBGameData::fromEspnResponse($eventData);
    }

    protected function buildGameAttributes(object $dto, array $eventData, ?Model $homeTeam, ?Model $awayTeam): array
    {
        $attributes = parent::buildGameAttributes($dto, $eventData, $homeTeam, $awayTeam);
        [$homeCompetitor, $awayCompetitor] = $this->resolveCompetitors($eventData);

        $attributes['probable_home_pitcher_espn_id'] = $this->probablePitcherEspnId($homeCompetitor);
        $attributes['probable_away_pitcher_espn_id'] = $this->probablePitcherEspnId($awayCompetitor);

        return $attributes;
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
