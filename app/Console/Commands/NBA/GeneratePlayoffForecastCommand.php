<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\GeneratePlayoffForecast;
use App\Models\NBA\TeamMetric;
use Illuminate\Console\Command;

class GeneratePlayoffForecastCommand extends Command
{
    protected $signature = 'nba:generate-playoff-forecast
        {--season= : Season to generate forecast for (defaults to nba.season.default)}';

    protected $description = 'Generate NBA playoff and title futures forecast';

    public function handle(GeneratePlayoffForecast $generatePlayoffForecast): int
    {
        $season = $this->option('season');
        $requestedSeason = $season !== null ? (int) $season : null;
        $resolvedSeason = (int) ($season ?? config('nba.season.default'));

        $this->info('Generating NBA playoff futures forecast...');
        $forecasts = $generatePlayoffForecast->execute($requestedSeason);

        if ($forecasts->isEmpty()) {
            $latestMetricsSeason = TeamMetric::query()->max('season');
            if ($latestMetricsSeason !== null && (int) $latestMetricsSeason !== $resolvedSeason) {
                $this->warn("No eligible NBA team metrics found for season {$resolvedSeason}. Trying latest metrics season {$latestMetricsSeason}.");
                $forecasts = $generatePlayoffForecast->execute((int) $latestMetricsSeason);
                $resolvedSeason = (int) $latestMetricsSeason;
            }
        }

        if ($forecasts->isEmpty()) {
            $availableMetricSeasons = TeamMetric::query()
                ->select('season')
                ->distinct()
                ->orderByDesc('season')
                ->pluck('season')
                ->take(5)
                ->values()
                ->all();

            $this->warn('No eligible NBA team metrics found. Run nba:calculate-team-metrics first.');
            if ($availableMetricSeasons !== []) {
                $this->line('Available team metric seasons: '.implode(', ', $availableMetricSeasons));
            }

            return self::SUCCESS;
        }

        if ($requestedSeason !== null && $requestedSeason !== $resolvedSeason) {
            $this->warn("Requested season {$requestedSeason} had no metrics. Generated forecast for {$resolvedSeason} instead.");
        }
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
