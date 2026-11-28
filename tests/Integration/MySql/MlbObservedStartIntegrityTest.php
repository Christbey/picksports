<?php

use App\Models\MLB\Game;
use App\Models\MLB\PeriodFeatureSnapshot;
use App\Models\MLB\Team;
use App\Services\MLB\MlbPeriodFeatureStore;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Services\Sports\SportsDateWindowService;
use App\Support\MLB\MlbGameStart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql')->and(DB::getDatabaseName())->toBe('picksports_test_readiness');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['app.timezone' => 'America/Chicago', 'sports.business_timezone' => 'America/Chicago']);
});

it('retains exact signed epochs across venue walls and repeated hours on actual MySQL', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    foreach ([
        ['2026-07-02T06:30:00Z', '2026-07-02', '02:30:00'],
        ['2026-07-02T15:30:00+09:00', '2026-07-01', '23:30:00'],
        ['2026-11-01T01:30:00-05:00', '2026-11-01', '01:30:00'],
        ['2026-11-01T01:30:00-06:00', '2026-11-01', '01:30:00'],
        ['1965-07-02T06:30:00Z', '1965-07-02', '01:30:00'],
    ] as [$source, $date, $time]) {
        $attributes = Game::factory()->make(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_date' => $date, 'game_time' => $time])->getAttributes() + MlbGameStart::observed($source, ['date' => $source]);
        $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes)->fresh();
        expect(MlbGameStart::for($game)?->getTimestamp())->toBe(Carbon::parse($source)->getTimestamp())
            ->and(MlbGameStart::known(Game::query())->whereKey($game->id)->exists())->toBeTrue();
        $canonical = $game->sportEvent->starts_at;
        expect($canonical === null || $canonical->getTimestamp() === Carbon::parse($source)->getTimestamp())->toBeTrue();
        if (str_starts_with($source, '1965')) {
            expect($game->start_epoch)->toBeLessThan(0)->and($canonical)->toBeNull();
        }
    }
    $column = collect(DB::connection()->getSchemaBuilder()->getColumns('mlb_games'))->firstWhere('name', 'start_epoch');
    expect($column['nullable'])->toBeTrue()->and(strtolower($column['type']))->toBe('bigint');
});

it('verifies captured JSON after MySQL key ordering and declines current context corruption', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = Game::factory()->withSyntheticWallStart('America/Chicago')->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'game_date' => '2026-07-02', 'game_time' => '01:30:00', 'status' => 'STATUS_SCHEDULED']);
    $game->refresh();
    $dates = app(SportsDateWindowService::class);
    expect($dates->applyGameDateWindow(Game::query(), $dates->forDate('2026-07-02'))->whereKey($game->id)->exists())->toBeTrue();
    $capture = array_reverse(MlbGameStart::capture($game, now()), true);
    $features = ['_capture_id' => (string) Str::uuid(), '_start_observation' => $capture, 'first_3_moneyline' => ['home_prior_games' => 2, 'away_prior_games' => 1]];
    $hasher = app(CanonicalPayloadHasher::class);
    $snapshot = PeriodFeatureSnapshot::create(['game_id' => $game->id,
        'feature_version' => MlbPeriodFeatureStore::FEATURE_VERSION,
        'feature_hash' => $hasher->hash($features), 'features' => $features, 'pregame_safe' => true,
        'availability_status' => 'observed_pregame', 'game_start_at' => MlbGameStart::canonical($game),
        'features_available_at' => MlbGameStart::legacyTimestamp(now())]);
    expect($hasher->hash($snapshot->fresh()->features))->toBe($snapshot->feature_hash)
        ->and(MlbGameStart::capturedFor($game, data_get($snapshot->fresh()->features, '_start_observation')))->toBeTrue()
        ->and(app(MlbPeriodFeatureStore::class)->forGames(collect([$game])))->toHaveKey($game->id);
    $game->newQuery()->whereKey($game->id)->update(['start_context_hash' => str_repeat('f', 64)]);
    expect(MlbGameStart::for($game->fresh()))->toBeNull()
        ->and($dates->applyGameDateWindow(Game::query(), $dates->forDate('2026-07-02'))->whereKey($game->id)->exists())->toBeFalse()
        ->and(MlbGameStart::before(Game::query(), Carbon::parse('2026-07-03T00:00:00Z'))->whereKey($game->id)->exists())->toBeFalse()
        ->and(app(MlbPeriodFeatureStore::class)->forGames(collect([$game->fresh()])))->toBe([]);
});
