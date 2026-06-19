<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\MLB\FetchGameDetails;
use App\Models\MLB\Game;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-mlb-game-details';

    protected const SPORT_CODE = 'MLB';

    protected const PENDING_GAMES_DESCRIPTOR = 'MLB games missing player stats';

    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const REQUIRES_FINAL_STATUS = true;

    protected function includesMissingFinalScores(): bool
    {
        return true;
    }
}
