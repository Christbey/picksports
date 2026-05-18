<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\TeamMetric;
use App\Services\NFL\HistoricalTeamMetricCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SeedPreseasonTeamMetricsCommand extends Command
{
    protected $signature = 'nfl:seed-preseason-metrics
                            {--season= : Season to seed (defaults to nfl.season.default)}
                            {--as-of= : Date to capture as-of (defaults to today)}';

    protected $description = 'Seed preseason nfl_team_metrics for an upcoming season using prior-season carryover plus offseason signals. Useful before any games of the new season are played, so the prediction generator has non-null metrics for Week 1 forecasts.';

    public function handle(HistoricalTeamMetricCalculator $calculator): int
    {
        $season = (int) ($this->option('season') ?? config('nfl.season.default'));
        $asOf = $this->option('as-of') ? Carbon::parse($this->option('as-of')) : Carbon::today();
        $regularSeasonType = (string) config('nfl.season.types.regular', 2);

        $this->info("Seeding NFL preseason team metrics for season {$season} as of {$asOf->toDateString()}...");

        $rows = $calculator->calculateForDate($season, $asOf);
        if ($rows === []) {
            $this->warn('Historical calculator returned no rows. Check that teams exist and prior seasons have player_stats data.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();
        $seeded = 0;

        foreach ($rows as $row) {
            $attributes = [
                'team_id' => (int) $row['team_id'],
                'season' => (int) $row['season'],
                'season_type' => $regularSeasonType,
            ];

            $payload = [
                'wins' => (int) ($row['wins'] ?? 0),
                'losses' => (int) ($row['losses'] ?? 0),
                'predictive_rating' => $row['predictive_rating'] ?? null,
                'future_strength_of_schedule' => $row['future_strength_of_schedule'] ?? null,
                'recent_form_rating' => $row['recent_form_rating'] ?? null,
                'injury_total_adjustment' => $row['injury_total_adjustment'] ?? null,
                'calculation_date' => $asOf->toDateString(),
            ];

            TeamMetric::updateOrCreate($attributes, $payload);
            $seeded++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Seeded {$seeded} team_metric rows for season {$season} season_type {$regularSeasonType}.");

        return self::SUCCESS;
    }
}
