<?php

use App\Actions\ESPN\CFB\SyncGameDetails;
use App\Actions\GradePlayerProps;
use App\Actions\OddsApi\CFB\SyncPlayerPropsForGames;
use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Models\User;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\ESPN\CFB\EspnService;
use App\Services\OddsApi\OddsApiService;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-12 14:00:00', 'UTC'));
    $this->team = Team::factory()->create();
    $this->away = Team::factory()->create();
    $this->game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->away->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-09-12', 'game_time' => '20:00:00', 'odds_api_event_id' => 'cfb-event']);
    $this->player = Player::factory()->create(['espn_id' => fake()->unique()->numerify('########'), 'team_id' => $this->team->id, 'full_name' => 'Dante Moore']);
    $this->response = fn ($line = 250.5, $name = 'Dante Moore') => ['id' => 'cfb-event', 'commence_time' => '2026-09-12T20:00:00Z', 'bookmakers' => [[
        'key' => 'fanduel', 'markets' => [['key' => 'player_pass_yds', 'outcomes' => [
            ['name' => 'Over', 'description' => $name, 'point' => $line, 'price' => -112],
            ['name' => 'Under', 'description' => $name, 'point' => $line, 'price' => -108],
        ]]],
    ]]];
    $this->prop = fn ($market = 'player_pass_yds', $line = 250.5) => PlayerProp::create([
        'game_id' => $this->game->id, 'player_id' => $this->player->id, 'player_name' => 'Dante Moore',
        'market' => $market, 'line' => $line, 'over_price' => -112, 'under_price' => -108, 'fetched_at' => now(),
    ]);
});

test('imports FanDuel props idempotently and preserves grades and moved lines', function () {
    $api = Mockery::mock(OddsApiService::class);
    $api->shouldReceive('getPlayerProps')->with('cfb-event', 'americanfootball_ncaaf', CfbPropEligibility::MARKETS, 'fanduel,draftkings')
        ->andReturn(($this->response)(), ($this->response)(), ($this->response)(260.5), ($this->response)());
    $sync = new SyncPlayerPropsForGames($api);
    expect($sync->execute('2026-09-12', null, false)['stored'])->toBe(1);
    $original = PlayerProp::first();
    expect($original->player_id)->toBe($this->player->id)->and($original->under_price)->toBe(-108);
    $sync->execute('2026-09-12', null, false);
    expect(PlayerProp::count())->toBe(1);
    $sync->execute('2026-09-12', null, false);
    expect(PlayerProp::count())->toBe(2)->and((bool) $original->fresh()->is_current)->toBeFalse();
    $original->update(['graded_at' => now(), 'actual_value' => 270, 'hit_over' => true]);
    $sync->execute('2026-09-12', null, false);
    expect((float) $original->fresh()->actual_value)->toBe(270.0)->and($original->fresh()->graded_at)->not->toBeNull();
});

test('empty successful responses retire quotes while API failures preserve them', function () {
    $prop = ($this->prop)();
    $api = Mockery::mock(OddsApiService::class);
    $empty = ($this->response)();
    $empty['bookmakers'] = [];
    $api->shouldReceive('getPlayerProps')->andReturn(null, $empty);
    $sync = new SyncPlayerPropsForGames($api);
    expect($sync->execute('2026-09-12', null, false)['failed'])->toBe(1);
    expect((bool) $prop->fresh()->is_current)->toBeTrue();
    expect($sync->execute('2026-09-12', null, false)['empty'])->toBe(1);
    expect((bool) $prop->fresh()->is_current)->toBeFalse()->and(PlayerProp::count())->toBe(1);
});

test('keeps alternate lines separate and leaves ambiguous players unlinked', function () {
    Player::factory()->create(['espn_id' => fake()->unique()->numerify('########'), 'team_id' => $this->away->id, 'full_name' => 'Dante Moore']);
    $response = ($this->response)();
    $response['bookmakers'][0]['markets'][0]['outcomes'][1]['point'] = 251.5;
    $api = Mockery::mock(OddsApiService::class);
    $api->shouldReceive('getPlayerProps')->andReturn($response);
    (new SyncPlayerPropsForGames($api))->execute('2026-09-12', null, false);
    expect(PlayerProp::count())->toBe(2)->and(PlayerProp::whereNotNull('player_id')->count())->toBe(0);
    foreach (PlayerProp::all() as $prop) {
        expect(CfbPropEligibility::eligible($prop))->toBeFalse();
    }
});

test('supports January postseason and Central dates crossing UTC midnight but skips kickoff', function () {
    $this->travelTo(Carbon::parse('2027-01-12 00:00:00', 'UTC'));
    $this->game->update(['season_type' => 3, 'game_date' => '2027-01-12', 'game_time' => '01:00:00']);
    $response = ($this->response)();
    $response['commence_time'] = '2027-01-12T01:00:00Z';
    $api = Mockery::mock(OddsApiService::class);
    $api->shouldReceive('getPlayerProps')->once()->andReturn($response);
    $sync = new SyncPlayerPropsForGames($api);
    expect($sync->execute('2027-01-11', null, false)['stored'])->toBe(1);
    expect(app(PlayerPropAnalyzer::class)->getAvailableDatesForSport('CFB')->pluck('value')->all())->toBe(['2027-01-11']);
    $this->travelTo(now()->addHours(2));
    expect($sync->execute('2027-01-11', null, false)['games'])->toBe(0);
});

test('grades football yardage and pushes only after final with reported stats', function ($market, $field) {
    $prop = ($this->prop)($market, 100);
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'player_id' => $this->player->id, 'team_id' => $this->team->id, $field => 100]);
    $grader = app(GradePlayerProps::class);
    expect($grader->executeForGame('americanfootball_ncaaf', $this->game->id)['graded'])->toBe(0);
    $this->game->update(['status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 17]);
    expect($grader->executeForGame('americanfootball_ncaaf', $this->game->id)['graded'])->toBe(1);
    expect((float) $prop->fresh()->actual_value)->toBe(100.0)->and($prop->fresh()->hit_over)->toBeNull();
    expect($grader->executeForGame('americanfootball_ncaaf', $this->game->id)['graded'])->toBe(0);
})->with([['player_pass_yds', 'passing_yards'], ['player_rush_yds', 'rushing_yards'], ['player_reception_yds', 'receiving_yards']]);

test('missing categories nonparticipants and unsupported touchdown markets stay pending', function () {
    $this->game->update(['status' => 'STATUS_FINAL']);
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'player_id' => $this->player->id, 'team_id' => $this->team->id, 'passing_yards' => null]);
    ($this->prop)();
    ($this->prop)('player_anytime_td', .5);
    expect(app(GradePlayerProps::class)->executeForGame('americanfootball_ncaaf', $this->game->id)['graded'])->toBe(0);
});

test('blocks unavailable players stale quotes and departed roster links', function () {
    $prop = ($this->prop)()->fresh();
    expect(CfbPropEligibility::eligible($prop))->toBeTrue();
    $this->player->injuries()->create(['team_id' => $this->team->id, 'injury_key' => 'test', 'status' => 'Out', 'is_active' => true]);
    expect(CfbPropEligibility::eligible($prop->fresh()))->toBeFalse();
    $this->player->injuries()->delete();
    $prop->update(['fetched_at' => now()->subHours(2)]);
    expect(CfbPropEligibility::eligible($prop->fresh()))->toBeFalse();
    $prop->update(['fetched_at' => now()]);
    $this->player->update(['team_id' => Team::factory()->create()->id]);
    expect(CfbPropEligibility::eligible($prop->fresh()))->toBeFalse();
});

test('scores from prior reported current-team stats and excludes future and transferred-team games', function () {
    foreach ([['2026-09-01', $this->team->id, 300], ['2026-08-25', $this->team->id, 310], ['2025-12-20', $this->team->id, 320],
        ['2026-10-01', $this->team->id, 999], ['2025-12-01', $this->away->id, 999], ['2026-09-05', $this->team->id, null]] as [$date, $team, $yards]) {
        $game = Game::factory()->create(['home_team_id' => $team, 'away_team_id' => Team::factory()->create()->id,
            'season' => (int) substr($date, 0, 4), 'season_type' => 2, 'game_date' => $date, 'status' => 'STATUS_FINAL']);
        PlayerStat::factory()->create(['game_id' => $game->id, 'player_id' => $this->player->id, 'team_id' => $team, 'passing_yards' => $yards, 'passing_attempts' => 30]);
    }
    $prop = ($this->prop)('player_pass_yds', 200.5);
    app(PlayerPropAnalyzer::class)->analyzeProps(sport: 'CFB', gameFilter: $this->game->id, attachNarratives: false);
    expect((float) data_get($prop->fresh()->confidence_decomposition, 'stat_summary.season_avg'))->toBe(310.0);
    $this->player->injuries()->create(['team_id' => $this->team->id, 'injury_key' => 'test', 'status' => 'Out', 'is_active' => true]);
    expect(app(PlayerPropAnalyzer::class)->precomputedRecommendations(sport: 'CFB', gameFilter: $this->game->id))->toBeEmpty();
});

test('serves the college board and raw props through authenticated v2 endpoints', function () {
    Sanctum::actingAs(User::factory()->create());
    ($this->prop)();
    $this->getJson('/api/v2/sports/cfb/markets/player-props?date=2026-09-12')
        ->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.market', 'player_pass_yds');
    $this->getJson('/api/v2/sports/cfb/player-props/board?date=2026-09-12')
        ->assertOk()->assertJsonPath('sport', 'CFB')->assertJsonPath('data', []);
});

test('preparation imports roster before box scores and links previously unknown props', function () {
    $prop = ($this->prop)();
    $prop->update(['player_id' => null]);
    $this->player->delete();
    $espn = Mockery::mock(EspnService::class);
    $roster = ['athletes' => [['items' => [['id' => '123456', 'firstName' => 'Dante', 'lastName' => 'Moore', 'fullName' => 'Dante Moore', 'displayName' => 'Dante Moore']]]]];
    $espn->shouldReceive('getRoster')->with((string) $this->team->espn_id)->andReturn($roster);
    $other = ['athletes' => [['items' => [['id' => '654321', 'displayName' => 'Other Player', 'fullName' => 'Other Player']]]]];
    $espn->shouldReceive('getRoster')->with((string) $this->away->espn_id)->andReturn($other);
    $espn->shouldReceive('getTeamInjuries')->andReturn(['injuries' => []]);
    for ($i = 1; $i <= 3; $i++) {
        $history = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->away->id,
            'status' => 'STATUS_FINAL', 'season' => 2026, 'season_type' => 2, 'game_date' => "2026-08-0{$i}"]);
        $espn->shouldReceive('getGame')->with((string) $history->espn_event_id)->once()->andReturn(['boxscore' => ['players' => [[
            'team' => ['id' => (string) $this->team->espn_id], 'statistics' => [[
                'name' => 'passing', 'labels' => ['C/ATT', 'YDS', 'TD', 'INT'],
                'athletes' => [['athlete' => ['id' => '123456'], 'stats' => ['20/30', '300', '2', '0']]],
            ]],
        ]]]]);
    }
    app()->instance(EspnService::class, $espn);
    $this->artisan('cfb:prepare-player-props', ['--date' => '2026-09-12'])->assertSuccessful();
    expect(PlayerStat::count())->toBe(3)->and($prop->fresh()->player_id)->not->toBeNull();
    expect((float) data_get($prop->fresh()->confidence_decomposition, 'stat_summary.season_avg'))->toBe(300.0);
});

test('expired high-confidence college quotes do not hide eligible lower-ranked picks at the board limit', function () {
    $snapshot = ['recommended_side' => 'Over', 'confidence_score' => 90, 'predicted_over_probability' => 70,
        'confidence_decomposition' => ['schema_version' => 'player-prop-signal-v2', 'stat_summary' => ['season_avg' => 300], 'cover_record' => ['season' => ['games' => 6]]]];
    $expired = ($this->prop)();
    $expired->update([...$snapshot, 'fetched_at' => now()->subHours(2)]);
    $current = ($this->prop)();
    $current->update([...$snapshot, 'confidence_score' => 70]);
    $board = app(PlayerPropAnalyzer::class)->precomputedRecommendations(sport: 'CFB', gameFilter: $this->game->id, limit: 1);
    expect($board)->toHaveCount(1)->and($board->first()['prop']->id)->toBe($current->id);
});

test('grades college props immediately after final game details ingest player stats', function () {
    $this->game->update(['status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 17]);
    $prop = ($this->prop)('player_pass_yds', 200.5);
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'player_id' => $this->player->id, 'team_id' => $this->team->id, 'passing_yards' => 250]);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->with($this->game->espn_event_id)->andReturn(['boxscore' => [], 'header' => ['competitions' => [[
        'status' => ['type' => ['name' => 'STATUS_FINAL']],
        'competitors' => [['homeAway' => 'home', 'score' => '24'], ['homeAway' => 'away', 'score' => '17']],
    ]]]]);
    $stats = Mockery::mock();
    $stats->shouldReceive('execute')->once()->andReturn(1);
    $teams = Mockery::mock();
    $teams->shouldReceive('execute')->once()->andReturn(2);
    $plays = Mockery::mock();
    $plays->shouldReceive('execute')->once()->andReturn(1);
    (new SyncGameDetails($service, $stats, $teams, $plays))->execute($this->game->espn_event_id);
    expect($prop->fresh()->graded_at)->not->toBeNull()->and($prop->fresh()->hit_over)->toBeTrue();
});
