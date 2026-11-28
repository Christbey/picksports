<?php

use App\Models\CanonicalPrediction;
use App\Models\PredictionPublicationEffect;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Publication effect current-state tests require disposable MySQL.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    Http::preventStrayRequests();
    config(['database.connections.publication_effect_peer' => config('database.connections.mysql')]);
});

afterEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('publication_effect_peer');
});

function nativePublicationMutationFixture(): PredictionPublicationEffect
{
    return PredictionPublicationEffect::create(['canonical_prediction_id' => CanonicalPrediction::factory()->create()->id,
        'effect' => 'cache', 'payload' => ['z' => 1, 'a' => ['zero' => 0]], 'state' => 'pending', 'attempts' => 3]);
}

it('refuses a terminal receipt committed behind an inherited consistent snapshot', function () {
    $effect = nativePublicationMutationFixture();
    DB::beginTransaction();
    $stale = PredictionPublicationEffect::findOrFail($effect->id);
    DB::connection('publication_effect_peer')->table('prediction_publication_effects')->where('id', $effect->id)->update(['state' => 'completed']);
    $stale->attempts = 4;
    expect(fn () => $stale->saveQuietly())->toThrow(LogicException::class, 'Terminal publication effect');
    DB::rollBack();
    expect($effect->fresh()->state)->toBe('completed')->and($effect->fresh()->attempts)->toBe(3);
});

it('refuses admission payload drift committed behind a consistent worker snapshot', function () {
    $effect = nativePublicationMutationFixture();
    DB::beginTransaction();
    $stale = PredictionPublicationEffect::findOrFail($effect->id);
    DB::connection('publication_effect_peer')->table('prediction_publication_effects')->where('id', $effect->id)->update(['payload' => '{"z":2,"a":{"zero":0}}']);
    $stale->reason_code = 'stale-worker';
    expect(fn () => $stale->saveQuietly())->toThrow(LogicException::class, 'admission payload changed');
    DB::rollBack();
    expect($effect->fresh()->payload)->toBe(['a' => ['zero' => 0], 'z' => 2])
        ->and($effect->fresh()->reason_code)->toBeNull();
});

it('preserves mutable pending updates with SQL JSON object order equivalence', function () {
    $effect = nativePublicationMutationFixture();
    $effect->reason_code = 'legitimate-update';
    $effect->saveQuietly();
    $effect->incrementQuietly('attempts');
    expect($effect->fresh()->reason_code)->toBe('legitimate-update')
        ->and($effect->fresh()->attempts)->toBe(4)
        ->and($effect->fresh()->payload)->toBe(['a' => ['zero' => 0], 'z' => 1]);
});
