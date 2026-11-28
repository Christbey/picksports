<?php

use App\Models\CBB\RosterEntry;
use App\Models\CBB\RosterSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('preserves persisted roster observations through ordinary and quiet instance mutation methods', function (string $model, string $method) {
    $row = $model === 'snapshot'
        ? RosterSnapshot::factory()->create(['season' => 2027, 'content_hash' => str_repeat('a', 64)])
        : RosterEntry::factory()->create(['attributes' => ['position' => 'G', 'sentinel' => 'original']]);
    $table = $row->getTable();
    $before = DB::table($table)->orderBy('id')->get()->map(fn ($item) => (array) $item)->all();
    $field = $model === 'snapshot' ? 'season' : 'attributes';
    $changed = $model === 'snapshot' ? 2028 : ['position' => 'F', 'sentinel' => 'changed'];
    $error = null;
    try {
        match ($method) {
            'save' => $row->forceFill([$field => $changed])->save(),
            'saveQuietly' => $row->forceFill([$field => $changed])->saveQuietly(),
            'updateQuietly' => $row->updateQuietly([$field => $changed]),
            'delete' => $row->delete(),
            'deleteQuietly' => $row->deleteQuietly(),
            'incrementQuietly' => $row->incrementQuietly($model === 'snapshot' ? 'season' : 'id'),
            'decrementQuietly' => $row->decrementQuietly($model === 'snapshot' ? 'season' : 'id'),
        };
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $after = DB::table($table)->orderBy('id')->get()->map(fn ($item) => (array) $item)->all();
    fwrite(STDERR, json_encode(['model' => $model, 'method' => $method,
        'exception' => $error === null ? null : $error::class, 'persisted_unchanged' => $after === $before]).PHP_EOL);
    expect($error)->toBeInstanceOf(LogicException::class)->and($after)->toBe($before);
    Http::assertNothingSent();
})->with(['snapshot', 'entry'])->with(['save', 'saveQuietly', 'updateQuietly', 'delete', 'deleteQuietly', 'incrementQuietly', 'decrementQuietly']);

it('allows new quiet roster observation creation and retains historical known-at selection', function () {
    $older = RosterSnapshot::factory()->create(['season' => 2027, 'observed_at' => now()->subDay(), 'created_at' => now()->subDay()]);
    $next = RosterSnapshot::factory()->make(['team_id' => $older->team_id, 'season' => 2027,
        'observed_at' => now()->addHour(), 'created_at' => now()->addHour()]);
    expect($next->saveQuietly())->toBeTrue();
    $entry = RosterEntry::factory()->make(['roster_snapshot_id' => $older->id]);
    expect($entry->saveQuietly())->toBeTrue()->and($entry->fresh()->roster_snapshot_id)->toBe($older->id)
        ->and(RosterSnapshot::knownAt($older->team_id, 2027, now())->id)->toBe($older->id)
        ->and(RosterSnapshot::count())->toBe(2)->and(RosterEntry::count())->toBe(1);
    Http::assertNothingSent();
});
