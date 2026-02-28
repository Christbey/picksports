<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = \App\Models\WCBB\Game::class;
}
