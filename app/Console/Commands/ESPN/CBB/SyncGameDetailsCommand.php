<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\CBB\FetchGameDetails;
use App\Models\CBB\Game;
use Illuminate\Database\Eloquent\Collection;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-game-details';

    protected const SPORT_CODE = 'CBB';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;

    protected function pendingGamesDescriptor(): string
    {
        return 'CBB games missing player stats, team stats, or final play data';
    }

    protected function pendingGames(): Collection
    {
        return Game::query()
            ->whereNotNull('espn_event_id')
            ->where(function ($query) {
                $query
                    ->whereDoesntHave('playerStats')
                    ->orWhereDoesntHave('teamStats')
                    ->orWhere(function ($playQuery) {
                        $playQuery
                            ->where('status', 'STATUS_FINAL')
                            ->whereDoesntHave('plays');
                    });
            })
            ->orderBy('game_date', 'asc')
            ->get();
    }
}
