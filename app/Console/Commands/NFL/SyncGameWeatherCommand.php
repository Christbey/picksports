<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\GameWeather;
use App\Services\NFL\GameWeatherService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Console\Command;

class SyncGameWeatherCommand extends Command
{
    protected $signature = 'nfl:sync-game-weather
        {--season= : NFL season to sync}
        {--from-date= : Start date YYYY-MM-DD}
        {--to-date= : End date YYYY-MM-DD}
        {--days-back= : Sync games this many days before today}
        {--days-forward= : Sync games this many days after today}
        {--game-id= : Sync a single game id}
        {--force : Refresh existing rows}';

    protected $description = 'Sync kickoff weather for NFL games';

    public function handle(GameWeatherService $weatherService, SportsDateWindowService $dateWindows): int
    {
        $query = Game::query()
            ->with(['homeTeam', 'awayTeam'])
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

        foreach ($games as $game) {
            if (! $this->option('force') && GameWeather::query()->where('game_id', $game->id)->exists()) {
                $skipped++;

                continue;
            }

            $weather = $weatherService->fetch($game);
            if ($weather === null) {
                $skipped++;

                continue;
            }

            $row = GameWeather::query()->updateOrCreate(['game_id' => $game->id], $weather);
            $row->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->info("NFL weather sync complete. Created {$created}, updated {$updated}, skipped {$skipped}.");

        return Command::SUCCESS;
    }
}
