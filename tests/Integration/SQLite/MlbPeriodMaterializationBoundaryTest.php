<?php

use App\Models\MLB\Game;
use App\Models\MLB\PeriodFeatureSnapshot;
use App\Models\MLB\Team;
use App\Models\SportEvent;
use App\Services\MLB\MlbPeriodFeatureBuilder;
use App\Services\MLB\MlbPeriodFeatureStore;
use App\Support\MLB\MlbGameStart;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ObservedMlbGameFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo('2026-10-30 10:00:00');
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true, 'prediction_lifecycle.canonical_reads.mlb' => true]);
});

afterEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function periodBoundaryGame(): Game
{
    return ObservedMlbGameFixture::observe(Game::factory()->create([
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'status' => 'STATUS_SCHEDULED',
    ]), now()->addDay()->toImmutable());
}

function periodBoundaryBuilder(Game $game, ?Closure $duringReplay = null): MlbPeriodFeatureBuilder
{
    $builder = Mockery::mock(MlbPeriodFeatureBuilder::class);
    $builder->shouldReceive('liveFeaturesForGames')->once()->andReturnUsing(function () use ($game, $duringReplay) {
        $duringReplay?->__invoke();

        return [$game->id => ['first_3_moneyline' => ['feature_home_pitcher_elo' => null, 'feature_pitcher_context_known' => 0]]];
    });

    return $builder;
}

it('refuses an unknown inherited transaction before replaying or persisting period inputs', function () {
    $game = periodBoundaryGame();
    $builder = Mockery::mock(MlbPeriodFeatureBuilder::class);
    $builder->shouldNotReceive('liveFeaturesForGames');
    DB::beginTransaction();
    try {
        expect(fn () => (new MlbPeriodFeatureStore($builder))->materialize(collect([$game])))
            ->toThrow(RuntimeException::class, 'requires its own transaction');
        expect(PeriodFeatureSnapshot::count())->toBe(0);
    } finally {
        DB::rollBack();
    }
});

it('retains observed canonical period fallback without borrowing unqualified pitcher inputs', function () {
    $game = periodBoundaryGame();
    $store = new MlbPeriodFeatureStore(periodBoundaryBuilder($game));
    $snapshot = $store->materialize(collect([$game]))->sole();
    expect($snapshot->forecast_source)->toBe('canonical')
        ->and($snapshot->canonical_prediction_id)->toBeNull()
        ->and($snapshot->pregame_safe)->toBeTrue()
        ->and(MlbGameStart::capturedFor($game, $snapshot->features['_start_observation']))->toBeTrue()
        ->and($store->forGames(collect([$game]))[$game->id])->toBe(array_intersect_key($snapshot->features, MlbPeriodFeatureBuilder::MARKETS));
});

it('refuses a changed event owner discovered after replay without widening the event lock set', function () {
    $game = periodBoundaryGame();
    $oldEvent = $game->sport_event_id;
    $replacement = SportEvent::factory()->create(['sport' => 'mlb', 'starts_at' => $game->sportEvent->starts_at]);
    $builder = periodBoundaryBuilder($game, fn () => DB::table('mlb_games')->where('id', $game->id)->update(['sport_event_id' => $replacement->id]));
    $eventLocks = [];
    $armed = true;
    DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$eventLocks, &$armed) {
        if ($armed && str_starts_with($sql, 'select "id", "sport", "starts_at" from "sport_events"')) {
            $eventLocks[] = $bindings;
        }
    });
    try {
        expect((new MlbPeriodFeatureStore($builder))->materialize(collect([$game])))->toBeEmpty()
            ->and(PeriodFeatureSnapshot::count())->toBe(0)
            ->and($eventLocks)->toBe([[$oldEvent]]);
    } finally {
        $armed = false;
    }
});

it('keeps legacy period materialization game-only without acquiring canonical event locks', function () {
    config(['prediction_lifecycle.canonical_reads.mlb' => false]);
    $game = periodBoundaryGame();
    $eventReads = [];
    $armed = true;
    DB::connection()->beforeExecuting(function (string $sql) use (&$eventReads, &$armed) {
        if ($armed && str_contains($sql, '"sport_events"')) {
            $eventReads[] = $sql;
        }
    });
    try {
        $row = (new MlbPeriodFeatureStore(periodBoundaryBuilder($game)))->materialize(collect([$game]))->sole();
        expect($row->forecast_source)->toBe('legacy')->and($eventReads)->toBe([]);
    } finally {
        $armed = false;
    }
});
