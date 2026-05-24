<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractStandardSyncGameDetails;
use App\Models\WNBA\Game;

class SyncGameDetails extends AbstractStandardSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;
}
