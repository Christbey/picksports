<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;
use App\Models\WCBB\Game;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;
}
