<?php

use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\User;
use App\Services\Sports\CfbGameCalendarRead;
use App\Services\Sports\SportsDateWindowService;
use App\Support\CFB\CfbGameStart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('uses confirmed kickoff or raw calendar inventory in the generic CFB date range', function (bool $known) {
    Http::preventStrayRequests();
    config(['sports.business_timezone' => 'America/Chicago']);
    Sanctum::actingAs(User::factory()->create());
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'espn_event_id' => 'window-synthetic',
        'game_date' => '2027-01-13', 'game_time' => '02:00:00', 'season' => 2026,
        ...CfbGameStart::attributes('2027-01-13T02:00:00Z', ['timeValid' => $known], 'espn_scoreboard', 'window-synthetic')]);
    expect(CfbGameStart::for($game) !== null)->toBe($known);
    $day = $known ? '2027-01-12' : '2027-01-13';
    $wrongDay = $known ? '2027-01-13' : '2027-01-12';
    $this->getJson('/api/v2/sports/cfb/games?from_date='.$day.'&to_date='.$day)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $game->id);
    $this->getJson('/api/v2/sports/cfb/games?from_date='.$wrongDay.'&to_date='.$wrongDay)
        ->assertOk()->assertJsonCount(0, 'data');
    Http::assertNothingSent();
})->with([false, true]);

it('requires a confirmed clock for the generic CFB precise before-game cutoff', function (bool $known) {
    Http::preventStrayRequests();
    Sanctum::actingAs(User::factory()->create());
    $home = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'cutoff-synthetic', 'game_date' => '2027-01-13', 'game_time' => '02:00:00',
        'season' => 2026, 'status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0,
        ...CfbGameStart::attributes('2027-01-13T02:00:00Z', ['timeValid' => $known], 'espn_scoreboard', 'cutoff-synthetic')]);
    expect(CfbGameStart::for($game) !== null)->toBe($known);
    $query = http_build_query(['before_game_at' => '2027-01-13T03:00:00Z', 'status' => 'STATUS_FINAL']);
    $this->getJson('/api/v2/sports/cfb/teams/'.$home->id.'/games?'.$query)
        ->assertOk()->assertJsonCount($known ? 1 : 0, 'data');
    Http::assertNothingSent();
})->with([false, true]);

it('retains descriptive completed results without a precise kickoff criterion', function () {
    Http::preventStrayRequests();
    Sanctum::actingAs(User::factory()->create());
    $team = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'status' => 'STATUS_FINAL', 'game_date' => '2027-01-13', 'game_time' => '00:00:00',
        'home_score' => 0, 'away_score' => 0, 'kickoff_observation' => null]);
    $response = $this->getJson('/api/v2/sports/cfb/teams/'.$team->id.'/games?status=STATUS_FINAL&from_date=2027-01-13&to_date=2027-01-13')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $game->id);
    expect($game->fresh()->home_score)->toBe(0)->and($game->fresh()->away_score)->toBe(0);
    Http::assertNothingSent();
});

it('preserves real UTC midnight as a known instant rather than an unknown calendar carrier', function () {
    Http::preventStrayRequests();
    config(['sports.business_timezone' => 'America/Chicago']);
    Sanctum::actingAs(User::factory()->create());
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'midnight-window', ...CfbGameStart::attributes('2027-01-13T00:00:00Z', [], 'espn_schedule', 'midnight-window')]);
    $this->getJson('/api/v2/sports/cfb/games?from_date=2027-01-12&to_date=2027-01-12')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $game->id);
    expect(app(SportsDateWindowService::class)->gameStart($game)?->toIso8601String())->toBe('2027-01-13T00:00:00+00:00');
    $game->kickoff_observation = null;
    expect(app(SportsDateWindowService::class)->gameStart($game))->toBeNull();
    Http::assertNothingSent();
});

it('does not hydrate eager team relations or oversized clock bodies in its bounded calendar scan', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'game_date' => '2027-01-13', 'game_time' => '02:00:00']);
    DB::table('cfb_games')->where('id', $game->id)->update(['kickoff_observation' => json_encode(['oversized' => str_repeat('x', 4096)])]);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $query = Game::with(['homeTeam', 'awayTeam']);
    $window = app(SportsDateWindowService::class)->forDate('2027-01-13');
    app(CfbGameCalendarRead::class)->inWindow($query, $window);
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect(strtolower($sql))->toContain('case when')->not->toContain('select *')->not->toContain('cfb_teams');
    }
    expect($query->reorder()->pluck('cfb_games.id')->all())->toBe([$game->id]);
});

it('refuses an ID overflow rather than returning truncated CFB calendar inventory', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $attributes = Game::factory()->raw(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => '2027-01-13', 'game_time' => '02:00:00', 'kickoff_observation' => null]);
    $rows = [];
    for ($id = 0; $id < 1001; $id++) {
        $rows[] = [...$attributes, 'espn_event_id' => 'bounded-calendar-'.$id];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('cfb_games')->insert($chunk);
    }
    $window = app(SportsDateWindowService::class)->forDate('2027-01-13');
    expect(fn () => app(CfbGameCalendarRead::class)->inWindow(Game::query(), $window))
        ->toThrow(LengthException::class, 'ID capacity exceeded');
});

it('refuses prelimited candidate queries instead of silently accepting partial clock selection', function () {
    $window = app(SportsDateWindowService::class)->forDate('2027-01-13');
    expect(fn () => app(CfbGameCalendarRead::class)->inWindow(Game::query()->limit(1), $window))
        ->toThrow(LogicException::class, 'unpaginated');
});
