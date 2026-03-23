<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\MLB\UpdateLivePrediction;
use App\DataTransferObjects\ESPN\MLBGameData;
use App\Models\MLB\Game;
use App\Models\MLB\Team;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;

    protected function gameDtoFromResponse(array $eventData): MLBGameData
    {
        return MLBGameData::fromEspnResponse($eventData);
    }
}
