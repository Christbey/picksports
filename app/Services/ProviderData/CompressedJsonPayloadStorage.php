<?php

namespace App\Services\ProviderData;

use GuzzleHttp\Promise\Utils as Promises;
use GuzzleHttp\Psr7\Utils as Streams;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CompressedJsonPayloadStorage
{
    public const PREFETCH_BATCH_SIZE = 4;

    private const PREFETCH_BYTE_LIMIT = 4 * 1024 * 1024;

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{version:int,codec:string,disk:string,prefix:string,object_key:string,json_sha256:string,compressed_sha256:string,json_bytes:int,compressed_bytes:int}
     */
    public function archive(array $payload): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > $this->jsonLimit()) {
            throw new RuntimeException('Payload exceeds the JSON archive size limit.');
        }

        $compressed = gzencode($json, 6);
        if ($compressed === false || strlen($compressed) > $this->compressedLimit()) {
            throw new RuntimeException('Payload exceeds the compressed archive size limit.');
        }

        $manifest = [
            'version' => 1,
            'codec' => 'gzip-json',
            'disk' => $this->disk(),
            'prefix' => trim((string) config('provider-data.storage.prefix', 'providers'), '/'),
            'object_key' => '',
            'json_sha256' => hash('sha256', $json),
            'compressed_sha256' => hash('sha256', $compressed),
            'json_bytes' => strlen($json),
            'compressed_bytes' => strlen($compressed),
        ];
        $manifest['object_key'] = $this->objectKey($manifest);
        $this->validateManifest($manifest);
        $disk = Storage::disk($this->disk());

        if (! $disk->exists($manifest['object_key']) && ! $disk->put($manifest['object_key'], $compressed, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to write compressed payload archive.');
        }

        if ($this->read($manifest) !== $payload) {
            throw new RuntimeException('Archived payload failed read-back verification.');
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<array-key, mixed>
     */
    public function read(array $manifest): array
    {
        $this->validateManifest($manifest);
        $stream = Storage::disk($this->disk())->readStream($manifest['object_key']);
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to read compressed payload archive.');
        }

        try {
            $compressed = stream_get_contents($stream, $manifest['compressed_bytes'] + 1);
        } finally {
            fclose($stream);
        }

        return $this->decode($manifest, $compressed);
    }

    /** @return array<string, array> Verified payloads keyed by object key. */
    public function prefetch(array $manifests): array
    {
        if ($manifests === [] || count($manifests) > self::PREFETCH_BATCH_SIZE) {
            return [];
        }

        $disk = Storage::disk($this->disk());
        if (! $disk instanceof AwsS3V3Adapter) {
            return [];
        }

        $objects = [];
        $bytes = 0;
        foreach ($manifests as $manifest) {
            $this->validateManifest($manifest);
            $key = $manifest['object_key'];
            if (isset($objects[$key]) && $objects[$key] !== $manifest) {
                return [];
            }
            if (! isset($objects[$key])) {
                $objects[$key] = $manifest;
                $bytes += $manifest['json_bytes'] + $manifest['compressed_bytes'];
            }
        }
        if ($bytes > self::PREFETCH_BYTE_LIMIT) {
            return [];
        }

        $requests = [];
        $client = $disk->getClient();
        $bucket = $disk->getConfig()['bucket'];
        foreach ($objects as $key => $manifest) {
            $requests[$key] = $client->getObjectAsync([
                'Bucket' => $bucket,
                'Key' => $disk->path($key),
                'Range' => 'bytes=0-'.$manifest['compressed_bytes'],
            ]);
        }

        $payloads = [];
        $responses = Promises::unwrap($requests);
        try {
            foreach ($responses as $key => $response) {
                $manifest = $objects[$key];
                $compressed = Streams::copyToString($response['Body'], $manifest['compressed_bytes'] + 1);
                $payloads[$key] = $this->decode($manifest, $compressed);
            }
        } finally {
            foreach ($responses as $response) {
                $response['Body']->close();
            }
        }

        return $payloads;
    }

    private function decode(array $manifest, string|false $compressed): array
    {
        if ($compressed === false || strlen($compressed) !== $manifest['compressed_bytes']
            || ! hash_equals($manifest['compressed_sha256'], hash('sha256', $compressed))) {
            throw new RuntimeException('Compressed payload failed size or checksum verification.');
        }

        $json = @gzdecode($compressed, $manifest['json_bytes'] + 1);
        if ($json === false || strlen($json) !== $manifest['json_bytes']
            || ! hash_equals($manifest['json_sha256'], hash('sha256', $json))) {
            throw new RuntimeException('Archived JSON failed decompression, size or checksum verification.');
        }

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('Archived JSON must contain an array or object.');
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>|null  $inline
     * @param  array<string, mixed>|null  $manifest
     * @return array<array-key, mixed>
     */
    public function resolve(?array $inline, ?array $manifest): array
    {
        if ($inline !== null) {
            return $inline;
        }

        if ($manifest === null) {
            throw new RuntimeException('Neither inline nor archived payload is available.');
        }

        return $this->read($manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function validateManifest(array $manifest): void
    {
        if (($manifest['version'] ?? null) !== 1 || ($manifest['codec'] ?? null) !== 'gzip-json'
            || ($manifest['disk'] ?? null) !== $this->disk()) {
            throw new RuntimeException('Unsupported payload archive manifest.');
        }

        if (! is_string($manifest['prefix'] ?? null)
            || ! preg_match('/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\z/', $manifest['prefix'])) {
            throw new RuntimeException('Invalid payload archive prefix.');
        }

        foreach (['json_sha256', 'compressed_sha256'] as $key) {
            if (! is_string($manifest[$key] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $manifest[$key])) {
                throw new RuntimeException('Invalid payload archive checksum.');
            }
        }

        foreach (['json_bytes' => $this->jsonLimit(), 'compressed_bytes' => $this->compressedLimit()] as $key => $limit) {
            if (! is_int($manifest[$key] ?? null) || $manifest[$key] < 1 || $manifest[$key] > $limit) {
                throw new RuntimeException('Payload archive exceeds size limits.');
            }
        }

        if (($manifest['object_key'] ?? null) !== $this->objectKey($manifest)) {
            throw new RuntimeException('Invalid payload archive object key.');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function objectKey(array $manifest): string
    {
        return $manifest['prefix']
            .'/payloads/v1/'.$manifest['json_sha256'].'/'.$manifest['compressed_sha256'].'.json.gz';
    }

    private function disk(): string
    {
        return (string) config('provider-data.storage.disk', 'provider-local');
    }

    private function jsonLimit(): int
    {
        return max(1, (int) config('provider-data.payloads.max_json_bytes', 8 * 1024 * 1024));
    }

    private function compressedLimit(): int
    {
        return max(1, (int) config('provider-data.payloads.max_compressed_bytes', 8 * 1024 * 1024));
    }
}
