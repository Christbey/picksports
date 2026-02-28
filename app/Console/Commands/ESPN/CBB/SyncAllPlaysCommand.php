<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractDispatchOverRecordsCommand;
use App\Jobs\ESPN\CBB\FetchPlays;
use App\Models\CBB\Game;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class SyncAllPlaysCommand extends AbstractDispatchOverRecordsCommand
{
    protected const COMMAND_NAME = 'espn:sync-all-cbb-plays';

    protected const COMMAND_DESCRIPTION = 'Dispatch jobs to sync play-by-play data for all completed CBB games';

    protected function emptyMessage(): string
    {
        return 'No completed CBB games found.';
    }

    protected function startMessage(int $count): string
    {
        return "Found {$count} completed games. Dispatching play sync jobs...";
    }

    protected function completeMessage(int $count): string
    {
        return "Dispatched {$count} play sync jobs successfully.";
    }

    protected function recordsToDispatch(): Collection
    {
        return Game::query()
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('espn_event_id')
            ->get();
    }

    protected function dispatchForRecord(Model $record): void
    {
        /** @var Game $record */
        FetchPlays::dispatch((string) $record->espn_event_id);
    }
}
