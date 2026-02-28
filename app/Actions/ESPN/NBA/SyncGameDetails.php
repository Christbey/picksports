<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractStandardSyncGameDetails;

class SyncGameDetails extends AbstractStandardSyncGameDetails
{
    protected const GAME_MODEL_CLASS = \App\Models\NBA\Game::class;
}
