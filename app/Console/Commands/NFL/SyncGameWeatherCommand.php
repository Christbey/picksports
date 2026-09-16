<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\GameWeather;
use App\Services\NFL\GameWeatherService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Console\Command;
use Throwable;

class SyncGameWeatherCommand extends Command
{
    protected $signature = 'nfl:sync-game-weather
        {--season= : NFL season to sync}
        {--from-date= : Start date YYYY-MM-DD}
        {--to-date= : End date YYYY-MM-DD}
        {--days-back= : Sync games this many days before today}
        {--days-forward= : Sync games this many days after today}
        {--game-id= : Sync a single game id}
        {--force : Refresh even recent rows}';

    protected $description = 'Sync kickoff weather for NFL games';

    public function handle(GameWeatherService $weatherService, SportsDateWindowService $dateWindows): int
    {
        $query = Game::query()
            ->with(['homeTeam', 'awayTeam', 'weather'])
            ->when($this->option('game-id'), fn ($query, $id) => $query->whereKey((int) $id))
            ->when($this->option('season'), fn ($query, $season) => $query->where('season', (int) $season));

        $fromDate = $this->option('from-date')
            ?: ($this->option('days-back') !== null ? now()->subDays((int) $this->option('days-back'))->toDateString() : null);
        $toDate = $this->option('to-date')
            ?: ($this->option('days-forward') !== null ? now()->addDays((int) $this->option('days-forward'))->toDateString() : null);

        if (is_string($fromDate) && is_string($toDate)) {
            $dateWindows->applyGameDateWindow($query, $dateWindows->forRange($fromDate, $toDate));
        } else {
            $query
                ->when($fromDate, fn ($query, $date) => $query->whereDate('game_date', '>=', $date))
                ->when($toDate, fn ($query, $date) => $query->whereDate('game_date', '<=', $date));
        }

        $games = $query
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->get();
        if ($games->isEmpty()) {
            $this->warn('No NFL games matched the weather sync scope.');

            return Command::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($games as $game) {
            $freshAfter = now()->subHours(max(1, (int) config('validation.thresholds.weather_completeness.stale_after_hours', 8)));
            if (! $this->option('force') && $game->weather?->updated_at?->gt($freshAfter)) {
                $skipped++;

                continue;
            }

            try {
                $weather = $weatherService->fetch($game);
                if ($weather === null) {
                    $failed++;
                    $this->warn("Game {$game->id}: no usable kickoff weather returned; existing data preserved.");

                    continue;
                }
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->warn("Game {$game->id}: weather refresh failed (".class_basename($exception).'); existing data preserved.');

                continue;
            }

            $row = GameWeather::query()->updateOrCreate(['game_id' => $game->id], $weather);
            if ($row->wasRecentlyCreated) {
                $created++;

                continue;
            }

            // A successful forced refresh is fresh even when the provider returns
            // values identical to the stored forecast (common for indoor venues).
            if (! $row->wasChanged('updated_at')) {
                $row->touch();
            }

            $updated++;
        }

        $this->info("NFL weather sync complete. Created {$created}, updated {$updated}, skipped {$skipped}.");
        if ($failed > 0) {
            $this->error("Failed to refresh {$failed} NFL weather record(s).");
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
