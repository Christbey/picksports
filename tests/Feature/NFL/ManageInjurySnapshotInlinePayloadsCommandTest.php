<?php

use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\Team;
use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('provider-data.storage.disk', 'inline-reclamation-test');
    Storage::fake('inline-reclamation-test');
    $team = Team::factory()->create();
    $this->snapshots = collect(range(1, 3))->map(function (int $index) use ($team) {
        $payload = [['athlete' => ['id' => (string) $index], 'status' => 'Out', 'detail' => str_repeat('x', 100)]];

        $snapshot = PlayerInjurySnapshot::create([
            'snapshot_uuid' => (string) Str::uuid(), 'team_id' => $team->id, 'espn_team_id' => $team->espn_id,
            'observed_at' => now()->subDays($index), 'source_updated_at' => now()->subDays($index + 1),
            'entry_count' => 1, 'payload_hash' => hash('sha256', 'original-'.$index), 'raw_payload' => $payload,
        ]);
        $snapshot = $snapshot->fresh();
        $snapshot->update(['payload_archive' => app(CompressedJsonPayloadStorage::class)->archive($snapshot->raw_payload)]);

        return $snapshot->fresh();
    });
    $this->batchOptions = ['--mode' => 'release', '--through-id' => $this->snapshots->last()->id];
});

it('verifies preview without changing payloads or an apply checkpoint', function () {
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads', $this->batchOptions))->toBe(0);
    $summary = json_decode(Artisan::output(), true);
    expect($summary['processed'])->toBe(3)->and($summary['inline_payloads_removed'])->toBe(0)
        ->and($summary['last_successful_id'])->toBe(0)
        ->and(PlayerInjurySnapshot::query()->whereNull('raw_payload')->count())->toBe(0);
});

it('releases and restores exact values without changing provenance entries or objects', function () {
    $originals = $this->snapshots->map(fn ($snapshot) => $snapshot->fresh()->getRawOriginal());
    $payloads = $this->snapshots->map(fn ($snapshot) => $snapshot->raw_payload);
    $objects = Storage::disk('inline-reclamation-test')->allFiles();
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads', $this->batchOptions + ['--apply' => true]))->toBe(0);
    foreach ($this->snapshots as $index => $snapshot) {
        $fresh = $snapshot->fresh();
        $actual = $fresh->getRawOriginal();
        $original = $originals[$index];
        unset($actual['raw_payload'], $original['raw_payload']);
        expect($fresh->raw_payload)->toBeNull()->and($fresh->reportPayload())->toBe($payloads[$index])
            ->and($actual)->toBe($original);
    }

    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads', $this->batchOptions + ['--apply' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['processed'])->toBe(0);

    $restore = array_replace($this->batchOptions, ['--mode' => 'restore', '--apply' => true]);
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads', $restore))->toBe(0);
    foreach ($this->snapshots as $index => $snapshot) {
        expect($snapshot->fresh()->raw_payload)->toBe($payloads[$index]);
        $actual = $snapshot->fresh()->getRawOriginal();
        $original = $originals[$index];
        unset($actual['raw_payload'], $original['raw_payload']);
        expect($actual)->toBe($original);
    }
    expect(Storage::disk('inline-reclamation-test')->allFiles())->toBe($objects);
});

it('stops on corrupt archives without releasing the failed or subsequent payload', function () {
    Storage::disk('inline-reclamation-test')->put($this->snapshots[1]->payload_archive['object_key'], 'corrupt');
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads', $this->batchOptions + ['--apply' => true]))->toBe(1);
    $summary = json_decode(Artisan::output(), true);
    expect($summary['processed'])->toBe(1)->and($summary['last_successful_id'])->toBe($this->snapshots[0]->id)
        ->and($this->snapshots[1]->fresh()->raw_payload)->not->toBeNull()
        ->and($this->snapshots[2]->fresh()->raw_payload)->not->toBeNull();
});

it('refuses valid archives containing different evidence', function () {
    $this->snapshots[0]->update(['payload_archive' => app(CompressedJsonPayloadStorage::class)->archive(['different'])]);
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads', $this->batchOptions + ['--apply' => true]))->toBe(1)
        ->and(PlayerInjurySnapshot::query()->whereNull('raw_payload')->count())->toBe(0);
});

it('does not release unarchived payloads', function () {
    $this->snapshots[0]->update(['payload_archive' => null]);
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads', $this->batchOptions + ['--apply' => true]))->toBe(0)
        ->and($this->snapshots[0]->fresh()->raw_payload)->not->toBeNull();
});

it('bounds writes by bytes and resumes from the last committed record', function () {
    $bytes = (int) PlayerInjurySnapshot::query()->whereKey($this->snapshots[0]->id)->selectRaw('LENGTH(raw_payload) AS bytes')->value('bytes');
    $options = $this->batchOptions + ['--apply' => true, '--max-bytes' => $bytes];
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads', $options))->toBe(0);
    $summary = json_decode(Artisan::output(), true);
    expect($summary['processed'])->toBe(1)->and($summary['byte_budget_reached'])->toBeTrue()
        ->and($summary['last_successful_id'])->toBe($this->snapshots[0]->id);
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads',
        $options + ['--after-id' => $summary['last_successful_id']]))->toBe(0)
        ->and($this->snapshots[1]->fresh()->raw_payload)->toBeNull()
        ->and($this->snapshots[2]->fresh()->raw_payload)->not->toBeNull();
});

it('refuses a first record larger than the byte budget', function () {
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads',
        $this->batchOptions + ['--apply' => true, '--max-bytes' => 1]))->toBe(1)
        ->and(PlayerInjurySnapshot::query()->whereNull('raw_payload')->count())->toBe(0);
});

it('respects both record count and the explicit upper boundary', function () {
    $options = array_replace($this->batchOptions, ['--through-id' => $this->snapshots[1]->id, '--limit' => 1, '--apply' => true]);
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads', $options))->toBe(0)
        ->and($this->snapshots[0]->fresh()->raw_payload)->toBeNull()
        ->and($this->snapshots[1]->fresh()->raw_payload)->not->toBeNull()
        ->and($this->snapshots[2]->fresh()->raw_payload)->not->toBeNull();
});

it('rejects invalid modes boundaries and budgets', function (array $overrides) {
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads', array_replace($this->batchOptions, $overrides)))->toBe(2);
})->with([
    [['--mode' => '']], [['--mode' => 'delete']], [['--through-id' => 0]], [['--limit' => 101]],
    [['--max-bytes' => 0]], [['--max-bytes' => 33554433]], [['--after-id' => 999999]],
]);

it('retains concurrent source changes rather than releasing them', function () {
    $real = new CompressedJsonPayloadStorage;
    $archives = Mockery::mock(CompressedJsonPayloadStorage::class);
    $archives->shouldReceive('read')->once()->andReturnUsing(function (array $manifest) use ($real): array {
        $payload = $real->read($manifest);
        $this->snapshots[0]->update(['raw_payload' => [['status' => 'Questionable']]]);

        return $payload;
    });
    app()->instance(CompressedJsonPayloadStorage::class, $archives);
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads',
        $this->batchOptions + ['--apply' => true, '--limit' => 1]))->toBe(1)
        ->and($this->snapshots[0]->fresh()->raw_payload)->toBe([['status' => 'Questionable']]);
});

it('does not restore unavailable archives as empty data', function () {
    $this->snapshots[0]->update(['raw_payload' => null]);
    Storage::disk('inline-reclamation-test')->delete($this->snapshots[0]->payload_archive['object_key']);
    expect(Artisan::call('nfl:manage-injury-snapshot-inline-payloads',
        array_replace($this->batchOptions, ['--mode' => 'restore', '--apply' => true])))->toBe(1)
        ->and($this->snapshots[0]->fresh()->raw_payload)->toBeNull();
});
