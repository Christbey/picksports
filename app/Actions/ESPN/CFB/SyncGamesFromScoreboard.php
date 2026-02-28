<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\CFB\UpdateLivePrediction;
use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = \App\Models\CFB\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;
    protected const SYNC_ORPHANED_IN_PROGRESS_GAMES = true;
}
