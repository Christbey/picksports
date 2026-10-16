<?php

use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\Team;
use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('provider-data.storage.disk', 'injury-archive-test');
    Storage::fake('injury-archive-test');
});

it('reads archived injury reports without altering snapshot identity or provenance', function () {
    $team = Team::factory()->create();
    $payload = [['athlete' => ['id' => '123'], 'status' => 'Out']];
    $snapshot = PlayerInjurySnapshot::create([
        'snapshot_uuid' => (string) Str::uuid(), 'team_id' => $team->id,
        'espn_team_id' => $team->espn_id, 'observed_at' => now(),
        'source_updated_at' => now()->subHour(), 'entry_count' => 1,
        'payload_hash' => hash('sha256', 'original-provider-encoding'),
        'payload_archive' => app(CompressedJsonPayloadStorage::class)->archive($payload),
    ])->fresh();
    $attributes = $snapshot->getRawOriginal();

    expect($snapshot->reportPayload())->toBe($payload)
        ->and($snapshot->raw_payload)->toBeNull()
        ->and($snapshot->getRawOriginal())->toBe($attributes)
        ->and($snapshot->isDirty())->toBeFalse();

    $migration = require database_path('migrations/2026_10_15_203917_add_payload_archive_to_nfl_player_injury_snapshots_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Restore inline injury payloads');
});

it('keeps inline and legacy missing reports authoritative', function () {
    $snapshot = new PlayerInjurySnapshot(['raw_payload' => [], 'payload_archive' => ['version' => 99]]);
    expect($snapshot->reportPayload())->toBe([]);
    $snapshot->raw_payload = null;
    $snapshot->payload_archive = null;
    expect($snapshot->reportPayload())->toBeNull();
});

it('does not reinterpret corrupt archived reports as an empty healthy report', function () {
    $manifest = app(CompressedJsonPayloadStorage::class)->archive([]);
    Storage::disk('injury-archive-test')->put($manifest['object_key'], 'corrupt');
    $snapshot = new PlayerInjurySnapshot(['payload_archive' => $manifest]);

    expect(fn () => $snapshot->reportPayload())->toThrow(RuntimeException::class);
});
