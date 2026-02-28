<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = \App\Models\CBB\Game::class;
}
