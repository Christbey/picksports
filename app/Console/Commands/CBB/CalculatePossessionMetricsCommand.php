<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\CalculatePossessionMetrics;
use Illuminate\Console\Command;

class CalculatePossessionMetricsCommand extends Command
{
    protected $signature = 'cbb:calculate-possession-metrics
        {--season= : Limit to season (defaults to configured season)}
        {--game_id= : Rebuild metrics using only a single game}
        {--rebuild : Delete existing season metrics before recalculating}
        {--chunk=200 : Number of final games to process per batch}
        {--limit-games=0 : Limit the number of final games processed (0 = all)}';

    protected $description = 'Calculate team-level possession value metrics for CBB from play-by-play data';

    public function handle(CalculatePossessionMetrics $action): int
    {
        $season = (int) ($this->option('season') ?: config('cbb.season.default'));
        $gameId = $this->option('game_id') ? (int) $this->option('game_id') : null;
        $rebuild = (bool) $this->option('rebuild');
        $chunkSize = max(25, (int) $this->option('chunk'));
        $limitGames = max(0, (int) $this->option('limit-games'));

        $this->info("Calculating CBB possession metrics for season {$season}".($gameId ? " using game {$gameId}" : '').'...');

        $rows = $action->execute(
            $season,
            $gameId,
            $rebuild,
            $chunkSize,
            $limitGames > 0 ? $limitGames : null
        );

        $this->info('Calculated '.count($rows).' team possession metric rows.');

        if ($rows !== []) {
            $preview = collect($rows)
                ->sortByDesc('rolling_net_points_per_possession')
                ->take(10)
                ->map(fn ($row) => [
                    $row['team_id'],
                    $row['rolling_offensive_points_per_possession'],
                    $row['rolling_defensive_points_per_possession_allowed'],
                    $row['rolling_net_points_per_possession'],
                    $row['possessions_per_game'],
                ]);

            $this->table(['Team ID', 'Rolling Off PPP', 'Rolling Def PPP', 'Rolling Net PPP', 'Poss/Game'], $preview);
        }

        return self::SUCCESS;
    }
}
