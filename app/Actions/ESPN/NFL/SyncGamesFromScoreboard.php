<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\NFL\UpdateLivePrediction;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = \App\Models\NFL\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NFL\Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;
}
