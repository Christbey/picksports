<?php

use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\User;
use App\Services\ESPN\CFB\EspnService;
use App\Services\Sports\SportsDateWindowService;
use App\Support\CFB\CfbGameStart;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    config(['sports.business_timezone' => 'America/Chicago']);
    Http::preventStrayRequests();
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
    $this->travelBack();
});

it('keeps native unknown raw-date inventory separate from confirmed Central date and true midnight', function () {
    Sanctum::actingAs(User::factory()->create());
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $ids = [];
    foreach (['unknown', 'known', 'midnight'] as $kind) {
        $instant = $kind === 'midnight' ? '2027-01-13T00:00:00Z' : '2027-01-13T02:00:00Z';
        $id = 'generic-native-'.$kind;
        $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'espn_event_id' => $id,
            'game_date' => '2027-01-13', 'game_time' => $kind === 'midnight' ? '00:00:00' : '02:00:00',
            'season' => 2026, 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0,
            ...CfbGameStart::attributes($instant, ['timeValid' => $kind !== 'unknown'], 'espn_scoreboard', $id)]);
        $ids[$kind] = $game->id;
        expect($game->getRawOriginal('game_time'))->toBe($kind === 'midnight' ? '00:00:00' : '02:00:00');
    }
    $rawDay = $this->getJson('/api/v2/sports/cfb/games?from_date=2027-01-13&to_date=2027-01-13')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.home_score', 0)->assertJsonPath('data.0.away_score', 0)
        ->assertJsonPath('data.0.starts_at', null)->assertJsonPath('data.0.game_time', null);
    expect(array_column($rawDay->json('data'), 'id'))->toBe([$ids['unknown']]);
    $knownDay = $this->getJson('/api/v2/sports/cfb/games?from_date=2027-01-12&to_date=2027-01-12')->assertOk()->assertJsonCount(2, 'data');
    expect(array_column($knownDay->json('data'), 'id'))->toEqualCanonicalizing([$ids['known'], $ids['midnight']]);
    $knownRows = collect($knownDay->json('data'))->keyBy('id');
    expect($knownRows[$ids['midnight']]['starts_at'])->toBe('2027-01-13T00:00:00+00:00');
    $results = $this->getJson('/api/v2/sports/cfb/teams/'.$home->id.'/games?status=STATUS_FINAL')->assertOk()->assertJsonCount(3, 'data');
    expect(Game::whereKey($ids['unknown'])->firstOrFail()->home_score)->toBe(0)
        ->and(Game::whereKey($ids['unknown'])->firstOrFail()->away_score)->toBe(0);
    $column = DB::selectOne('SELECT DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['cfb_games', 'game_time']);
    expect($column->DATA_TYPE)->toBe('time')->and($column->IS_NULLABLE)->toBe('NO');
    Http::assertNothingSent();
});

it('withholds native unknown clocks only for the explicit precise before-game filter', function () {
    Sanctum::actingAs(User::factory()->create());
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $knownIds = [];
    foreach (['unknown', 'known', 'midnight'] as $kind) {
        $id = 'cutoff-native-'.$kind;
        $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'espn_event_id' => $id,
            'game_date' => '2027-01-13', 'game_time' => $kind === 'midnight' ? '00:00:00' : '02:00:00',
            'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0,
            ...CfbGameStart::attributes($kind === 'midnight' ? '2027-01-13T00:00:00Z' : '2027-01-13T02:00:00Z', ['timeValid' => $kind !== 'unknown'], 'espn_schedule', $id)]);
        if ($kind !== 'unknown') {
            $knownIds[] = $game->id;
        }
    }
    $query = http_build_query(['before_game_at' => '2027-01-13T03:00:00Z', 'status' => 'STATUS_FINAL']);
    $response = $this->getJson('/api/v2/sports/cfb/teams/'.$home->id.'/games?'.$query)->assertOk()->assertJsonCount(2, 'data');
    expect(array_column($response->json('data'), 'id'))->toEqualCanonicalizing($knownIds);
    Http::assertNothingSent();
});

it('verifies an old native unknown carrier before horizon selection and honestly reapplies the horizon', function () {
    $this->travelTo('2026-11-27 12:00:00');
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create(['division' => 'FBS'])->id,
        'away_team_id' => Team::factory()->create(['division' => 'FBS'])->id, 'espn_event_id' => 'native-generic-recovery',
        'season' => 2026, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-01', 'game_time' => '00:00:00']);
    $payload = fn (string $date): array => ['header' => ['id' => 'native-generic-recovery', 'season' => ['year' => 2026],
        'competitions' => [['date' => $date, 'dateValid' => true, 'timeValid' => true, 'status' => ['type' => ['name' => 'STATUS_SCHEDULED']]]]]];
    $this->mock(EspnService::class)->shouldReceive('getGame')->twice()->with('native-generic-recovery')
        ->andReturn($payload('2026-11-29T20:00:00Z'), $payload('2026-11-26T20:00:00Z'));
    $this->artisan('espn:sync-cfb-pregame-metadata', ['--season' => 2026, '--game-id' => $game->id])
        ->expectsOutput('CFB pregame metadata: verified 1, failed 0, deferred 0.')->assertSuccessful();
    expect(CfbGameStart::for($game->fresh())?->toIso8601String())->toBe('2026-11-29T20:00:00+00:00');
    // A second actual same-event unconfirmed carrier must be verified again before horizon exclusion.
    $game->refresh()->forceFill(['game_date' => '2026-10-01', 'game_time' => '00:00:00',
        ...CfbGameStart::attributes('2026-10-01T00:00:00Z', ['timeValid' => false], 'espn_schedule', 'native-generic-recovery')])->save();
    expect(CfbGameStart::for($game->fresh()))->toBeNull();
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--game' => $game->id])
        ->expectsOutput('Game '.$game->id.': verified kickoff excluded after verification (outside future horizon).')->assertSuccessful();
    expect(CfbGameStart::for($game->fresh())?->toIso8601String())->toBe('2026-11-26T20:00:00+00:00')
        ->and(DB::table('cfb_pipeline_steps')->where('scope', 'game:'.$game->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('retains native correlated prediction and game predicates while independently qualifying bounded clock headers', function () {
    $selected = Team::factory()->create();
    $other = Team::factory()->create();
    $away = Team::factory()->create();
    $ids = [];
    foreach (['unknown', 'known', 'midnight', 'foreign-team', 'foreign-prediction'] as $kind) {
        $event = 'correlated-native-'.$kind;
        $game = Game::factory()->create(['home_team_id' => $kind === 'foreign-team' ? $other->id : $selected->id,
            'away_team_id' => $away->id, 'espn_event_id' => $event, 'game_date' => '2027-01-13', 'game_time' => $kind === 'midnight' ? '00:00:00' : '02:00:00',
            ...CfbGameStart::attributes($kind === 'midnight' ? '2027-01-13T00:00:00Z' : '2027-01-13T02:00:00Z', ['timeValid' => in_array($kind, ['known', 'midnight'], true)], 'espn_schedule', $event)]);
        $prediction = Prediction::create(['game_id' => $game->id, 'model_version' => $kind === 'foreign-prediction' ? 'other-context' : 'selected-context']);
        $ids[$kind] = $prediction->id;
    }
    $windows = app(SportsDateWindowService::class);
    foreach (['2027-01-13' => ['unknown'], '2027-01-12' => ['known', 'midnight']] as $date => $expected) {
        $window = $windows->forDate($date);
        $rows = Prediction::where('model_version', 'selected-context')->whereHas('game', function ($games) use ($selected, $windows, $window) {
            $games->where('home_team_id', $selected->id);
            $windows->applyGameDateWindow($games, $window);
        })->pluck('id')->all();
        expect($rows)->toEqualCanonicalizing(array_map(fn ($kind) => $ids[$kind], $expected));
    }
    Http::assertNothingSent();
});
