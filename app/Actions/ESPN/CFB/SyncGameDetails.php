<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;
use App\Models\CFB\Game;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;
}
