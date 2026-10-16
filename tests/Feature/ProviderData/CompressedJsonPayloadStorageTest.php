<?php

use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('provider-data.storage.disk', 'payload-test');
    config()->set('provider-data.storage.prefix', 'providers');
    Storage::fake('payload-test');
    $this->archives = new CompressedJsonPayloadStorage;
});

it('stores private compressed JSON and verifies identical values on read back', function () {
    $payload = ['zero' => 0.0, 'missing' => null, 'ready' => false, 'text' => 'é / test', 'list' => [3, 1, 2]];
    $manifest = $this->archives->archive($payload);

    expect($this->archives->read($manifest))->toBe($payload)
        ->and(Storage::disk('payload-test')->getVisibility($manifest['object_key']))->toBe('private');
    expect($this->archives->archive($payload))->toBe($manifest)
        ->and(Storage::disk('payload-test')->allFiles())->toHaveCount(1);
});

it('retains empty inline payloads and supports archived-only payloads', function () {
    $manifest = $this->archives->archive(['value' => 1]);

    expect($this->archives->resolve([], $manifest))->toBe([])
        ->and($this->archives->resolve(null, $manifest))->toBe(['value' => 1]);
    expect(fn () => $this->archives->resolve(null, null))->toThrow(RuntimeException::class);
});

it('reads existing manifests after the configured write prefix changes', function () {
    $manifest = $this->archives->archive(['original' => true]);
    config()->set('provider-data.storage.prefix', 'providers/new-location');

    expect($this->archives->read($manifest))->toBe(['original' => true])
        ->and($this->archives->archive(['original' => true])['object_key'])->toStartWith('providers/new-location/');
});

it('rejects altered archive bytes instead of silently returning missing data', function () {
    $manifest = $this->archives->archive(['value' => 1]);
    Storage::disk('payload-test')->put($manifest['object_key'], 'corrupt');

    expect(fn () => $this->archives->read($manifest))->toThrow(RuntimeException::class)
        ->and(fn () => $this->archives->archive(['value' => 1]))->toThrow(RuntimeException::class);
});

it('rejects missing archive objects', function () {
    $manifest = $this->archives->archive(['value' => 1]);
    Storage::disk('payload-test')->delete($manifest['object_key']);

    expect(fn () => $this->archives->read($manifest))->toThrow(RuntimeException::class);
});

it('rejects unsupported or unsafe manifests before reading storage', function (string $field, mixed $value) {
    $manifest = $this->archives->archive(['value' => 1]);
    $manifest[$field] = $value;

    expect(fn () => $this->archives->read($manifest))->toThrow(RuntimeException::class);
})->with([
    ['version', 2], ['codec', 'unknown'], ['disk', 's3'],
    ['object_key', '../secret'], ['prefix', '../secret'], ['json_sha256', 'invalid'], ['compressed_bytes', -1],
]);

it('rejects oversized JSON before writing any archive', function () {
    config()->set('provider-data.payloads.max_json_bytes', 128);

    expect(fn () => $this->archives->archive(['data' => str_repeat('x', 256)]))->toThrow(RuntimeException::class)
        ->and(Storage::disk('payload-test')->allFiles())->toBe([]);
});

it('rejects oversized compressed payloads before writing storage', function () {
    config()->set('provider-data.payloads.max_compressed_bytes', 1);

    expect(fn () => $this->archives->archive(['value' => 1]))->toThrow(RuntimeException::class)
        ->and(Storage::disk('payload-test')->allFiles())->toBe([]);
});

it('fails when object storage refuses a write', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->andReturn(false);
    $disk->shouldReceive('put')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('payload-test')->once()->andReturn($disk);

    expect(fn () => $this->archives->archive(['value' => 1]))->toThrow(RuntimeException::class);
});

it('bounds decompression even when manifest claims a smaller JSON size', function () {
    $manifest = $this->archives->archive(['data' => str_repeat('x', 10000)]);
    config()->set('provider-data.payloads.max_json_bytes', 128);
    $manifest['json_bytes'] = 100;

    expect(fn () => $this->archives->read($manifest))->toThrow(RuntimeException::class);
});

it('rejects truncated gzip with an otherwise matching compressed checksum', function () {
    $manifest = $this->archives->archive(['value' => 'original']);
    $compressed = substr(Storage::disk('payload-test')->get($manifest['object_key']), 0, 12);
    $manifest['compressed_sha256'] = hash('sha256', $compressed);
    $manifest['compressed_bytes'] = strlen($compressed);
    $manifest['object_key'] = 'providers/payloads/v1/'.$manifest['json_sha256'].'/'.$manifest['compressed_sha256'].'.json.gz';
    Storage::disk('payload-test')->put($manifest['object_key'], $compressed);

    expect(fn () => $this->archives->read($manifest))->toThrow(RuntimeException::class);
});
