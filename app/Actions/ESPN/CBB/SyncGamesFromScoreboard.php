<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\CBB\UpdateLivePrediction;
use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = \App\Models\CBB\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CBB\Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;
    protected const SYNC_ORPHANED_IN_PROGRESS_GAMES = true;
}
