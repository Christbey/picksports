<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\GenerateTournamentForecast;
use Illuminate\Console\Command;

class GenerateTournamentForecastCommand extends Command
{
    protected $signature = 'cbb:generate-tournament-forecast
        {--season= : Season to forecast (defaults to cbb.season.default)}
        {--simulations= : Number of Monte Carlo simulations to run}';

    protected $description = 'Generate CBB March Madness field and champion probability forecasts';

    public function handle(GenerateTournamentForecast $generateTournamentForecast): int
    {
        $season = $this->option('season');
        $simulations = $this->option('simulations');

        $this->info('Generating CBB tournament forecast...');

        $forecasts = $generateTournamentForecast->execute(
            $season !== null ? (int) $season : null,
            $simulations !== null ? (int) $simulations : null
        );

        if ($forecasts->isEmpty()) {
            $this->warn('No eligible CBB team metrics found. Run cbb:calculate-team-metrics first.');

            return self::SUCCESS;
        }

        $resolvedSeason = (int) ($season ?? config('cbb.season.default'));
        $resolvedSimulationRuns = (int) $forecasts->first()->simulation_runs;

        $this->info("Season: {$resolvedSeason}");
        $this->info("Simulation Runs: {$resolvedSimulationRuns}");
        $this->newLine();

        $makeField = $forecasts
            ->sortByDesc('tournament_make_probability')
            ->take(16)
            ->values();

        $this->info('Top Tournament Make Probabilities');
        $this->table(
            ['Team', 'Conf', 'Seed', 'AQ %', 'AL %', 'First Four %', 'Make %'],
            $makeField->map(function ($forecast) {
                $teamName = trim(implode(' ', array_filter([
                    $forecast->team->school ?? null,
                    $forecast->team->mascot ?? null,
                ])));
                if ($teamName === '') {
                    $teamName = (string) ($forecast->team->abbreviation ?? $forecast->team_id);
                }

                return [
                    $teamName,
                    $forecast->team->conference ?? 'Independent',
                    $forecast->projected_seed ? (string) $forecast->projected_seed : '-',
                    round(((float) $forecast->auto_bid_probability) * 100, 1).'%',
                    round(((float) $forecast->at_large_probability) * 100, 1).'%',
                    round(((float) $forecast->first_four_probability) * 100, 1).'%',
                    round(((float) $forecast->tournament_make_probability) * 100, 1).'%',
                ];
            })
        );

        $this->newLine();

        $champions = $forecasts
            ->sortByDesc('champion_probability')
            ->take(16)
            ->values();

        $this->info('Top Championship Probabilities');
        $this->table(
            ['Team', 'Seed', 'Final Four %', 'Title Game %', 'Champion %'],
            $champions->map(function ($forecast) {
                $teamName = trim(implode(' ', array_filter([
                    $forecast->team->school ?? null,
                    $forecast->team->mascot ?? null,
                ])));
                if ($teamName === '') {
                    $teamName = (string) ($forecast->team->abbreviation ?? $forecast->team_id);
                }

                return [
                    $teamName,
                    $forecast->projected_seed ? (string) $forecast->projected_seed : '-',
                    round(((float) $forecast->final_four_probability) * 100, 2).'%',
                    round(((float) $forecast->title_game_probability) * 100, 2).'%',
                    round(((float) $forecast->champion_probability) * 100, 2).'%',
                ];
            })
        );

        $this->newLine();

        $bubble = $forecasts
            ->sortByDesc('bid_thief_probability')
            ->take(12)
            ->values();

        $this->info('Bid Thief / Play-In Risk');
        $this->table(
            ['Team', 'Seed', 'Bid Thief %', 'First Four (AQ) %', 'First Four (AL) %'],
            $bubble->map(function ($forecast) {
                $teamName = trim(implode(' ', array_filter([
                    $forecast->team->school ?? null,
                    $forecast->team->mascot ?? null,
                ])));
                if ($teamName === '') {
                    $teamName = (string) ($forecast->team->abbreviation ?? $forecast->team_id);
                }

                return [
                    $teamName,
                    $forecast->projected_seed ? (string) $forecast->projected_seed : '-',
                    round(((float) $forecast->bid_thief_probability) * 100, 2).'%',
                    round(((float) $forecast->first_four_auto_probability) * 100, 2).'%',
                    round(((float) $forecast->first_four_at_large_probability) * 100, 2).'%',
                ];
            })
        );

        return self::SUCCESS;
    }
}
