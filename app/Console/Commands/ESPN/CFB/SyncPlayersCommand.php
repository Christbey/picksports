<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSyncPlayersCommand;
use App\Jobs\ESPN\CFB\FetchPlayers;
use App\Models\CFB\Team;

class SyncPlayersCommand extends AbstractSyncPlayersCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-players';

    protected const SPORT_CODE = 'CFB';

    protected const PLAYERS_SYNC_JOB_CLASS = FetchPlayers::class;

    protected function dispatchPlayersSync(?string $teamEspnId): void
    {
        if ($teamEspnId !== null) {
            FetchPlayers::dispatch($teamEspnId);

            return;
        }

        Team::query()
            ->whereNotNull('espn_id')
            ->pluck('espn_id')
            ->filter()
            ->each(fn ($espnId) => FetchPlayers::dispatch((string) $espnId));
    }
}
