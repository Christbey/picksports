<?php

use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
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

function mockPayloadPrefetchDisk(MockHandler $handler): void
{
    $client = new S3Client([
        'version' => 'latest', 'region' => 'us-east-1',
        'credentials' => ['key' => 'test', 'secret' => 'test'], 'handler' => $handler,
    ]);
    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldReceive('getClient')->andReturn($client);
    $disk->shouldReceive('getConfig')->andReturn(['bucket' => 'test-bucket']);
    $disk->shouldReceive('path')->andReturnUsing(fn (string $key) => 'prefix/'.$key);
    Storage::set('payload-test', $disk);
}

it('prefetches distinct archive objects with bounded ranges and verifies both checksums', function () {
    $first = $this->archives->archive(['value' => 1]);
    $second = $this->archives->archive(['value' => 2]);
    $commands = [];
    $handler = new MockHandler;
    foreach ([$first, $second] as $manifest) {
        $body = Storage::disk('payload-test')->get($manifest['object_key']);
        $handler->append(function ($command) use (&$commands, $body) {
            $commands[] = [$command['Key'], $command['Range']];

            return new Result(['Body' => Utils::streamFor($body)]);
        });
    }
    mockPayloadPrefetchDisk($handler);

    expect($this->archives->prefetch([$first, $second, $first]))->toBe([
        $first['object_key'] => ['value' => 1],
        $second['object_key'] => ['value' => 2],
    ])->and($commands)->toBe([
        ['prefix/'.$first['object_key'], 'bytes=0-'.$first['compressed_bytes']],
        ['prefix/'.$second['object_key'], 'bytes=0-'.$second['compressed_bytes']],
    ]);
});

it('falls back to lazy reads for local disks and batches outside the prefetch budget', function () {
    $manifest = $this->archives->archive(['value' => 1]);
    expect($this->archives->prefetch([$manifest]))->toBe([]);
    mockPayloadPrefetchDisk(new MockHandler);

    expect($this->archives->prefetch(array_fill(0, 5, $manifest)))->toBe([]);
    $manifest['json_bytes'] = 4 * 1024 * 1024;
    expect($this->archives->prefetch([$manifest]))->toBe([]);
});

it('rejects corrupted data during prefetch', function () {
    $manifest = $this->archives->archive(['value' => 1]);
    mockPayloadPrefetchDisk(new MockHandler([new Result([
        'Body' => Utils::streamFor(str_repeat('x', $manifest['compressed_bytes'])),
    ])]));

    expect(fn () => $this->archives->prefetch([$manifest]))->toThrow(RuntimeException::class, 'checksum');
});
