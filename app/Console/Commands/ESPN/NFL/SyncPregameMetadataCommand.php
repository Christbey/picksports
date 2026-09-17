<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Actions\ESPN\NFL\SyncGameMetadata;
use App\Models\NFL\Game;
use App\Services\NFL\Predictions\NflPregameHorizon;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Console\Command;
use Throwable;

class SyncPregameMetadataCommand extends Command
{
    protected $signature = 'espn:sync-nfl-pregame-metadata
        {--season= : Optional NFL season filter}
        {--days-forward=8 : Include through this many business dates after today, from 1 to 16}
        {--limit=32 : Maximum summaries fetched, from 1 to 64}
        {--game-id= : Optional NFL game ID within the upcoming horizon}';

    protected $description = 'Refresh upcoming NFL venue and matchup metadata without fetching plays or box scores';

    public function handle(SyncGameMetadata $sync, SportsDateWindowService $dates): int
    {
        $days = (int) $this->option('days-forward');
        $limit = (int) $this->option('limit');
        if ($days < 1 || $days > 16 || $limit < 1 || $limit > 64) {
            $this->error('Use --days-forward between 1 and 16 and --limit between 1 and 64.');

            return self::FAILURE;
        }
        $window = $dates->forwardWindow(null, $days);
        $games = Game::query()
            ->whereIn('season_type', NflPregameHorizon::seasonTypes())
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->whereNotNull('espn_event_id')
            ->where(fn ($query) => $dates->applyGameDateWindow($query, $window))
            ->when($this->option('season'), fn ($query, $season) => $query->where('season', (int) $season))
            ->when($this->option('game-id'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderByRaw('CASE WHEN venue_name IS NULL OR venue_city IS NULL THEN 0 ELSE 1 END')
            ->orderBy('game_date')->orderBy('game_time')->get()
            ->filter(function (Game $game) use ($dates): bool {
                $kickoff = $dates->gameDateTimeUtc($game->game_date, $game->game_time);

                return $kickoff && $kickoff->isFuture();
            });
        $deferred = max(0, $games->count() - $limit);
        $updated = 0;
        $unchanged = 0;
        $failed = 0;
        foreach ($games->take($limit) as $game) {
            try {
                $sync->execute($game) ? $updated++ : $unchanged++;
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->warn("Game {$game->id}: pregame metadata unavailable (".class_basename($exception).').');
            }
        }
        $this->info("NFL pregame metadata: updated {$updated}, unchanged {$unchanged}, failed {$failed}, deferred {$deferred}.");

        return $failed > 0 || $deferred > 0 ? self::FAILURE : self::SUCCESS;
    }
}
