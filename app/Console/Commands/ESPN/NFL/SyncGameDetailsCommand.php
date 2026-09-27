<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\NFL\FetchGameDetails;
use App\Models\NFL\Game;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-game-details';

    protected const SPORT_CODE = 'NFL';

    protected const REQUIRES_FINAL_STATUS = true;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;

    protected function whereMissingDetails($query, string $gameModel): void
    {
        parent::whereMissingDetails($query, $gameModel);

        // A partial live payload has rows, but has never reached the final score.
        $query->orWhereDoesntHave('plays', fn ($plays) => $plays
            ->where('period', '>=', 4)
            ->whereColumn('nfl_plays.home_score', 'nfl_games.home_score')
            ->whereColumn('nfl_plays.away_score', 'nfl_games.away_score'));
    }
}
