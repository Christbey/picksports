<?php

use App\Actions\CFB\UpdateLivePrediction;
use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\User;
use App\Services\CFB\Live\LiveBettingSync;
use App\Services\CFB\Live\LiveMarketComparison;
use App\Services\CFB\Live\LivePropProjector;
use App\Services\CFB\Live\LiveSnapshotRecorder;
use App\Services\ESPN\CFB\EspnService;
use App\Services\OddsApi\OddsApiService;
use App\Support\PredictionFieldAccess;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-12T18:00:00Z'));
    $this->home = Team::factory()->create();
    $this->away = Team::factory()->create();
    $this->game = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'season' => 2026, 'season_type' => 2, 'game_date' => '2026-09-12', 'game_time' => '17:00:00',
        'status' => 'STATUS_IN_PROGRESS', 'period' => 2, 'game_clock' => '00:00', 'home_score' => 14, 'away_score' => 7,
        'odds_api_event_id' => 'live-event']);
    $this->prediction = Prediction::create(['game_id' => $this->game->id, 'predicted_spread' => 10, 'predicted_total' => 50, 'win_probability' => .7, 'confidence_score' => 65]);
    $this->market = fn ($time = '2026-09-12T17:59:30Z') => ['id' => 'live-event', 'commence_time' => '2026-09-12T17:00:00Z', 'home_team' => 'Home', 'away_team' => 'Away',
        'bookmakers' => [['key' => 'fanduel', 'markets' => [
            ['key' => 'spreads', 'last_update' => $time, 'outcomes' => [['name' => 'Home', 'point' => -7.5, 'price' => -110], ['name' => 'Away', 'point' => 7.5, 'price' => -110]]],
            ['key' => 'totals', 'last_update' => $time, 'outcomes' => [['name' => 'Over', 'point' => 45.5, 'price' => -110], ['name' => 'Under', 'point' => 45.5, 'price' => -110]]],
            ['key' => 'h2h', 'last_update' => $time, 'outcomes' => [['name' => 'Home', 'price' => -200], ['name' => 'Away', 'price' => 170]]],
        ]]]];
});

test('live updates and finalization preserve the pregame forecast and append history', function () {
    $original = $this->prediction->only(['predicted_spread', 'predicted_total', 'win_probability', 'confidence_score']);
    $action = app(UpdateLivePrediction::class);
    expect($action->execute($this->game))->not->toBeNull();
    $first = LivePredictionSnapshot::first();
    expect($this->prediction->fresh()->only(array_keys($original)))->toBe($original);
    $this->game->update(['period' => 3, 'game_clock' => '10:00', 'home_score' => 14, 'away_score' => 21]);
    $action->execute($this->game->fresh());
    expect(LivePredictionSnapshot::count())->toBe(2)->and($first->fresh()->state['home_score'])->toBe(14);
    $this->game->update(['status' => 'STATUS_FINAL', 'period' => 4, 'home_score' => 28, 'away_score' => 31]);
    $action->execute($this->game->fresh());
    expect(LivePredictionSnapshot::count())->toBe(3)->and(LivePredictionSnapshot::latest('id')->first()->status)->toBe('final');
    expect($this->prediction->fresh()->live_predicted_total)->toBeNull()->and($this->prediction->fresh()->only(array_keys($original)))->toBe($original);
    expect(fn () => $first->update(['status' => 'changed']))->toThrow(LogicException::class);
});

test('freezes the original pregame baseline even if another process later changes the legacy row', function () {
    app(UpdateLivePrediction::class)->execute($this->game);
    $this->prediction->update(['predicted_spread' => 99]);
    $this->game->update(['game_clock' => '01:00']);
    app(UpdateLivePrediction::class)->execute($this->game->fresh());
    expect((float) LivePredictionSnapshot::latest('id')->first()->pregame['spread'])->toBe(10.0);
    expect((float) $this->prediction->fresh()->predicted_spread)->toBe(99.0);
});

test('withholds live forecasts for missing state or college overtime', function ($changes, $status) {
    $this->game->update($changes);
    expect(app(UpdateLivePrediction::class)->execute($this->game->fresh()))->toBeNull();
    expect(LivePredictionSnapshot::first()->status)->toBe($status)->and(LivePredictionSnapshot::first()->projection)->toBeNull();
})->with([[['game_clock' => null], 'missing_clock'], [['home_score' => null], 'missing_score'], [['period' => 5, 'game_clock' => '00:00'], 'overtime_unmodeled']]);

test('handles home spread signs and refuses stale or pregame market quotes', function () {
    $service = app(LiveMarketComparison::class);
    $projection = ['spread' => 10, 'total' => 50, 'home_win_probability' => .75];
    $rows = $service->games(($this->market)(), $projection);
    expect($rows[0]['difference'])->toBe(2.5)->and($rows[0]['lean'])->toBe('Home');
    expect($rows[1]['difference'])->toBe(4.5)->and($rows[2]['lean'])->toBe('Home');
    foreach (['2026-09-12T17:50:00Z', '2026-09-12T16:59:00Z'] as $time) {
        expect($service->games(($this->market)($time), $projection)[0]['difference'])->toBeNull();
    }
});

test('live prop estimates add remaining production and never modify pregame props', function () {
    $player = Player::factory()->create(['espn_id' => 'live-player', 'full_name' => 'Test Runner', 'team_id' => $this->home->id]);
    $prop = PlayerProp::create(['game_id' => $this->game->id, 'player_id' => $player->id, 'player_name' => 'Test Runner', 'market' => 'player_rush_yds', 'line' => 80.5, 'fetched_at' => now()->subHours(2), 'recommended_side' => 'Over']);
    for ($i = 1; $i <= 3; $i++) {
        $history = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => "2026-08-0{$i}"]);
        PlayerStat::factory()->create(['game_id' => $history->id, 'player_id' => $player->id, 'team_id' => $this->home->id, 'rushing_yards' => 100]);
    }
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'player_id' => $player->id, 'team_id' => $this->home->id, 'rushing_yards' => 60]);
    $before = $prop->fresh()->getAttributes();
    $rows = app(LivePropProjector::class)->project($this->game, ['seconds_remaining' => 1800], null, true);
    expect($rows[0]['projected'])->toBe(115.0)->and($rows[0]['quotes'])->toBe([])->and($prop->fresh()->getAttributes())->toBe($before);
    expect(app(LivePropProjector::class)->project($this->game, ['seconds_remaining' => 1800], null, false)[0]['projected'])->toBeNull();
    $response = ($this->market)();
    $response['bookmakers'][0]['markets'][] = ['key' => 'player_rush_yds', 'last_update' => '2026-09-12T17:59:30Z',
        'outcomes' => [['name' => 'Over', 'description' => 'Test Runner', 'point' => 120.5, 'price' => -110],
            ['name' => 'Under', 'description' => 'Test Runner', 'point' => 120.5, 'price' => -110]]];
    $rows = app(LivePropProjector::class)->project($this->game, ['seconds_remaining' => 1800], $response, true);
    expect($rows[0]['quotes'][0]['line'])->toBe(120.5)->and($rows[0]['quotes'][0]['lean'])->toBe('Under')->and($rows[0]['quotes'][0]['difference'])->toBe(-5.5);
    app(UpdateLivePrediction::class)->execute($this->game);
    $base = LivePredictionSnapshot::first();
    app(LiveSnapshotRecorder::class)->record($this->game, $base->pregame, $base->projection, 'live', 'live_feed', [], $rows);
    $this->game->update(['status' => 'STATUS_FINAL']);
    PlayerStat::where('game_id', $this->game->id)->update(['rushing_yards' => 150]);
    $finalRows = app(LivePropProjector::class)->project($this->game->fresh(), null, null, true);
    app(LiveSnapshotRecorder::class)->record($this->game->fresh(), $base->pregame, null, 'final', 'live_feed', [], $finalRows);
    Sanctum::actingAs(User::factory()->create());
    $response = $this->getJson('/api/v2/sports/cfb/games/'.$this->game->id.'/live-betting')->assertOk();
    $history = collect($response->json('history'))->first(fn ($row) => count($row['props'][0]['quotes'] ?? []) > 0);
    expect($history['props'][0]['quotes'][0]['result'])->toBe('loss');
    expect($prop->fresh()->getAttributes())->toBe($before);

});

test('live capture uses uncached feeds and keeps pregame odds and predictions intact', function () {
    $this->game->update(['odds_data' => ['pregame' => 'keep']]);
    $espn = Mockery::mock(EspnService::class);
    $espn->shouldReceive('getLiveGame')->with((string) $this->game->espn_event_id)->once()->andReturn(['header' => ['id' => $this->game->espn_event_id, 'competitions' => [[
        'status' => ['type' => ['name' => 'STATUS_IN_PROGRESS'], 'period' => 2, 'displayClock' => '1:00'],
        'competitors' => [['homeAway' => 'home', 'score' => '14'], ['homeAway' => 'away', 'score' => '7']],
    ]]]]);
    $odds = Mockery::mock(OddsApiService::class);
    $odds->shouldReceive('getEventOdds')->with('americanfootball_ncaaf', 'live-event', Mockery::type('array'), 'fanduel,draftkings', false)->once()->andReturn(($this->market)());
    $result = (new LiveBettingSync($espn, $odds))->execute($this->game);
    expect($result['status'])->toBe('captured')->and($result['markets'])->toBe(3);
    expect($this->game->fresh()->odds_data)->toBe(['pregame' => 'keep'])->and((float) $this->prediction->fresh()->predicted_spread)->toBe(10.0);
});

test('live endpoint requires authentication and honors forecast field access', function () {
    app(UpdateLivePrediction::class)->execute($this->game);
    $url = '/api/v2/sports/cfb/games/'.$this->game->id.'/live-betting';
    $this->getJson($url)->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());
    $access = Mockery::mock(PredictionFieldAccess::class);
    $access->shouldReceive('canViewField')->andReturn(false);
    app()->instance(PredictionFieldAccess::class, $access);
    $this->getJson($url)->assertOk()->assertJsonPath('data.pregame.spread', null)->assertJsonPath('data.projection.home_win_probability', null);
    $this->getJson('/api/v2/sports/nfl/games/'.$this->game->id.'/live-betting')->assertNotFound();
});

test('read-time expiry hides comparisons while retaining captured history and final results', function () {
    app(UpdateLivePrediction::class)->execute($this->game);
    $base = LivePredictionSnapshot::first();
    $markets = app(LiveMarketComparison::class)->games(($this->market)('2026-09-12T17:58:00Z'), ['spread' => 10, 'total' => 50, 'home_win_probability' => .75]);
    app(LiveSnapshotRecorder::class)->record($this->game, $base->pregame, $base->projection, 'live', 'live_feed', $markets);
    Sanctum::actingAs(User::factory()->create());
    $access = Mockery::mock(PredictionFieldAccess::class);
    $access->shouldReceive('canViewField')->andReturn(true);
    app()->instance(PredictionFieldAccess::class, $access);
    $url = '/api/v2/sports/cfb/games/'.$this->game->id.'/live-betting';
    $this->getJson($url)->assertOk()->assertJsonPath('data.markets.0.lean', 'Home');
    $this->travel(2)->minutes();
    $this->getJson($url)->assertOk()->assertJsonPath('data.stale', false)->assertJsonPath('data.markets.0.fresh', false)
        ->assertJsonPath('data.markets.0.lean', null)->assertJsonPath('data.markets.0.lean_at_capture', 'Home');
    $this->game->update(['status' => 'STATUS_FINAL', 'home_score' => 28, 'away_score' => 21]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.stale', true)->assertJsonPath('data.markets.0.result', 'loss');
});

test('API failure still saves a projection without recycling prior live market lines', function () {
    $espn = Mockery::mock(EspnService::class);
    $espn->shouldReceive('getLiveGame')->andReturn(['header' => ['id' => $this->game->espn_event_id, 'competitions' => [[
        'status' => ['type' => ['name' => 'STATUS_IN_PROGRESS'], 'period' => 2, 'displayClock' => '1:00'],
        'competitors' => [['homeAway' => 'home', 'score' => '14'], ['homeAway' => 'away', 'score' => '7']],
    ]]]]);
    $odds = Mockery::mock(OddsApiService::class);
    $odds->shouldReceive('getEventOdds')->andReturn(null);
    $result = (new LiveBettingSync($espn, $odds))->execute($this->game);
    expect($result['market_status'])->toBe('unavailable')->and($result['markets'])->toBe(0);
    expect(LivePredictionSnapshot::where('source', 'live_feed')->first()->projection)->not->toBeNull();
});

test('a final game without final box scores remains pending for a later capture', function () {
    app(UpdateLivePrediction::class)->execute($this->game);
    $espn = Mockery::mock(EspnService::class);
    $espn->shouldReceive('getLiveGame')->andReturn(['header' => ['id' => $this->game->espn_event_id, 'competitions' => [[
        'status' => ['type' => ['name' => 'STATUS_FINAL'], 'period' => 4, 'displayClock' => '0:00'],
        'competitors' => [['homeAway' => 'home', 'score' => '28'], ['homeAway' => 'away', 'score' => '21']],
    ]]]]);
    $odds = Mockery::mock(OddsApiService::class);
    $odds->shouldNotReceive('getEventOdds');
    (new LiveBettingSync($espn, $odds))->execute($this->game);
    expect(LivePredictionSnapshot::where('source', 'live_feed')->latest('id')->first()->status)->toBe('final_pending_stats');
});
