<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractDispatchOverRecordsCommand;
use App\Jobs\ESPN\CBB\FetchTeamSchedule;
use App\Models\CBB\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class SyncAllTeamSchedulesCommand extends AbstractDispatchOverRecordsCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-all-team-schedules';

    protected const COMMAND_DESCRIPTION = 'Sync full schedules for all CBB teams from ESPN';

    protected function emptyMessage(): string
    {
        return 'No CBB teams found.';
    }

    protected function startMessage(int $count): string
    {
        return "Syncing schedules for {$count} teams...";
    }

    protected function completeMessage(int $count): string
    {
        return "Dispatched {$count} team schedule sync jobs successfully.";
    }

    protected function recordsToDispatch(): Collection
    {
        return Team::query()->get();
    }

    protected function dispatchForRecord(Model $record): void
    {
        /** @var Team $record */
        FetchTeamSchedule::dispatch((string) $record->espn_id);
    }
}
