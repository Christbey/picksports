<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;
use App\Models\CBB\Game;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;
}
