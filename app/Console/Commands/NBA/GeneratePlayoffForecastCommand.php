<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\GeneratePlayoffForecast;
use Illuminate\Console\Command;

class GeneratePlayoffForecastCommand extends Command
{
    protected $signature = 'nba:generate-playoff-forecast
        {--season= : Season to generate forecast for (defaults to nba.season.default)}';

    protected $description = 'Generate NBA playoff and title futures forecast';

    public function handle(GeneratePlayoffForecast $generatePlayoffForecast): int
    {
        $season = $this->option('season');

        $this->info('Generating NBA playoff futures forecast...');
        $forecasts = $generatePlayoffForecast->execute($season !== null ? (int) $season : null);

        if ($forecasts->isEmpty()) {
            $this->warn('No eligible NBA team metrics found. Run nba:calculate-team-metrics first.');

            return self::SUCCESS;
        }

        $resolvedSeason = (int) ($season ?? config('nba.season.default'));
        $this->info("Season: {$resolvedSeason}");
        $this->newLine();

        $leaders = $forecasts->sortByDesc('champion_probability')->take(12)->values();
        $this->table(
            ['Team', 'Conf', 'Seed', 'Make %', 'Conf Finals %', 'Finals %', 'Champion %'],
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
                    $forecast->conference ?? '-',
                    $forecast->projected_seed ? (string) $forecast->projected_seed : '-',
                    round(((float) $forecast->playoff_make_probability) * 100, 1).'%',
                    round(((float) $forecast->conference_finals_probability) * 100, 1).'%',
                    round(((float) $forecast->nba_finals_probability) * 100, 1).'%',
                    round(((float) $forecast->champion_probability) * 100, 2).'%',
                ];
            })
        );

        return self::SUCCESS;
    }
}

