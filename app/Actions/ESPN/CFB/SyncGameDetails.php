<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = \App\Models\CFB\Game::class;
}
