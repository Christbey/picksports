<?php

use App\Models\MLB\Game;
use App\Models\MLB\PeriodFeatureSnapshot;
use App\Models\MLB\Team;
use App\Services\MLB\MlbPeriodFeatureBuilder;
use App\Services\MLB\MlbPeriodFeatureStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\EloClockFixture;
use Tests\Support\ObservedMlbGameFixture;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Period lock-order proof requires disposable MySQL.');
    }
    $this->periodNativeDefault = DB::getDefaultConnection();
    $this->travelTo('2026-10-30 10:00:00');
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    Http::preventStrayRequests();
    EloClockFixture::prepare();
    config(['database.connections.period_publisher_peer' => config('database.connections.'.$this->periodNativeDefault)]);
    $peer = DB::connection('period_publisher_peer');
    // Fixture-only bounded waits and explicit UTC; application code never initializes sessions.
    $peer->statement("SET SESSION time_zone = '+00:00'");
    $peer->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 1');
    expect($peer->getPdo())->not->toBe(DB::connection()->getPdo());
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true, 'prediction_lifecycle.canonical_reads.mlb' => true]);
});

afterEach(function () {
    DB::setDefaultConnection($this->periodNativeDefault);
    foreach ([$this->periodNativeDefault, 'period_publisher_peer'] as $name) {
        $connection = DB::connection($name);
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::purge('period_publisher_peer');
    $this->travelBack();
});

function nativePeriodOrderGame(): Game
{
    return ObservedMlbGameFixture::observe(Game::factory()->create([
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'status' => 'STATUS_SCHEDULED',
    ]), now()->addDay()->toImmutable());
}

function nativePeriodOrderBuilder(Game $game): MlbPeriodFeatureBuilder
{
    $builder = Mockery::mock(MlbPeriodFeatureBuilder::class);
    $builder->shouldReceive('liveFeaturesForGames')->once()->andReturn([
        $game->id => ['first_3_moneyline' => ['feature_home_pitcher_elo' => null, 'feature_pitcher_context_known' => 0]],
    ]);

    return $builder;
}

it('does not hold Game while waiting behind an independent publisher-order Event owner', function () {
    $game = nativePeriodOrderGame();
    $peer = DB::connection('period_publisher_peer');
    $peer->beginTransaction();
    // The real publisher's first shared parent lock is Event; this actor reproduces that lock boundary.
    $peer->table('sport_events')->where('id', $game->sport_event_id)->lockForUpdate()->first(['id']);
    $gameAvailable = false;
    $probeFailure = null;
    $armed = true;
    DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$gameAvailable, &$probeFailure, $peer, $game) {
        if (! $armed || ! str_contains($sql, '`sport_events`') || ! str_contains($sql, 'for update')) {
            return;
        }
        $armed = false;
        try {
            $peer->table('mlb_games')->where('id', $game->id)->lockForUpdate()->first(['id']);
            $gameAvailable = true;
        } catch (QueryException $error) {
            $probeFailure = ['number' => $error->errorInfo[1] ?? null, 'query' => $error->getSql()];
        }
    });
    $waitFailure = null;
    try {
        (new MlbPeriodFeatureStore(nativePeriodOrderBuilder($game)))->materialize(collect([$game]));
    } catch (Throwable $error) {
        do {
            if ($error instanceof QueryException) {
                $waitFailure = ['number' => $error->errorInfo[1] ?? null, 'query' => $error->getSql()];
                break;
            }
            $error = $error->getPrevious();
        } while ($error !== null);
    } finally {
        $armed = false;
        $peer->rollBack();
    }
    expect($waitFailure['number'] ?? null)->toBe(1205)
        ->and($waitFailure['query'] ?? '')->toContain('sport_events', 'for update')
        ->and($probeFailure)->toBeNull()->and($gameAvailable)->toBeTrue()
        ->and(PeriodFeatureSnapshot::count())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('captures the observed canonical unknown-pitcher fallback after independent Event ownership is released', function () {
    $game = nativePeriodOrderGame();
    $row = (new MlbPeriodFeatureStore(nativePeriodOrderBuilder($game)))->materialize(collect([$game]))->sole();
    expect($row->forecast_source)->toBe('canonical')->and($row->canonical_prediction_id)->toBeNull()
        ->and($row->pregame_safe)->toBeTrue()->and($row->features['first_3_moneyline']['feature_pitcher_context_known'])->toBe(0);
});
