<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncGameDetails;
use App\Console\Commands\ESPN\AbstractBackfillGamesCommand;
use App\DataTransferObjects\ESPN\GameData;
use App\Jobs\ESPN\CBB\FetchGameDetails;
use App\Models\CBB\Game;
use Illuminate\Database\Eloquent\Collection;

class BackfillStaleGamesCommand extends AbstractBackfillGamesCommand
{
    protected const COMMAND_NAME = 'espn:backfill-cbb-stale-games';

    protected const COMMAND_DESCRIPTION = 'Backfill past CBB games stuck in non-final status by fetching details from ESPN';

    /**
     * @return Collection<int, Game>
     */
    protected function staleGames(string $targetStatus, ?string $date, int $limit): Collection
    {
        $query = Game::query()
            ->where('game_date', '<', now()->format('Y-m-d'))
            ->whereNotIn('status', GameData::finalStatuses())
            ->whereNotNull('espn_event_id')
            ->orderBy('game_date', 'asc');

        if ($targetStatus !== 'all') {
            $query->where('status', $targetStatus);
        }

        if ($date) {
            $query->where('game_date', $date);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        /** @var Collection<int, Game> $games */
        $games = $query->get();

        return $games;
    }

    protected function syncGameByEventId(string $eventId): bool
    {
        /** @var SyncGameDetails $syncGameDetails */
        $syncGameDetails = app(SyncGameDetails::class);
        $result = $syncGameDetails->execute($eventId);

        return (bool) ($result['game_updated'] ?? false);
    }

    protected function dispatchGameByEventId(string $eventId): void
    {
        FetchGameDetails::dispatch($eventId);
    }
}
