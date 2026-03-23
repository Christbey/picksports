<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\NFL\UpdateLivePrediction;
use App\Models\NFL\Game;
use App\Models\NFL\Team;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;
}
