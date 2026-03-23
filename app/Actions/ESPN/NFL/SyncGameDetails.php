<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractStandardSyncGameDetails;
use App\Models\NFL\Game;

class SyncGameDetails extends AbstractStandardSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;
}
