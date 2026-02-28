<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractStandardSyncGameDetails;

class SyncGameDetails extends AbstractStandardSyncGameDetails
{
    protected const GAME_MODEL_CLASS = \App\Models\CFB\Game::class;
}
