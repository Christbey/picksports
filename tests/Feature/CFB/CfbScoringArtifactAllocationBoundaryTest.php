<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\CFB\Scoring\CfbScoringArtifact;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CfbArtifactCountingStream;

it('bounds CFB artifact graphs while preserving payloads at admitted limits', function (string $kind, bool $accepted = false) {
    Storage::fake('local');
    $path = Storage::disk('local')->path('allocation-boundary.json');
    $handle = fopen($path, 'wb');
    fwrite($handle, '{"evidence_mode":"recorded_time","max_source_available_at":"2024-06-11T00:00:00Z","padding":');
    if (in_array($kind, ['at_byte_limit', 'bytes'], true)) {
        fwrite($handle, '"');
        $targetBytes = $kind === 'bytes' ? 16777217 : 16777216;
        $remaining = $targetBytes - ftell($handle) - 2;
        while ($remaining > 0) {
            $bytes = min($remaining, 65536);
            fwrite($handle, str_repeat('x', $bytes));
            $remaining -= $bytes;
        }
        fwrite($handle, '"');
    } elseif (in_array($kind, ['tokens', 'at_token_limit'], true)) {
        fwrite($handle, '[');
        $remaining = $kind === 'tokens' ? 499993 : 499992;
        while ($remaining > 0) {
            $count = min($remaining, 1000);
            fwrite($handle, str_repeat('0,', $count));
            $remaining -= $count;
        }
        fwrite($handle, $kind === 'tokens' ? '0]' : '{}]');
    } else {
        $levels = $kind === 'near_depth_limit' ? 62 : 70;
        fwrite($handle, str_repeat('[', $levels).'0'.str_repeat(']', $levels));
    }
    fwrite($handle, '}');
    fclose($handle);
    $artifact = cfbAllocationArtifactFixture('local', hash_file('sha256', $path));

    if ($accepted) {
        $loaded = app(CfbScoringArtifact::class)->load($artifact->id, now());
        expect($loaded['payload']['evidence_mode'])->toBe('recorded_time');
        if ($kind === 'at_byte_limit') {
            expect(filesize($path))->toBe(16777216)
                ->and(strlen($loaded['payload']['padding']))->toBeGreaterThan(16777000);
        } elseif ($kind === 'at_token_limit') {
            expect(count($loaded['payload']['padding']))->toBe(499993)
                ->and($loaded['payload']['padding'][499992])->toBe([]);
        } else {
            $value = $loaded['payload']['padding'];
            for ($i = 0; $i < 62; $i++) {
                $value = $value[0];
            }
            expect($value)->toBe(0);
        }

        return;
    }

    expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, now()))
        ->toThrow(RuntimeException::class, 'budget exceeded before decode');
})->with([['bytes'], ['tokens'], ['depth'], ['at_byte_limit', true], ['at_token_limit', true], ['near_depth_limit', true]]);

it('caps logical stream buffering and closes an endless artifact source', function () {
    $protocol = 'cfb-allocation-counter';
    stream_wrapper_register($protocol, CfbArtifactCountingStream::class);
    try {
        $stream = fopen($protocol.'://artifact', 'rb');
        $adapter = Mockery::mock(FilesystemAdapter::class);
        $adapter->shouldReceive('readStream')->once()->with('allocation-boundary.json')->andReturn($stream);
        Storage::shouldReceive('disk')->with('counter')->once()->andReturn($adapter);
        $artifact = cfbAllocationArtifactFixture('counter', str_repeat('a', 64));

        expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, now()))
            ->toThrow(RuntimeException::class, 'byte budget exceeded before decode');
        expect(CfbArtifactCountingStream::$closed)->toBeTrue()
            ->and(is_resource($stream))->toBeFalse()
            ->and(CfbArtifactCountingStream::$bytesRead)->toBeLessThanOrEqual(16777216 + 8192);
    } finally {
        stream_wrapper_unregister($protocol);
    }
});

it('closes failed streams and retains integrity before graph admission', function (string $failure) {
    $raw = $failure === 'malformed' ? '{"evidence_mode":}' : str_repeat('[', 70).'0'.str_repeat(']', 70);
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $raw);
    rewind($stream);
    $adapter = Mockery::mock(FilesystemAdapter::class);
    $adapter->shouldReceive('readStream')->once()->with('allocation-boundary.json')->andReturn($stream);
    Storage::shouldReceive('disk')->with('counter')->once()->andReturn($adapter);
    $artifact = cfbAllocationArtifactFixture('counter', $failure === 'integrity' ? str_repeat('a', 64) : hash('sha256', $raw));

    if ($failure === 'integrity') {
        expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, now()))->toThrow(DomainException::class, 'integrity');
    } elseif ($failure === 'malformed') {
        expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, now()))->toThrow(JsonException::class);
    } else {
        expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, now()))->toThrow(RuntimeException::class, 'depth budget');
    }
    expect(is_resource($stream))->toBeFalse();
})->with(['integrity', 'malformed', 'depth']);

function cfbAllocationArtifactFixture(string $disk, string $hash): ModelArtifact
{
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'cfb', 'run_type' => 'training', 'blend_version' => 'cfb-possession-v1', 'model_version' => 'test', 'feature_version' => 'test', 'config_hash' => hash('sha256', 'test'), 'code_version' => 'test', 'status' => 'completed', 'started_at' => now()]);

    return ModelArtifact::create(['training_run_id' => $run->id, 'sport' => 'cfb', 'market_type' => 'multi_market', 'model_type' => 'cfb_possession', 'model_version' => 'test', 'feature_version' => 'test', 'status' => 'challenger', 'artifact_disk' => $disk, 'artifact_object_key' => 'allocation-boundary.json', 'artifact_path' => 'allocation-boundary.json', 'dataset_hash' => hash('sha256', 'fixture'), 'artifact_hash' => $hash]);
}
