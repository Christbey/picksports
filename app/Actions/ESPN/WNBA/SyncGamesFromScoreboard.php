<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\WNBA\UpdateLivePrediction;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;

    protected const SYNC_ORPHANED_SCHEDULED_GAMES = true;
}
