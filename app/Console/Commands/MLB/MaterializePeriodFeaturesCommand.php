<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Game;
use App\Services\MLB\MlbPeriodFeatureStore;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MaterializePeriodFeaturesCommand extends Command
{
    protected $signature = 'mlb:materialize-period-features
        {--season= : MLB season}
        {--date= : First game date in Y-m-d format}
        {--days-ahead=2 : Number of dates to include}
        {--game=* : Materialize specific game IDs}';

    protected $description = 'Persist point-in-time MLB F3/F5 features for fast API presentation and inference';

    public function handle(MlbPeriodFeatureStore $features): int
    {
        $gameIds = collect($this->option('game'))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
        $season = $this->option('season');
        $date = $this->option('date') ?: now()->toDateString();
        $daysAhead = max(1, (int) $this->option('days-ahead'));

        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->when($gameIds->isNotEmpty(), fn ($query) => $query->whereKey($gameIds))
            ->when($gameIds->isEmpty(), function ($query) use ($season, $date, $daysAhead): void {
                $query
                    ->when($season, fn ($seasonQuery) => $seasonQuery->where('season', (int) $season))
                    ->whereDate('game_date', '>=', $date)
                    ->whereDate('game_date', '<', Carbon::parse($date)->addDays($daysAhead)->toDateString());
            })
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->get();

        if ($games->isEmpty()) {
            $this->warn('No MLB games matched the requested materialization window.');

            return self::SUCCESS;
        }

        $startedAt = hrtime(true);
        $snapshots = $features->materialize($games);
        $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->info(sprintf(
            'Materialized %d immutable period feature snapshots for %d games in %.2f seconds.',
            $snapshots->count(),
            $games->count(),
            $elapsed,
        ));

        return self::SUCCESS;
    }
}
