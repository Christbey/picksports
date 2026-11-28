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
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ObservedMlbGameFixture;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo('2026-10-30T10:00:00Z');
    Http::preventStrayRequests();
    $this->periodOwner = DB::getDefaultConnection();
    config(['database.connections.period_guard_peer' => config('database.connections.'.$this->periodOwner),
        'prediction_lifecycle.canonical_pipeline.mlb' => true, 'prediction_lifecycle.canonical_reads.mlb' => true]);
    foreach ([$this->periodOwner, 'period_guard_peer'] as $name) {
        DB::connection($name)->statement("SET SESSION time_zone = '+00:00'");
        DB::connection($name)->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::connection($name)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }
    expect(DB::connection()->getPdo())->not->toBe(DB::connection('period_guard_peer')->getPdo());
    expect(DB::connection()->scalar('SELECT CONNECTION_ID()'))->not->toBe(DB::connection('period_guard_peer')->scalar('SELECT CONNECTION_ID()'));
});

afterEach(function () {
    DB::setDefaultConnection($this->periodOwner);
    foreach ([$this->periodOwner, 'period_guard_peer'] as $name) {
        while (DB::connection($name)->transactionLevel() > 0) {
            DB::connection($name)->rollBack();
        }
    }
    DB::purge('period_guard_peer');
    $this->travelBack();
});

function nativeOwnedPeriodCapture(): PeriodFeatureSnapshot
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

function nativeLegacyPeriodCapture(): PeriodFeatureSnapshot
{
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_SCHEDULED']);

    return PeriodFeatureSnapshot::create(['game_id' => $game->id, 'forecast_source' => 'legacy',
        'feature_version' => 'legacy-descriptive', 'feature_hash' => hash('sha256', 'legacy-'.$game->id),
        'features' => ['signal' => 0], 'features_available_at' => now(), 'game_start_at' => now()->addDay(),
        'pregame_safe' => false, 'availability_status' => 'reconstructed_now']);
}

it('preserves actual owning period captures through native instance mutations and legacy compatibility', function () {
    $owned = nativeOwnedPeriodCapture();
    $before = DB::table('mlb_period_feature_snapshots')->where('id', $owned->id)->first();
    foreach (['update', 'saveQuietly', 'updateQuietly', 'incrementQuietly', 'delete', 'deleteQuietly'] as $method) {
        $capture = $owned->fresh();
        $failure = null;
        try {
            match ($method) {
                'update' => $capture->update(['feature_hash' => str_repeat('b', 64)]),
                'saveQuietly' => $capture->forceFill(['features' => ['changed' => true]])->saveQuietly(),
                'updateQuietly' => $capture->updateQuietly(['feature_hash' => str_repeat('b', 64)]),
                'incrementQuietly' => $capture->incrementQuietly('pregame_safe', 1, ['feature_hash' => str_repeat('b', 64)]),
                'delete' => $capture->delete(),
                'deleteQuietly' => $capture->deleteQuietly(),
            };
        } catch (LogicException $exception) {
            $failure = $exception;
        }
        expect(DB::table('mlb_period_feature_snapshots')->where('id', $owned->id)->first())->toEqual($before)
            ->and($failure)->toBeInstanceOf(LogicException::class);
    }
    $legacy = nativeLegacyPeriodCapture();
    expect($legacy->updateQuietly(['features' => ['signal' => 1]]))->toBeTrue();
    $legacy->incrementQuietly('pregame_safe', 0, ['feature_hash' => hash('sha256', 'legacy-changed')]);
    expect($legacy->fresh()->features)->toBe(['signal' => 1])->and($legacy->fresh()->pregame_safe)->toBeFalse();
    $raw = DB::table('mlb_period_feature_snapshots')->where('id', $legacy->id)->first();
    expect(fn () => $legacy->updateQuietly(['forecast_source' => 'canonical']))->toThrow(LogicException::class);
    expect(DB::table('mlb_period_feature_snapshots')->where('id', $legacy->id)->first())->toEqual($raw);
    $legacy->fresh()->deleteQuietly();
    expect(PeriodFeatureSnapshot::find($legacy->id))->toBeNull();
});

it('reads only bounded scalar classification despite large bodies and refuses ambiguous native strings', function () {
    $capture = nativeOwnedPeriodCapture();
    $id = $capture->id;
    // Trusted direct SQL supplies a negative allocation fixture, not protected training authority.
    $body = json_encode(['data' => str_repeat('x', 8388608)], JSON_THROW_ON_ERROR);
    DB::table('mlb_period_feature_snapshots')->where('id', $id)->update(['features' => $body]);
    $physical = (int) DB::table('mlb_period_feature_snapshots')->where('id', $id)->selectRaw('OCTET_LENGTH(features) AS body_bytes')->first()->body_bytes;
    expect($physical)->toBeGreaterThanOrEqual(8388608);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(fn () => $capture->deleteQuietly())->toThrow(LogicException::class);
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'mlb_period_feature_snapshots'));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->toHaveCount(1)->and($queries->implode(' '))->toContain('forecast_source', 'CASE WHEN', 'for update')
        ->not->toContain('*', '`features`');
    expect((int) DB::table('mlb_period_feature_snapshots')->where('id', $id)->selectRaw('OCTET_LENGTH(features) AS body_bytes')->first()->body_bytes)->toBe($physical);
    foreach (['Canonical', 'canonical ', ' legacy', 'legacy ', str_repeat('x', 16)] as $source) {
        DB::table('mlb_period_feature_snapshots')->where('id', $id)->update(['forecast_source' => $source]);
        expect(DB::table('mlb_period_feature_snapshots')->where('id', $id)->value('forecast_source'))->toBe($source);
        expect(fn () => $capture->deleteQuietly())->toThrow(LogicException::class);
        expect(DB::table('mlb_period_feature_snapshots')->where('id', $id)->value('forecast_source'))->toBe($source);
    }
    // VARCHAR(16) is a character bound: eight three-byte characters fit, but exceed the guard's byte bound.
    $multibyte = str_repeat('界', 8);
    DB::table('mlb_period_feature_snapshots')->where('id', $id)->update(['forecast_source' => $multibyte]);
    $sizes = DB::table('mlb_period_feature_snapshots')->where('id', $id)->selectRaw('CHAR_LENGTH(forecast_source) AS chars, OCTET_LENGTH(forecast_source) AS bytes')->first();
    expect((int) $sizes->chars)->toBe(8)->and((int) $sizes->bytes)->toBe(24);
    expect(fn () => $capture->deleteQuietly())->toThrow(LogicException::class, 'scalar budget');
    expect(DB::table('mlb_period_feature_snapshots')->where('id', $id)->value('forecast_source'))->toBe($multibyte);
    DB::table('mlb_period_feature_snapshots')->where('id', $id)->update(['forecast_source' => str_repeat('x', 16)]);
    $failure = null;
    try {
        DB::table('mlb_period_feature_snapshots')->where('id', $id)->update(['forecast_source' => str_repeat('x', 17)]);
    } catch (QueryException $exception) {
        $failure = $exception;
    }
    expect($failure)->toBeInstanceOf(QueryException::class)->and((int) ($failure?->errorInfo[1] ?? 0))->toBe(1406);
    expect(DB::table('mlb_period_feature_snapshots')->where('id', $id)->value('forecast_source'))->toBe(str_repeat('x', 16));
});

it('uses current leaf classification through an old RR view and respects an independent leaf owner', function () {
    $legacy = nativeLegacyPeriodCapture();
    $owner = DB::connection();
    $peer = DB::connection('period_guard_peer');
    $owner->beginTransaction();
    try {
        expect($owner->table('mlb_period_feature_snapshots')->where('id', $legacy->id)->value('forecast_source'))->toBe('legacy');
        // An unknown direct-SQL negative classification grants no canonical/capture authority.
        $peer->table('mlb_period_feature_snapshots')->where('id', $legacy->id)->update(['forecast_source' => 'unknown']);
        expect($owner->table('mlb_period_feature_snapshots')->where('id', $legacy->id)->value('forecast_source'))->toBe('legacy');
        expect(fn () => $legacy->updateQuietly(['feature_hash' => str_repeat('c', 64)]))->toThrow(LogicException::class);
    } finally {
        $owner->rollBack();
    }
    expect($peer->table('mlb_period_feature_snapshots')->where('id', $legacy->id)->value('forecast_source'))->toBe('unknown')
        ->and($peer->table('mlb_period_feature_snapshots')->where('id', $legacy->id)->value('feature_hash'))->toBe($legacy->getRawOriginal('feature_hash'));
    $capture = nativeOwnedPeriodCapture();
    $before = $owner->table('mlb_period_feature_snapshots')->where('id', $capture->id)->first();
    $peer->beginTransaction();
    $failure = null;
    try {
        $peer->table('mlb_period_feature_snapshots')->where('id', $capture->id)->lockForUpdate()->first(['id']);
        try {
            $capture->deleteQuietly();
        } catch (QueryException $exception) {
            $failure = $exception;
        }
        expect($failure)->toBeInstanceOf(QueryException::class)->and((int) ($failure?->errorInfo[1] ?? 0))->toBe(1205)
            ->and(strtolower($failure?->getSql() ?? ''))->toContain('mlb_period_feature_snapshots', 'for update');
    } finally {
        $peer->rollBack();
    }
    expect(fn () => $capture->deleteQuietly())->toThrow(LogicException::class);
    expect($owner->table('mlb_period_feature_snapshots')->where('id', $capture->id)->first())->toEqual($before)
        ->and($owner->transactionLevel())->toBe(0);
});
