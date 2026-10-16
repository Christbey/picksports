<?php

use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\Team;
use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('provider-data.storage.disk', 'batch-archive-test');
    Storage::fake('batch-archive-test');
    $team = Team::factory()->create();
    $this->snapshots = collect(range(1, 3))->map(fn (int $index) => PlayerInjurySnapshot::create([
        'snapshot_uuid' => (string) Str::uuid(), 'team_id' => $team->id,
        'espn_team_id' => $team->espn_id, 'observed_at' => now()->subDays($index),
        'source_updated_at' => now()->subDays($index + 1), 'entry_count' => 1,
        'payload_hash' => hash('sha256', 'original-'.$index),
        'raw_payload' => [['athlete' => ['id' => (string) $index], 'status' => 'Out']],
    ]));
    $this->through = $this->snapshots->last()->id;
});

it('previews bounded batches without writing objects manifests or apply checkpoints', function () {
    expect(Artisan::call('nfl:archive-injury-snapshot-payloads', ['--through-id' => $this->through, '--limit' => 1]))->toBe(0);
    $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($summary['mode'])->toBe('dry-run')
        ->and($summary['selected'])->toBe(1)
        ->and($summary['last_successful_id'])->toBe(0)
        ->and($summary['last_previewed_id'])->toBe($this->snapshots->first()->id)
        ->and(PlayerInjurySnapshot::query()->whereNotNull('payload_archive')->count())->toBe(0)
        ->and(Storage::disk('batch-archive-test')->allFiles())->toBe([]);
});

it('persists verified manifests retains inline data and resumes without changing provenance', function () {
    $originals = $this->snapshots->map(fn ($snapshot) => $snapshot->fresh()->getRawOriginal());
    expect(Artisan::call('nfl:archive-injury-snapshot-payloads', [
        '--through-id' => $this->through, '--limit' => 1, '--apply' => true,
    ]))->toBe(0);
    $first = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($first['manifests_added'])->toBe(1)->and($first['inline_payloads_removed'])->toBe(0);

    expect(Artisan::call('nfl:archive-injury-snapshot-payloads', [
        '--through-id' => $this->through, '--after-id' => $first['last_successful_id'], '--apply' => true,
    ]))->toBe(0);
    expect(PlayerInjurySnapshot::query()->whereNotNull('payload_archive')->count())->toBe(3);
    foreach ($this->snapshots as $index => $snapshot) {
        $fresh = $snapshot->fresh();
        $attributes = $fresh->getRawOriginal();
        unset($attributes['payload_archive']);
        $original = $originals[$index];
        unset($original['payload_archive']);
        expect($attributes)->toBe($original)
            ->and(app(CompressedJsonPayloadStorage::class)->read($fresh->payload_archive))->toBe($fresh->raw_payload);
    }

    $objects = Storage::disk('batch-archive-test')->allFiles();
    expect(Artisan::call('nfl:archive-injury-snapshot-payloads', ['--through-id' => $this->through, '--apply' => true]))->toBe(0);
    $repeat = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($repeat['manifests_added'])->toBe(0)->and($repeat['existing_manifests_verified'])->toBe(3)
        ->and(Storage::disk('batch-archive-test')->allFiles())->toBe($objects);
});

it('stops at a failed record and checkpoints only earlier verified records', function () {
    $this->snapshots[1]->update(['payload_archive' => ['version' => 99]]);
    expect(Artisan::call('nfl:archive-injury-snapshot-payloads', ['--through-id' => $this->through, '--apply' => true]))->toBe(1);
    $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($summary['processed'])->toBe(1)
        ->and($summary['last_successful_id'])->toBe($this->snapshots[0]->id)
        ->and($this->snapshots[0]->fresh()->payload_archive)->not->toBeNull()
        ->and($this->snapshots[2]->fresh()->payload_archive)->toBeNull()
        ->and(PlayerInjurySnapshot::query()->whereNull('raw_payload')->count())->toBe(0);
});

it('rejects invalid or conflicting batch options', function (array $options) {
    expect(Artisan::call('nfl:archive-injury-snapshot-payloads', $options))->toBe(2)
        ->and(Storage::disk('batch-archive-test')->allFiles())->toBe([]);
})->with([
    [[]], [['--through-id' => 1, '--limit' => 101]], [['--through-id' => 1, '--after-id' => 2]],
    [['--through-id' => 1, '--dry-run' => true, '--apply' => true]],
]);

it('refuses oversized records before uploading', function () {
    config()->set('provider-data.payloads.max_json_bytes', 1);
    expect(Artisan::call('nfl:archive-injury-snapshot-payloads', ['--through-id' => $this->through, '--apply' => true]))->toBe(1)
        ->and(Storage::disk('batch-archive-test')->allFiles())->toBe([]);
});

it('does not attach an archive if the source changes while it is uploaded', function () {
    $real = new CompressedJsonPayloadStorage;
    $archives = Mockery::mock(CompressedJsonPayloadStorage::class)->makePartial();
    $archives->shouldReceive('archive')->once()->andReturnUsing(function (array $payload) use ($real): array {
        $manifest = $real->archive($payload);
        $this->snapshots[0]->update(['raw_payload' => [['status' => 'Questionable']]]);

        return $manifest;
    });
    app()->instance(CompressedJsonPayloadStorage::class, $archives);

    expect(Artisan::call('nfl:archive-injury-snapshot-payloads', [
        '--through-id' => $this->through, '--limit' => 1, '--apply' => true,
    ]))->toBe(1);
    $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($summary['last_successful_id'])->toBe(0)
        ->and($this->snapshots[0]->fresh()->payload_archive)->toBeNull()
        ->and($this->snapshots[0]->fresh()->raw_payload)->toBe([['status' => 'Questionable']]);
});

it('does not process snapshots beyond the explicit ID boundary', function () {
    expect(Artisan::call('nfl:archive-injury-snapshot-payloads', [
        '--through-id' => $this->snapshots[1]->id, '--apply' => true,
    ]))->toBe(0)
        ->and($this->snapshots[2]->fresh()->payload_archive)->toBeNull()
        ->and(PlayerInjurySnapshot::query()->whereNotNull('payload_archive')->count())->toBe(2);
});

it('refuses to reuse an archive that differs from retained inline evidence', function () {
    $original = $this->snapshots[0]->fresh()->raw_payload;
    $other = app(CompressedJsonPayloadStorage::class)->archive([['status' => 'different']]);
    $this->snapshots[0]->update(['payload_archive' => $other]);

    expect(Artisan::call('nfl:archive-injury-snapshot-payloads', [
        '--through-id' => $this->through, '--limit' => 1, '--apply' => true,
    ]))->toBe(1)
        ->and($this->snapshots[0]->fresh()->raw_payload)->toBe($original);
});
