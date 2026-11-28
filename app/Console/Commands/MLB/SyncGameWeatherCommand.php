<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\GameWeather;
use App\Services\MLB\GameWeatherService;
use App\Support\MLB\MlbGameStart;
use App\Support\MLB\MlbWeatherTime;
use Illuminate\Console\Command;

class SyncGameWeatherCommand extends Command
{
    protected $signature = 'mlb:sync-game-weather
        {--season= : MLB season to sync}
        {--from-date= : Start date YYYY-MM-DD}
        {--to-date= : End date YYYY-MM-DD}
        {--days-back= : Sync games this many days before today}
        {--days-forward= : Sync games this many days after today}
        {--game-id= : Sync a single game id}
        {--force : Refresh existing rows}';

    protected $description = 'Sync first-pitch weather for MLB games';

    public function handle(GameWeatherService $weatherService): int
    {
        $query = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->when($this->option('game-id'), fn ($query, $id) => $query->whereKey((int) $id))
            ->when($this->option('season'), fn ($query, $season) => $query->where('season', (int) $season))
            ->when($this->option('from-date'), fn ($query, $date) => $query->whereDate('game_date', '>=', $date))
            ->when($this->option('to-date'), fn ($query, $date) => $query->whereDate('game_date', '<=', $date))
            ->when($this->option('from-date') === null && $this->option('days-back') !== null, function ($query): void {
                $query->whereDate('game_date', '>=', now()->subDays((int) $this->option('days-back'))->toDateString());
            })
            ->when($this->option('to-date') === null && $this->option('days-forward') !== null, function ($query): void {
                $query->whereDate('game_date', '<=', now()->addDays((int) $this->option('days-forward'))->toDateString());
            })
            ->orderBy('game_date')
            ->orderBy('game_time');

        $games = $query->get();
        if ($games->isEmpty()) {
            $this->warn('No MLB games matched the weather sync scope.');

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

            if ((new GameWeather)->getConnection()->getName() !== $game->getConnection()->getName()) {
                throw new \RuntimeException('MLB weather projection requires one coherent database connection.');
            }
            $row = $game->getConnection()->transaction(function () use ($game, $weather): ?GameWeather {
                $current = $game->newQuery()->lockForUpdate()->find($game->id);
                if ($current === null || MlbGameStart::context($current) !== MlbGameStart::context($game)) {
                    return null;
                }
                if (MlbGameStart::for($current) !== null && MlbWeatherTime::marker($current, new GameWeather($weather + ['game_id' => $game->id])) === null) {
                    return null;
                }

                return GameWeather::query()->updateOrCreate(['game_id' => $game->id], $weather);
            });
            if ($row === null) {
                $skipped++;

                continue;
            }
            $row->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->info("MLB weather sync complete. Created {$created}, updated {$updated}, skipped {$skipped}.");

        return Command::SUCCESS;
    }
}
