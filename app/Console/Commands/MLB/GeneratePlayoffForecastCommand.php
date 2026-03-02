<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\GeneratePlayoffForecast;
use Illuminate\Console\Command;

class GeneratePlayoffForecastCommand extends Command
{
    protected $signature = 'mlb:generate-playoff-forecast
        {--season= : Season to generate forecast for (defaults to mlb.season.default)}';

    protected $description = 'Generate MLB playoff and World Series futures forecast';

    public function handle(GeneratePlayoffForecast $generatePlayoffForecast): int
    {
        $season = $this->option('season');

        $this->info('Generating MLB playoff futures forecast...');
        $forecasts = $generatePlayoffForecast->execute($season !== null ? (int) $season : null);

        if ($forecasts->isEmpty()) {
            $this->warn('No eligible MLB team metrics found. Run mlb:calculate-team-metrics first.');

            return self::SUCCESS;
        }

        $resolvedSeason = (int) ($season ?? config('mlb.season.default'));
        $this->info("Season: {$resolvedSeason}");
        $this->newLine();

        $leaders = $forecasts->sortByDesc('champion_probability')->take(12)->values();
        $this->table(
            ['Team', 'League', 'Seed', 'Make %', 'LCS %', 'WS %', 'Champion %'],
            $leaders->map(function ($forecast) {
                $teamName = trim(implode(' ', array_filter([
                    $forecast->team->location ?? null,
                    $forecast->team->name ?? null,
                ])));
                if ($teamName === '') {
                    $teamName = (string) ($forecast->team->abbreviation ?? $forecast->team_id);
                }

                return [
                    $teamName,
                    $forecast->league ?? '-',
                    $forecast->projected_seed ? (string) $forecast->projected_seed : '-',
                    round(((float) $forecast->playoff_make_probability) * 100, 1).'%',
                    round(((float) $forecast->league_championship_probability) * 100, 1).'%',
                    round(((float) $forecast->world_series_probability) * 100, 1).'%',
                    round(((float) $forecast->champion_probability) * 100, 2).'%',
                ];
            })
        );

        return self::SUCCESS;
    }
}

