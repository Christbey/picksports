<?php

use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\StartingPitcherForecast;
use App\Models\MLB\Team;
use App\Services\MLB\MlbStartingPitcherForecastService;
use Carbon\Carbon;

uses()->group('mlb');

afterEach(fn () => Carbon::setTestNow());

it('stores one immutable pregame rotation forecast and grades it against the actual starter', function () {
    Carbon::setTestNow('2026-08-01 10:00:00');

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $predicted = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => 'forecast-pitcher',
        'full_name' => 'Forecast Pitcher',
        'elo_rating' => 1524,
    ]);
    $game = Game::factory()->regularSeason()->create([
        'season' => 2026,
        'game_date' => '2026-08-01',
        'game_time' => '19:10:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'pitcher_projection_generated_at' => now(),
        'pitcher_projection_metadata' => ['version' => 'rotation-v1'],
    ]);
    $projection = [
        'pitcher_espn_id' => $predicted->espn_id,
        'confidence' => 0.8,
        'evidence' => ['rotation_size' => 5, 'games_ahead' => 1],
    ];
    $service = app(MlbStartingPitcherForecastService::class);

    $first = $service->record($game, 'home', $projection);
    $second = $service->record($game, 'home', $projection);

    expect($first)->not->toBeNull()
        ->and($second?->id)->toBe($first?->id)
        ->and(StartingPitcherForecast::query()->count())->toBe(1)
        ->and($first?->known_before_game_start)->toBeTrue()
        ->and($first?->predicted_pitcher_rating)->toBe(1524.0)
        ->and($first?->grade)->toBeNull();

    $game->update([
        'actual_home_pitcher_espn_id' => $predicted->espn_id,
        'starting_pitchers_confirmed_at' => now()->addHours(10),
    ]);
    $service->confirmGame($game->fresh());

    $graded = $first->fresh();
    expect($graded->is_correct)->toBeTrue()
        ->and($graded->starter_changed)->toBeFalse()
        ->and($graded->grade)->toBe('correct')
        ->and($graded->brier_score)->toEqualWithDelta(0.04, 0.000001)
        ->and($graded->confidence_error)->toEqualWithDelta(0.2, 0.000001)
        ->and($graded->actual_pitcher_rating)->toBe(1524.0)
        ->and($graded->graded_at)->not->toBeNull();

    expect(fn () => $graded->update(['confidence' => 0.9]))
        ->toThrow(LogicException::class, 'immutable');
});

it('grades a changed starter as an incorrect rotation forecast and reports calibration', function () {
    Carbon::setTestNow('2026-08-01 09:00:00');

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $predicted = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => 'predicted-away',
        'elo_rating' => 1490,
    ]);
    $actual = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => 'actual-away',
        'elo_rating' => 1530,
    ]);
    $game = Game::factory()->regularSeason()->create([
        'season' => 2026,
        'game_date' => '2026-08-02',
        'game_time' => '18:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'pitcher_projection_generated_at' => now(),
        'pitcher_projection_metadata' => ['version' => 'rotation-v1'],
    ]);
    $service = app(MlbStartingPitcherForecastService::class);
    $forecast = $service->record($game, 'away', [
        'pitcher_espn_id' => $predicted->espn_id,
        'confidence' => 0.7,
        'evidence' => ['rotation_size' => 5],
    ]);

    $game->update([
        'actual_away_pitcher_espn_id' => $actual->espn_id,
        'starting_pitchers_confirmed_at' => now()->addDay(),
    ]);
    $service->confirmGame($game->fresh());

    $graded = $forecast?->fresh();
    $report = $service->report(2026);

    expect($graded?->is_correct)->toBeFalse()
        ->and($graded?->starter_changed)->toBeTrue()
        ->and($graded?->grade)->toBe('incorrect')
        ->and($graded?->brier_score)->toEqualWithDelta(0.49, 0.000001)
        ->and($graded?->rating_difference)->toBe(40.0)
        ->and(data_get($report, 'summary.forecasts'))->toBe(1)
        ->and(data_get($report, 'summary.accuracy'))->toBe(0.0)
        ->and(data_get($report, 'summary.average_brier'))->toBe(0.49)
        ->and(data_get($report, 'summary.rating_mae'))->toBe(40.0);
});
