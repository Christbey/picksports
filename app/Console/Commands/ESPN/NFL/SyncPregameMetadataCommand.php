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
        $days = $this->positiveInteger($this->option('days-forward'));
        $limit = $this->positiveInteger($this->option('limit'));
        if ($days === null || $days > 16 || $limit === null || $limit > 64) {
            $this->error('Use --days-forward between 1 and 16 and --limit between 1 and 64.');

            return self::FAILURE;
        }
        $season = $this->option('season') === null ? null : $this->positiveInteger($this->option('season'));
        $gameId = $this->option('game-id') === null ? null : $this->positiveInteger($this->option('game-id'));
        if (($this->option('season') !== null && $season === null) || ($this->option('game-id') !== null && $gameId === null)) {
            $this->error('Use a positive integer for explicit --season and --game-id selectors.');

            return self::FAILURE;
        }
        $window = $dates->forwardWindow(null, $days);
        $games = Game::query()
            ->whereIn('season_type', NflPregameHorizon::seasonTypes())
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->whereNotNull('espn_event_id')
            ->where(fn ($query) => $dates->applyGameDateWindow($query, $window))
            ->when($season !== null, fn ($query) => $query->where('season', $season))
            ->when($gameId !== null, fn ($query) => $query->whereKey($gameId))
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

    private function positiveInteger(mixed $value): ?int
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? null : $parsed;
    }
}
