<?php

use App\Actions\CBB\GenerateTournamentForecast;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TournamentForecast;

uses()->group('cbb', 'predictions');

beforeEach(function () {
    config()->set('cbb.tournament_forecast.field_size', 8);
    config()->set('cbb.tournament_forecast.auto_bids', 3);
    config()->set('cbb.tournament_forecast.simulations', 1200);
    config()->set('cbb.tournament_forecast.random_seed', 12345);
    config()->set('cbb.season.default', 2026);

    $conferences = ['SEC', 'ACC', 'Big 12', 'Big Ten'];
    $this->teams = collect();

    for ($i = 0; $i < 10; $i++) {
        $team = Team::factory()->create([
            'school' => "Team {$i}",
            'mascot' => "Mascot {$i}",
            'abbreviation' => 'T'.$i,
            'conference' => $conferences[$i % count($conferences)],
            'elo_rating' => 1780 - ($i * 30),
        ]);

        TeamMetric::create([
            'team_id' => $team->id,
            'season' => 2026,
            'offensive_efficiency' => 112 - $i,
            'defensive_efficiency' => 98 + ($i * 0.8),
            'net_rating' => 14 - ($i * 1.8),
            'tempo' => 69 + ($i * 0.1),
            'strength_of_schedule' => 1590 - ($i * 8),
            'games_played' => 28,
            'meets_minimum' => true,
            'adj_offensive_efficiency' => 113 - ($i * 0.9),
            'adj_defensive_efficiency' => 97 + ($i * 0.7),
            'adj_net_rating' => 16 - ($i * 2.0),
            'rolling_offensive_efficiency' => 111 - ($i * 0.8),
            'rolling_defensive_efficiency' => 99 + ($i * 0.7),
            'rolling_net_rating' => 12 - ($i * 1.6),
            'rolling_tempo' => 69 + ($i * 0.1),
            'rolling_games_count' => 10,
            'calculation_date' => now()->toDateString(),
        ]);

        $this->teams->push($team);
    }

    // Create final games so win_pct is not a constant across teams.
    for ($i = 0; $i < 9; $i++) {
        Game::factory()->create([
            'season' => 2026,
            'home_team_id' => $this->teams[$i]->id,
            'away_team_id' => $this->teams[$i + 1]->id,
            'status' => 'STATUS_FINAL',
            'home_score' => 82 - $i,
            'away_score' => 67 - $i,
        ]);
    }
});

it('generates tournament make and championship probabilities', function () {
    $action = new GenerateTournamentForecast;
    $forecasts = $action->execute(2026, 1200);

    expect($forecasts)->toHaveCount(10);
    expect(TournamentForecast::query()->where('season', 2026)->count())->toBe(10);

    $autoBidCount = $forecasts->where('auto_bid', true)->count();
    expect($autoBidCount)->toBe(3);

    $projectedSeedsCount = $forecasts->whereNotNull('projected_seed')->count();
    expect($projectedSeedsCount)->toBe(8);

    $championProbabilitySum = (float) $forecasts->sum(fn ($row) => (float) $row->champion_probability);
    expect($championProbabilitySum)->toBeGreaterThan(0.97)
        ->toBeLessThan(1.03);

    $topByChampion = $forecasts->sortByDesc('champion_probability')->first();
    $bottomByChampion = $forecasts->sortBy('champion_probability')->first();

    expect($topByChampion->team_id)->toBe($this->teams[0]->id)
        ->and((float) $topByChampion->champion_probability)->toBeGreaterThan((float) $bottomByChampion->champion_probability);

    $topByMake = $forecasts->sortByDesc('tournament_make_probability')->first();
    $bottomByMake = $forecasts->sortBy('tournament_make_probability')->first();

    expect((float) $topByMake->tournament_make_probability)
        ->toBeGreaterThan((float) $bottomByMake->tournament_make_probability);

    $sample = $forecasts->first();
    $aq = (float) $sample->auto_bid_probability;
    $al = (float) $sample->at_large_probability;
    $make = (float) $sample->tournament_make_probability;

    expect($aq)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    expect($al)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    expect((float) $sample->first_four_probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    expect((float) $sample->bid_thief_probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    expect((float) $sample->first_four_auto_probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    expect((float) $sample->first_four_at_large_probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    expect(abs(($aq + $al) - $make))->toBeLessThan(0.02);
});

it('runs the tournament forecast command and persists rows', function () {
    $this->artisan('cbb:generate-tournament-forecast', [
        '--season' => 2026,
        '--simulations' => 400,
    ])->assertExitCode(0);

    $forecasts = TournamentForecast::query()->where('season', 2026)->get();

    expect($forecasts)->toHaveCount(10);
    expect($forecasts->first()->simulation_runs)->toBe(400);
});
