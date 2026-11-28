<?php

use App\Models\MLB\Game;
use App\Models\MLB\PeriodFeatureSnapshot;
use App\Models\MLB\Team;
use App\Services\MLB\MlbPeriodFeatureBuilder;
use App\Services\MLB\MlbPeriodFeatureStore;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\MLB\MlbGameStart;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../../Support/ObservedMlbVenueFixture.php';

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

beforeEach(function () {
    config(['app.timezone' => 'America/Chicago', 'sports.business_timezone' => 'America/Chicago']);
});
afterEach(fn () => $this->travelBack());

it('fences new period captures from subsequent schedule corrections without rewriting the capture', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $attributes = Game::factory()->make(['status' => 'STATUS_SCHEDULED'] + mlbSyntheticVenueDateParts('Boston', 'MA'))->getAttributes();
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);
    $store = app(MlbPeriodFeatureStore::class);
    $snapshots = $store->materialize(collect([$game]));
    expect($snapshots)->toHaveCount(1);
    $snapshot = $snapshots->sole()->fresh();
    $before = $snapshot->getAttributes();
    expect(MlbGameStart::capturedFor($game, data_get($snapshot->features, '_start_observation')))->toBeTrue();
    $features = $store->forGames(collect([$game]));
    expect($features[$game->id])->not->toHaveKey('_start_observation');
    $game->update(MlbGameStart::prepare($game, ['game_time' => '03:30:00'] + MlbGameStart::observed('2026-07-02T07:30:00Z', ['date' => '2026-07-02T07:30:00Z'])));
    expect(MlbGameStart::capturedFor($game, data_get($snapshot->features, '_start_observation')))->toBeFalse()
        ->and($snapshot->fresh()->getAttributes())->toBe($before);
});

it('reads period captures without materializing missing or invalid evidence', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class,
        Game::factory()->make(['status' => 'STATUS_SCHEDULED'] + mlbSyntheticVenueDateParts('Boston', 'MA'))->getAttributes());
    $store = app(MlbPeriodFeatureStore::class);
    expect($store->forGames(collect([$game])))->toBe([])
        ->and(PeriodFeatureSnapshot::count())->toBe(0);

    $snapshot = $store->materialize(collect([$game]))->sole();
    $before = $snapshot->fresh()->getAttributes();
    expect($store->forGames(collect([$game])))->toHaveKey($game->id)
        ->and($snapshot->fresh()->getAttributes())->toBe($before);

    // Trusted SQL corruption fixture: the read path must decline, never repair it.
    $features = $snapshot->features;
    $features['first_3_moneyline']['home_prior_games'] = 999;
    DB::table($snapshot->getTable())->where('id', $snapshot->id)->update(['features' => json_encode($features, JSON_THROW_ON_ERROR)]);
    $corrupt = $snapshot->fresh()->getAttributes();
    expect($store->forGames(collect([$game])))->toBe([])
        ->and($snapshot->fresh()->getAttributes())->toBe($corrupt)
        ->and(PeriodFeatureSnapshot::count())->toBe(1);
});

it('does not fall back to an older safe capture when the latest capture is unavailable', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class,
        Game::factory()->make(['status' => 'STATUS_SCHEDULED'] + mlbSyntheticVenueDateParts('Boston', 'MA'))->getAttributes());
    $store = app(MlbPeriodFeatureStore::class);
    $first = $store->materialize(collect([$game]))->sole();
    expect($store->forGames(collect([$game])))->toHaveKey($game->id);
    $latest = $first->replicate();
    $latest->feature_hash = str_repeat('d', 64);
    $latest->pregame_safe = false;
    $latest->availability_status = 'reconstructed_now';
    $latest->save();
    $before = $latest->fresh()->getAttributes();
    expect($store->forGames(collect([$game])))->toBe([])
        ->and($latest->fresh()->getAttributes())->toBe($before)
        ->and(PeriodFeatureSnapshot::count())->toBe(2);
});

it('appends a new current period capture for same-clock A B A feature reversion', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class,
        Game::factory()->make(['status' => 'STATUS_SCHEDULED'] + mlbSyntheticVenueDateParts('Boston', 'MA'))->getAttributes());
    $builder = Mockery::mock(MlbPeriodFeatureBuilder::class);
    $a = ['first_3_moneyline' => ['home_prior_games' => 1]];
    $b = ['first_3_moneyline' => ['home_prior_games' => 2]];
    $builder->shouldReceive('liveFeaturesForGames')->times(4)->andReturn([$game->id => $a], [$game->id => $b], [$game->id => $a], [$game->id => $a]);
    $store = new MlbPeriodFeatureStore($builder);
    $first = $store->materialize(collect([$game]))->sole();
    $second = $store->materialize(collect([$game]))->sole();
    $third = $store->materialize(collect([$game]))->sole();
    $retry = $store->materialize(collect([$game]))->sole();
    expect($third->id)->toBeGreaterThan($second->id)->and($second->id)->toBeGreaterThan($first->id)
        ->and($retry->id)->toBe($third->id)->and($retry->fresh()->getAttributes())->toBe($third->fresh()->getAttributes())
        ->and($store->forGames(collect([$game]))[$game->id])->toBe($a)
        ->and(PeriodFeatureSnapshot::count())->toBe(3);
});

it('declines a lossy period capture clock before inserting its nonnullable legacy timestamp', function () {
    $this->travelTo(Carbon::parse('2026-10-31T12:00:00Z'));
    $game = Game::factory()->withSyntheticWallStart('America/Chicago')->create([
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'game_date' => '2026-11-02', 'game_time' => '12:00:00', 'status' => 'STATUS_SCHEDULED',
    ]);
    $lossy = collect(['2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z'])->first(fn ($clock) => MlbGameStart::legacyTimestamp(Carbon::parse($clock)) === null);
    expect($lossy)->not->toBeNull();
    $this->travelTo(Carbon::parse($lossy));
    $store = app(MlbPeriodFeatureStore::class);
    expect($store->materialize(collect([$game])))->toHaveCount(0)
        ->and(PeriodFeatureSnapshot::count())->toBe(0)
        ->and(MlbGameStart::for($game))->not->toBeNull();
});
