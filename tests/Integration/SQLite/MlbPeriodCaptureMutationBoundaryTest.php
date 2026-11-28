<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\PeriodFeatureSnapshot;
use App\Models\MLB\Team;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\MLB\MlbPeriodFeatureStore;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ObservedMlbGameFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo('2026-10-30T10:00:00Z');
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true, 'prediction_lifecycle.canonical_reads.mlb' => true]);
});

afterEach(function () {
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function ownedPeriodMutationCapture(): PeriodFeatureSnapshot
{
    $start = now()->addDay()->toImmutable();
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'season' => 2026, 'starts_at' => $start]);
    $game = ObservedMlbGameFixture::observe(Game::factory()->create([
        'sport_event_id' => $event->id, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(),
    ]), $start);
    $input = EventInputSnapshot::factory()->create(ObservedMlbGameFixture::snapshotAttributes($game, [
        'event' => ['starts_at' => $start->toIso8601String()],
        'home' => ['team_id' => $game->home_team_id, 'elo' => 1510],
        'away' => ['team_id' => $game->away_team_id, 'elo' => 1480],
        'pitching' => ['home' => ['elo' => 1600], 'away' => ['elo' => 1500]],
    ]));
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'mlb', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded', 'output_hash' => str_repeat('a', 64)]);
    $native = CanonicalPrediction::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'generated_at' => now(), 'output_hash' => str_repeat('a', 64), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, .65], ['spread', 'home', -2, null], ['total', 'combined', 9, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);
    // Real observed source, selected canonical inputs, feature builder and owning store.
    $capture = app(MlbPeriodFeatureStore::class)->materialize(collect([$game]))->sole();
    expect($capture->forecast_source)->toBe('canonical')
        ->and($capture->canonical_prediction_id)->toBe($native->id)
        ->and($capture->pregame_safe)->toBeTrue()
        ->and(data_get($capture->features, '_capture_id'))->toBeString();

    return $capture->refresh();
}

it('keeps an actual owned period capture immutable through every instance mutation path', function (string $method) {
    $capture = ownedPeriodMutationCapture();
    $before = DB::table('mlb_period_feature_snapshots')->where('id', $capture->id)->first();
    $write = fn () => match ($method) {
        'update' => $capture->update(['feature_hash' => str_repeat('b', 64)]),
        'saveQuietly' => $capture->forceFill(['features' => ['changed' => true]])->saveQuietly(),
        'updateQuietly' => $capture->updateQuietly(['feature_hash' => str_repeat('b', 64)]),
        'incrementQuietly' => $capture->incrementQuietly('pregame_safe', 1, ['feature_hash' => str_repeat('b', 64)]),
        'delete' => $capture->delete(),
        'deleteQuietly' => $capture->deleteQuietly(),
    };
    $refused = false;
    try {
        $write();
    } catch (LogicException) {
        $refused = true;
    }
    // Assert actual raw source preservation first, including quiet deletion.
    expect(DB::table('mlb_period_feature_snapshots')->where('id', $capture->id)->first())->toEqual($before)
        ->and($refused)->toBeTrue();
})->with(['update', 'saveQuietly', 'updateQuietly', 'incrementQuietly', 'delete', 'deleteQuietly']);

function legacyPeriodMutationCapture(): PeriodFeatureSnapshot
{
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_SCHEDULED']);

    return PeriodFeatureSnapshot::create(['game_id' => $game->id, 'forecast_source' => 'legacy',
        'feature_version' => 'legacy-descriptive', 'feature_hash' => hash('sha256', 'legacy-'.$game->id),
        'features' => ['signal' => 0], 'features_available_at' => now(), 'game_start_at' => now()->addDay(),
        'pregame_safe' => false, 'availability_status' => 'reconstructed_now']);
}

it('retains ordinary legacy mutability without allowing an existing row to acquire canonical source identity', function () {
    $legacy = legacyPeriodMutationCapture();
    expect($legacy->update(['features' => ['signal' => 1]]))->toBeTrue();
    $legacy->forceFill(['features' => ['signal' => 2]])->saveQuietly();
    $legacy->incrementQuietly('pregame_safe', 0, ['feature_hash' => hash('sha256', 'legacy-changed')]);
    expect($legacy->fresh()->features)->toBe(['signal' => 2])->and($legacy->fresh()->pregame_safe)->toBeFalse();
    $legacy->deleteQuietly();
    expect(PeriodFeatureSnapshot::find($legacy->id))->toBeNull();

    foreach (['update', 'updateQuietly', 'incrementQuietly', 'expression'] as $method) {
        $legacy = legacyPeriodMutationCapture();
        $before = DB::table('mlb_period_feature_snapshots')->where('id', $legacy->id)->first();
        $write = fn () => match ($method) {
            'update' => $legacy->update(['forecast_source' => 'canonical']),
            'updateQuietly' => $legacy->updateQuietly(['forecast_source' => 'canonical']),
            'incrementQuietly' => $legacy->incrementQuietly('pregame_safe', 0, ['forecast_source' => 'canonical']),
            'expression' => $legacy->updateQuietly(['forecast_source' => DB::raw("'canonical'")]),
        };
        expect($write)->toThrow(LogicException::class);
        expect(DB::table('mlb_period_feature_snapshots')->where('id', $legacy->id)->first())->toEqual($before);
    }
});

it('uses current bounded classification for stale templates while preserving a no-op save and raw captured bytes', function () {
    $capture = ownedPeriodMutationCapture();
    $id = $capture->id;
    $before = DB::table('mlb_period_feature_snapshots')->where('id', $id)->first();
    $this->travelTo(now()->addMinute());
    expect($capture->saveQuietly())->toBeTrue()
        ->and(DB::table('mlb_period_feature_snapshots')->where('id', $id)->first())->toEqual($before);
    $stale = clone $capture;
    $stale->setRawAttributes([...$stale->getAttributes(), 'forecast_source' => 'legacy'], true);
    expect(fn () => $stale->forceFill(['feature_hash' => str_repeat('c', 64)])->saveQuietly())->toThrow(LogicException::class);
    $redirected = clone $capture;
    expect(fn () => $redirected->forceFill(['id' => $id + 1000])->saveQuietly())->toThrow(LogicException::class);
    expect(DB::table('mlb_period_feature_snapshots')->where('id', $id)->first())->toEqual($before)
        ->and(PeriodFeatureSnapshot::count())->toBe(1);

    // Trusted direct SQL is outside the instance guard; it supplies a large-body negative fixture only.
    $body = json_encode(['data' => str_repeat('x', 8388608)], JSON_THROW_ON_ERROR);
    DB::table('mlb_period_feature_snapshots')->where('id', $id)->update(['features' => $body]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(fn () => $capture->deleteQuietly())->toThrow(LogicException::class);
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_starts_with($sql, 'select') && str_contains($sql, 'mlb_period_feature_snapshots'));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->not->toBeEmpty()
        ->and($queries->implode(' '))->toContain('forecast_source')->not->toContain('*', '"features"', '`features`')
        ->and((int) DB::table('mlb_period_feature_snapshots')->where('id', $id)->selectRaw('length(CAST(features AS BLOB)) AS body_bytes')->first()->body_bytes)->toBe(strlen($body));
    DB::table('mlb_period_feature_snapshots')->where('id', $id)->update(['forecast_source' => str_repeat('x', 1048576)]);
    expect(fn () => $capture->deleteQuietly())->toThrow(LogicException::class, 'scalar budget');
});
