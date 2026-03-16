<?php

use App\Actions\WCBB\GenerateTournamentForecast;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TournamentForecast;

uses()->group('wcbb', 'predictions');

beforeEach(function () {
    config()->set('wcbb.season.default', 2026);
    config()->set('wcbb.tournament_forecast.field_size', 4);
    config()->set('wcbb.tournament_forecast.auto_bids', 2);
    config()->set('wcbb.tournament_forecast.simulations', 400);
    config()->set('wcbb.tournament_forecast.random_seed', 12345);
    config()->set('wcbb.tournament_forecast.selection_conference_strength_weight', 0.35);
    config()->set('wcbb.tournament_forecast.selection_power_conference_bonus', 0.45);
    config()->set('wcbb.tournament_forecast.selection_resume_confidence_penalty', 0.30);
    config()->set('wcbb.tournament_forecast.selection_full_confidence_games', 20);
    config()->set('wcbb.tournament_forecast.conference_strength_top_teams', 2);
});

it('uses conference context so a one-bid heater does not automatically outrank a power-conference contender', function () {
    $ucla = Team::factory()->create([
        'school' => 'UCLA',
        'mascot' => 'Bruins',
        'abbreviation' => 'UCL',
        'conference' => 'Big Ten Conference',
        'elo_rating' => 1555,
    ]);

    $usc = Team::factory()->create([
        'school' => 'USC',
        'mascot' => 'Trojans',
        'abbreviation' => 'USC',
        'conference' => 'Big Ten Conference',
        'elo_rating' => 1548,
    ]);

    $fdu = Team::factory()->create([
        'school' => 'Fairleigh Dickinson',
        'mascot' => 'Knights',
        'abbreviation' => 'FDU',
        'conference' => 'Northeast Conference',
        'elo_rating' => 1542,
    ]);

    $liu = Team::factory()->create([
        'school' => 'Long Island',
        'mascot' => 'Sharks',
        'abbreviation' => 'LIU',
        'conference' => 'Northeast Conference',
        'elo_rating' => 1470,
    ]);

    $tcu = Team::factory()->create([
        'school' => 'TCU',
        'mascot' => 'Horned Frogs',
        'abbreviation' => 'TCU',
        'conference' => 'Big 12 Conference',
        'elo_rating' => 1560,
    ]);

    $byu = Team::factory()->create([
        'school' => 'BYU',
        'mascot' => 'Cougars',
        'abbreviation' => 'BYU',
        'conference' => 'Big 12 Conference',
        'elo_rating' => 1510,
    ]);

    collect([
        [$ucla, 33.9, 38.4, 1491.6, 5],
        [$usc, 21.0, 18.5, 1504.0, 5],
        [$fdu, 39.7, 41.5, 1505.7, 5],
        [$liu, -8.0, -7.0, 1496.0, 5],
        [$tcu, 24.9, 20.7, 1509.8, 6],
        [$byu, 10.0, 8.5, 1503.0, 6],
    ])->each(function (array $row) {
        [$team, $adjNet, $rollingNet, $sos, $gamesPlayed] = $row;

        TeamMetric::create([
            'team_id' => $team->id,
            'season' => 2026,
            'offensive_efficiency' => 108.0,
            'defensive_efficiency' => 96.0,
            'net_rating' => $adjNet,
            'tempo' => 69.0,
            'strength_of_schedule' => $sos,
            'games_played' => $gamesPlayed,
            'meets_minimum' => true,
            'adj_offensive_efficiency' => 109.0,
            'adj_defensive_efficiency' => 95.0,
            'adj_net_rating' => $adjNet,
            'rolling_offensive_efficiency' => 107.0,
            'rolling_defensive_efficiency' => 97.0,
            'rolling_net_rating' => $rollingNet,
            'rolling_tempo' => 69.0,
            'rolling_games_count' => min($gamesPlayed, 10),
            'calculation_date' => now()->toDateString(),
        ]);
    });

    foreach ([[$ucla, $usc, 82, 70], [$fdu, $liu, 78, 63], [$tcu, $byu, 75, 68]] as [$home, $away, $homeScore, $awayScore]) {
        Game::factory()->create([
            'season' => 2026,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'STATUS_FINAL',
            'home_score' => $homeScore,
            'away_score' => $awayScore,
        ]);
    }

    $forecasts = (new GenerateTournamentForecast)->execute(2026, 400);

    $uclaForecast = $forecasts->firstWhere('team_id', $ucla->id);
    $fduForecast = $forecasts->firstWhere('team_id', $fdu->id);

    expect($uclaForecast)->not->toBeNull()
        ->and($fduForecast)->not->toBeNull()
        ->and((float) $uclaForecast->selection_score)->toBeGreaterThan((float) $fduForecast->selection_score)
        ->and((int) $uclaForecast->projected_seed)->toBeLessThanOrEqual((int) $fduForecast->projected_seed);

    expect(TournamentForecast::query()->where('season', 2026)->count())->toBe(6);
});
