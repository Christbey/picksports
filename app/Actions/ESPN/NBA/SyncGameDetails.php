<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractStandardSyncGameDetails;
use App\Models\NBA\Game;

class SyncGameDetails extends AbstractStandardSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;
}
