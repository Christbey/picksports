<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\CalculatePossessionMetrics;
use App\Support\BasketballSeason;
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
        $seasonValue = $this->input->hasParameterOption('--season', true)
            ? $this->option('season') : (config('cbb.season.default') ?? BasketballSeason::forDate());
        $season = filter_var($seasonValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $gameId = $this->input->hasParameterOption('--game_id', true)
            ? filter_var($this->option('game_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        $limitGames = filter_var($this->option('limit-games'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($season === false || $gameId === false || $limitGames === false) {
            $this->error('Season and game_id must be positive integers; limit-games must be a nonnegative integer.');

            return self::FAILURE;
        }
        $rebuild = (bool) $this->option('rebuild');
        $chunkSize = max(25, (int) $this->option('chunk'));

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
