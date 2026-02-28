<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\MLB\UpdateLivePrediction;
use App\DataTransferObjects\ESPN\MLBGameData;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = \App\Models\MLB\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\MLB\Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;

    protected function gameDtoFromResponse(array $eventData): MLBGameData
    {
        return MLBGameData::fromEspnResponse($eventData);
    }
}
