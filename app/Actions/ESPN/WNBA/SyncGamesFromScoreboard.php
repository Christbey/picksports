<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\WNBA\UpdateLivePrediction;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = \App\Models\WNBA\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WNBA\Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;
}
