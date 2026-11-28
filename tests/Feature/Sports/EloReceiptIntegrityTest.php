<?php

use App\Casts\UtcEloDateTime;
use App\Models\SportEloApplication;
use App\Models\SportEloApplicationEffect;
use App\Models\SportEloSeasonInitialization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function eloImmutableReceiptFixture(): array
{
    $initialization = SportEloSeasonInitialization::create(['sport' => 'nfl', 'entity_type' => 'team', 'entity_id' => 1,
        'season' => 2026, 'calculation_version' => 'synthetic-v1', 'configuration_hash' => str_repeat('a', 64),
        'kind' => 'baseline_admission', 'baseline_kind' => 'declared_default', 'rating_before' => '1500.0000', 'rating_after' => '1500.0000',
        'source_fingerprint' => str_repeat('b', 64), 'receipt_hash' => str_repeat('c', 64), 'evidence' => ['initial' => true],
        'observed_at' => now(), 'recorded_at' => now()]);
    $application = SportEloApplication::create(['sport' => 'nfl', 'game_id' => 1, 'season' => 2026,
        'calculation_version' => 'synthetic-v1', 'configuration_hash' => str_repeat('a', 64),
        'source_fingerprint' => str_repeat('b', 64), 'receipt_hash' => str_repeat('d', 64), 'source_snapshot' => ['initial' => true],
        'played_at' => now(), 'recorded_at' => now()]);
    $effect = SportEloApplicationEffect::create(['application_id' => $application->id, 'initialization_id' => $initialization->id,
        'entity_type' => 'team', 'entity_id' => 1, 'rating_before' => '1500.0000', 'rating_after' => '1501.0000',
        'rating_delta' => '1.0000', 'evidence' => ['initial' => true], 'recorded_at' => now()]);

    return [$initialization->fresh(), $application->fresh(), $effect->fresh()];
}

it('protects every Elo receipt against ordinary quiet numeric and deletion mutations', function (int $index, string $method) {
    $receipt = eloImmutableReceiptFixture()[$index];
    $before = $receipt->getAttributes();
    if (in_array($method, ['save', 'saveQuietly'], true)) {
        $receipt->recorded_at = now()->addDay();
    }
    expect(fn () => in_array($method, ['increment', 'decrement', 'incrementQuietly', 'decrementQuietly'], true)
        ? $receipt->{$method}($index === 1 ? 'game_id' : 'entity_id') : $receipt->{$method}())->toThrow(LogicException::class);
    expect($receipt->fresh()->getAttributes())->toBe($before);
})->with([0, 1, 2])->with(['save', 'saveQuietly', 'delete', 'deleteQuietly', 'increment', 'decrement', 'incrementQuietly', 'decrementQuietly']);

it('does not let a stale quiet model replace a persisted receipt', function (int $index) {
    $receipt = eloImmutableReceiptFixture()[$index];
    $stale = $receipt->fresh();
    $field = $index === 1 ? 'source_snapshot' : 'evidence';
    // Raw SQL is outside model immutability; use it to model persisted drift, not to claim it is guarded.
    DB::table($receipt->getTable())->where('id', $receipt->id)->update([$field => '{"outside_model":true}']);
    $before = $receipt->fresh()->getAttributes();
    $stale->{$field} = ['late' => true];
    expect(fn () => $stale->saveQuietly())->toThrow(LogicException::class);
    expect($receipt->fresh()->getAttributes())->toBe($before);
})->with([0, 1, 2]);

it('retains restrictive parent receipt foreign keys even outside model APIs', function (string $table) {
    [$initialization, $application] = eloImmutableReceiptFixture();
    $id = $table === 'sport_elo_applications' ? $application->id : $initialization->id;
    expect(fn () => DB::table($table)->where('id', $id)->delete())->toThrow(QueryException::class);
})->with(['sport_elo_applications', 'sport_elo_season_initializations']);

it('roundtrips offset chronology through explicit UTC text and serialization', function () {
    config(['app.timezone' => 'America/Chicago']);
    [, $application] = eloImmutableReceiptFixture();
    $attributes = $application->getAttributes();
    unset($attributes['id']);
    $attributes['game_id'] = 2;
    $attributes['source_snapshot'] = ['initial' => true];
    $attributes['played_at'] = CarbonImmutable::parse('1965-12-31T18:00:00.123456-06:00');
    $copy = SportEloApplication::create($attributes)->fresh();
    expect($copy->getRawOriginal('played_at'))->toBe('1966-01-01 00:00:00.123456')
        ->and($copy->played_at->format('Y-m-d H:i:s.uP'))->toBe('1966-01-01 00:00:00.123456+00:00')
        ->and($copy->toArray()['played_at'])->toBe('1966-01-01T00:00:00.123456Z');
});

it('does not invent midnight or normalize invalid chronology strings', function (string $value) {
    $application = new SportEloApplication;
    expect(fn () => $application->setAttribute('played_at', $value))->toThrow(InvalidArgumentException::class);
})->with(['1965-10-01', '2026-02-31 12:00:00', '2026-10-01 24:00:00', 'now']);

it('requires real source time and a valid calendar date before forming chronology', function (string $date, string $time) {
    expect(fn () => (new UtcEloDateTime)->observed($date, $time))->toThrow(InvalidArgumentException::class);
})->with([['2026-02-31', '12:00:00'], ['1965-10-01', ''], ['1965-10-01', '24:00:00']]);
