<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractStandardSyncGameDetails;

class SyncGameDetails extends AbstractStandardSyncGameDetails
{
    protected const GAME_MODEL_CLASS = \App\Models\NFL\Game::class;
}
