<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\NBA\UpdateLivePrediction;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = \App\Models\NBA\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NBA\Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;
    protected const SYNC_ORPHANED_IN_PROGRESS_GAMES = true;
}
