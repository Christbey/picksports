<?php

use App\Actions\Validation\SportValidator;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Facades\Config;

uses()->group('sports');

it('resolves an active nba finals stage from the real remaining teams', function () {
    $this->travelTo('2026-06-06 12:00:00');

    $knicks = Team::factory()->create(['conference' => 'Eastern']);
    $spurs = Team::factory()->create(['conference' => 'Western']);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $knicks->id,
        'away_team_id' => $spurs->id,
        'game_date' => '2026-06-04',
        'status' => 'STATUS_FINAL',
        'home_score' => 111,
        'away_score' => 106,
    ]);

    $nextGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $spurs->id,
        'away_team_id' => $knicks->id,
        'game_date' => '2026-06-08',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $context = app(SeasonStageService::class)->context('nba', 2026);

    expect($context->stage)->toBe('finals')
        ->and($context->stageGroup)->toBe('championship')
        ->and($context->activeGameIds)->toContain($nextGame->id)
        ->and($context->remainingTeamIds)->toEqualCanonicalizing([$knicks->id, $spurs->id]);
});

it('treats only the next championship game as market ready unless later games have odds', function () {
    $this->travelTo('2026-06-06 12:00:00');

    $knicks = Team::factory()->create(['conference' => 'Eastern']);
    $spurs = Team::factory()->create(['conference' => 'Western']);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $knicks->id,
        'away_team_id' => $spurs->id,
        'game_date' => '2026-06-04',
        'status' => 'STATUS_FINAL',
        'home_score' => 111,
        'away_score' => 106,
    ]);

    $nextGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $spurs->id,
        'away_team_id' => $knicks->id,
        'game_date' => '2026-06-08',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $ifNecessaryWithoutMarket = Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $knicks->id,
        'away_team_id' => $spurs->id,
        'game_date' => '2026-06-10',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $ifNecessaryWithMarket = Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $spurs->id,
        'away_team_id' => $knicks->id,
        'game_date' => '2026-06-12',
        'status' => 'STATUS_SCHEDULED',
        'odds_api_event_id' => 'odds-available',
    ]);

    $context = app(SeasonStageService::class)->context('nba', 2026);

    expect($context->activeGameIds)->toEqualCanonicalizing([
        $nextGame->id,
        $ifNecessaryWithoutMarket->id,
        $ifNecessaryWithMarket->id,
    ])
        ->and($context->marketReadyGameIds)->toEqualCanonicalizing([
            $nextGame->id,
            $ifNecessaryWithMarket->id,
        ]);
});

it('treats only the next regular season game date as market ready unless later games have odds', function () {
    $this->travelTo('2026-06-06 12:00:00');

    $home = MlbTeam::factory()->create();
    $away = MlbTeam::factory()->create();

    $todayGame = MlbGame::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2026-06-06',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $tomorrowWithoutMarket = MlbGame::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'home_team_id' => $away->id,
        'away_team_id' => $home->id,
        'game_date' => '2026-06-07',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $laterWithMarket = MlbGame::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2026-06-08',
        'status' => 'STATUS_SCHEDULED',
        'odds_api_event_id' => 'odds-available',
    ]);

    $context = app(SeasonStageService::class)->context('mlb', 2026);

    expect($context->stage)->toBe('regular_season')
        ->and($context->stageGroup)->toBe('regular_season')
        ->and($context->activeGameIds)->toEqualCanonicalizing([
            $todayGame->id,
            $tomorrowWithoutMarket->id,
            $laterWithMarket->id,
        ])
        ->and($context->marketReadyGameIds)->toEqualCanonicalizing([
            $todayGame->id,
            $laterWithMarket->id,
        ]);
});

it('keeps local date windows stable for utc game times crossing midnight', function () {
    Config::set('sports.business_timezone', 'America/Chicago');

    $window = app(SportsDateWindowService::class)->forDate('2026-06-01');

    expect($window->localStartDate())->toBe('2026-06-01')
        ->and($window->utcStartDateTime())->toBe('2026-06-01 05:00:00')
        ->and($window->utcEndDateTime())->toBe('2026-06-02 04:59:59');
});

it('adds schedule window validation metadata for active game/date/week/month coverage', function () {
    $this->travelTo('2026-06-06 12:00:00');

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    Game::factory()->create([
        'espn_event_id' => '401-a',
        'season' => 2026,
        'season_type' => '3',
        'week' => 1,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => '2026-06-08',
        'game_time' => '01:00:00',
        'status' => 'STATUS_SCHEDULED',
    ]);

    Game::factory()->create([
        'espn_event_id' => '401-b',
        'season' => 2026,
        'season_type' => '3',
        'week' => 1,
        'home_team_id' => $away->id,
        'away_team_id' => $home->id,
        'game_date' => '2026-06-08',
        'game_time' => '02:00:00',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $result = collect(app(SportValidator::class)->validate('nba'))
        ->firstWhere('check_type', 'validation_schedule_window_integrity');

    expect($result)->not->toBeNull()
        ->and($result['metadata']['coverage_by_date'])->toHaveKey('2026-06-08')
        ->and($result['metadata']['coverage_by_week'])->toHaveKey('1')
        ->and($result['metadata']['coverage_by_month'])->toHaveKey('2026-06');
});
