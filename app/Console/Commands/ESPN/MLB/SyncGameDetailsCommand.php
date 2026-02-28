<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Console\Commands\ESPN\AbstractSyncGameDetailsCommand;
use App\Jobs\ESPN\MLB\FetchGameDetails;
use App\Models\MLB\Game;
use Illuminate\Database\Eloquent\Collection;

class SyncGameDetailsCommand extends AbstractSyncGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-mlb-game-details';
    protected const SPORT_CODE = 'MLB';
    protected const PENDING_GAMES_DESCRIPTOR = 'past games without linescores';
    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;

    protected function pendingGames(): Collection
    {
        return Game::query()
            ->whereDate('game_date', '<', now())
            ->whereNotNull('espn_event_id')
            ->whereNull('home_linescores')
            ->orderBy('game_date', 'asc')
            ->get();
    }
}
