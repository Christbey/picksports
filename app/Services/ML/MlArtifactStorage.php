<?php

namespace App\Services\ML;

use App\Models\ModelRun;
use App\Services\MLB\MlbTabularFiles;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class MlArtifactStorage
{
    public function storeArtifact(
        ModelRun $trainingRun,
        string $artifactId,
        string $sourcePath,
        ?string $contentType = null,
        ?int $expectedBytes = null,
        ?string $expectedSha256 = null,
    ): MlStoredObject {
        return $this->store($trainingRun, $artifactId, 'artifacts', $sourcePath, $contentType, $expectedBytes, $expectedSha256);
    }

    public function storeDataset(
        ModelRun $trainingRun,
        string $artifactId,
        string $sourcePath,
        ?string $contentType = null,
        ?int $expectedBytes = null,
        ?string $expectedSha256 = null,
    ): MlStoredObject {
        return $this->store($trainingRun, $artifactId, 'datasets', $sourcePath, $contentType, $expectedBytes, $expectedSha256);
    }

    public function storeReport(
        ModelRun $trainingRun,
        string $artifactId,
        string $sourcePath,
        ?string $contentType = null,
        ?int $expectedBytes = null,
        ?string $expectedSha256 = null,
    ): MlStoredObject {
        return $this->store($trainingRun, $artifactId, 'reports', $sourcePath, $contentType, $expectedBytes, $expectedSha256);
    }

    public function materialize(
        string $disk,
        string $objectKey,
        string $sha256,
        ?string $contentType = null,
        ?int $expectedBytes = null,
    ): string {
        if ($expectedBytes !== null) {
            return $this->materializeBounded($disk, $objectKey, $sha256, $contentType, $expectedBytes);
        }
        $cache = Storage::disk($this->cacheDisk());
        $cacheKey = ltrim($objectKey, '/');

        if ($cache->exists($cacheKey)) {
            if (hash_equals($sha256, $this->hashStoredObject($cache, $cacheKey))) {
                return $cache->path($cacheKey);
            }

            $cache->delete($cacheKey);
        }

        $source = Storage::disk($disk);
        if (! $source->exists($objectKey)) {
            throw new \RuntimeException("ML object not found on disk [{$disk}]: {$objectKey}");
        }

        if (! hash_equals($sha256, $this->hashStoredObject($source, $objectKey))) {
            throw new \RuntimeException("ML object failed SHA-256 verification: {$objectKey}");
        }

        $stream = $source->readStream($objectKey);
        if (! is_resource($stream)) {
            throw new \RuntimeException("Unable to read ML object: {$objectKey}");
        }

        try {
            $written = $cache->put($cacheKey, $stream, [
                'visibility' => 'private',
                'ContentType' => $contentType ?: 'application/octet-stream',
            ]);
        } finally {
            fclose($stream);
        }

        if (! $written || ! hash_equals($sha256, $this->hashStoredObject($cache, $cacheKey))) {
            $cache->delete($cacheKey);

            throw new \RuntimeException("Materialized ML object failed SHA-256 verification: {$objectKey}");
        }

        return $cache->path($cacheKey);
    }

    private function store(
        ModelRun $trainingRun,
        string $artifactId,
        string $kind,
        string $sourcePath,
        ?string $contentType,
        ?int $expectedBytes = null,
        ?string $expectedSha256 = null,
    ): MlStoredObject {
        if ($expectedBytes !== null || $expectedSha256 !== null) {
            return $this->storeBounded($trainingRun, $artifactId, $kind, $sourcePath, $contentType, $expectedBytes, $expectedSha256);
        }
        $sourcePath = $this->absolutePath($sourcePath);
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new \RuntimeException("ML source file not found or unreadable: {$sourcePath}");
        }

        $sha256 = hash_file('sha256', $sourcePath);
        $size = filesize($sourcePath);
        if (! is_string($sha256) || ! is_int($size)) {
            throw new \RuntimeException("Unable to inspect ML source file: {$sourcePath}");
        }

        $contentType = $contentType ?: $this->contentType($sourcePath);
        $diskName = $this->disk();
        $disk = Storage::disk($diskName);
        $objectKey = $this->objectKey(
            sport: (string) $trainingRun->sport,
            runId: (string) $trainingRun->getKey(),
            artifactId: $artifactId,
            kind: $kind,
            filename: basename($sourcePath),
        );

        if ($disk->exists($objectKey)) {
            if (! hash_equals($sha256, $this->hashStoredObject($disk, $objectKey))) {
                throw new \RuntimeException("Refusing to overwrite immutable ML object: {$objectKey}");
            }

            $disk->setVisibility($objectKey, 'private');
        } else {
            $stream = fopen($sourcePath, 'rb');
            if (! is_resource($stream)) {
                throw new \RuntimeException("Unable to open ML source file: {$sourcePath}");
            }

            try {
                $written = $disk->put($objectKey, $stream, [
                    'visibility' => 'private',
                    'ContentType' => $contentType,
                ]);
            } finally {
                fclose($stream);
            }

            if (! $written) {
                throw new \RuntimeException("Unable to store ML object: {$objectKey}");
            }
        }

        if (! hash_equals($sha256, $this->hashStoredObject($disk, $objectKey))) {
            throw new \RuntimeException("Stored ML object failed SHA-256 verification: {$objectKey}");
        }

        $localPath = $this->materialize($diskName, $objectKey, $sha256, $contentType);

        return new MlStoredObject(
            disk: $diskName,
            objectKey: $objectKey,
            uri: $this->uri($diskName, $objectKey),
            sha256: $sha256,
            size: $size,
            contentType: $contentType,
            localPath: $localPath,
        );
    }

    private function hashStoredObject(FilesystemAdapter $disk, string $objectKey, ?int $expectedBytes = null): string
    {
        if ($expectedBytes !== null && $disk->size($objectKey) !== $expectedBytes) {
            throw new \RuntimeException('Stored ML object byte length changed before hashing.');
        }
        $stream = $disk->readStream($objectKey);
        if (! is_resource($stream)) {
            throw new \RuntimeException("Unable to read ML object for hashing: {$objectKey}");
        }

        try {
            if ($expectedBytes !== null) {
                return MlbTabularFiles::digest($stream, $expectedBytes);
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function assertBound(int $bytes, string $sha256): void
    {
        if ($bytes < 0 || $bytes > 1073741824 || ! preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new \RuntimeException('Bounded ML object metadata is invalid.');
        }
    }

    /** Copy/hash exactly once into an anonymous owned file before publishing to another backend. */
    private function verifiedCopy($source, int $bytes, string $sha256)
    {
        $copy = tmpfile();
        if (! is_resource($copy)) {
            throw new \RuntimeException('Unable to create bounded ML object staging.');
        }
        try {
            if (! hash_equals($sha256, MlbTabularFiles::digest($source, $bytes, $copy)) || ! fflush($copy)) {
                throw new \RuntimeException('Bounded ML object failed exact byte/hash verification.');
            }
            rewind($copy);

            return $copy;
        } catch (\Throwable $exception) {
            fclose($copy);
            throw $exception;
        }
    }

    private function materializeBounded(string $disk, string $objectKey, string $sha256, ?string $contentType, int $bytes): string
    {
        $this->assertBound($bytes, $sha256);
        $cache = Storage::disk($this->cacheDisk());
        $cacheKey = ltrim($objectKey, '/');
        if ($cache->exists($cacheKey)) {
            try {
                if (hash_equals($sha256, $this->hashStoredObject($cache, $cacheKey, $bytes))) {
                    return $cache->path($cacheKey);
                }
            } catch (\RuntimeException) {
                // Only the transient owned cache is repaired. Stored source/archive objects remain untouched.
            }
            $cache->delete($cacheKey);
        }
        $source = Storage::disk($disk);
        if (! $source->exists($objectKey) || $source->size($objectKey) !== $bytes) {
            throw new \RuntimeException('Stored ML object differs from its admitted byte length.');
        }
        $input = $source->readStream($objectKey);
        if (! is_resource($input)) {
            throw new \RuntimeException('Unable to read bounded stored ML object.');
        }
        try {
            $copy = $this->verifiedCopy($input, $bytes, $sha256);
        } finally {
            fclose($input);
        }
        try {
            $written = $cache->put($cacheKey, $copy, ['visibility' => 'private', 'ContentType' => $contentType ?: 'application/octet-stream']);
        } finally {
            fclose($copy);
        }
        if (! $written || ! hash_equals($sha256, $this->hashStoredObject($cache, $cacheKey, $bytes))) {
            $cache->delete($cacheKey);
            throw new \RuntimeException('Bounded materialized ML object failed verification.');
        }

        return $cache->path($cacheKey);
    }

    private function storeBounded(ModelRun $trainingRun, string $artifactId, string $kind, string $sourcePath, ?string $contentType, ?int $bytes, ?string $sha256): MlStoredObject
    {
        if ($bytes === null || $sha256 === null) {
            throw new \RuntimeException('Both exact length and hash are required for bounded ML storage.');
        }
        $this->assertBound($bytes, $sha256);
        $sourcePath = $this->absolutePath($sourcePath);
        if (! is_file($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('Bounded ML source must be a regular owned file.');
        }
        $input = fopen($sourcePath, 'rb');
        if (! is_resource($input)) {
            throw new \RuntimeException('Unable to open bounded ML source.');
        }
        try {
            $stat = fstat($input);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $bytes) {
                throw new \RuntimeException('Bounded ML source identity/size changed before hashing.');
            }
            $copy = $this->verifiedCopy($input, $bytes, $sha256);
        } finally {
            fclose($input);
        }
        try {
            $contentType = $contentType ?: $this->contentType($sourcePath);
            $diskName = $this->disk();
            $disk = Storage::disk($diskName);
            $objectKey = $this->objectKey((string) $trainingRun->sport, (string) $trainingRun->getKey(), $artifactId, $kind, basename($sourcePath));
            if ($disk->exists($objectKey)) {
                if (! hash_equals($sha256, $this->hashStoredObject($disk, $objectKey, $bytes))) {
                    throw new \RuntimeException('Refusing to overwrite an immutable bounded ML object.');
                }
                $disk->setVisibility($objectKey, 'private');
            } elseif (! $disk->put($objectKey, $copy, ['visibility' => 'private', 'ContentType' => $contentType])) {
                throw new \RuntimeException('Unable to store bounded ML object.');
            }
            if (! hash_equals($sha256, $this->hashStoredObject($disk, $objectKey, $bytes))) {
                throw new \RuntimeException('Stored bounded ML object failed verification.');
            }
        } finally {
            fclose($copy);
        }
        $local = $this->materialize($diskName, $objectKey, $sha256, $contentType, $bytes);

        return new MlStoredObject(disk: $diskName, objectKey: $objectKey, uri: $this->uri($diskName, $objectKey), sha256: $sha256,
            size: $bytes, contentType: $contentType, localPath: $local);
    }

    private function objectKey(
        string $sport,
        string $runId,
        string $artifactId,
        string $kind,
        string $filename,
    ): string {
        return implode('/', [
            trim((string) config('ml.storage.prefix', 'ml'), '/'),
            $this->segment($sport),
            'runs',
            $this->segment($runId),
            $kind,
            $this->segment($artifactId),
            $this->filename($filename),
        ]);
    }

    private function uri(string $disk, string $objectKey): string
    {
        $config = (array) config("filesystems.disks.{$disk}", []);

        if (($config['driver'] ?? null) === 's3' && filled($config['bucket'] ?? null)) {
            return 's3://'.trim((string) $config['bucket'], '/').'/'.$objectKey;
        }

        if (($config['driver'] ?? null) === 'local') {
            return 'file://'.Storage::disk($disk)->path($objectKey);
        }

        return "storage://{$disk}/{$objectKey}";
    }

    private function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv' => 'text/csv',
            'json' => 'application/json',
            'jsonl' => 'application/x-ndjson',
            'parquet' => 'application/vnd.apache.parquet',
            default => mime_content_type($path) ?: 'application/octet-stream',
        };
    }

    private function segment(string $value): string
    {
        $segment = strtolower(trim($value));
        $segment = preg_replace('/[^a-z0-9._-]+/', '-', $segment) ?? '';
        $segment = trim($segment, '.-_');

        if ($segment === '') {
            throw new \InvalidArgumentException('ML object path segments cannot be empty.');
        }

        return $segment;
    }

    private function filename(string $value): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($value)) ?? '';
        $filename = trim($filename, '.-');

        return $filename !== '' ? $filename : 'object.bin';
    }

    private function disk(): string
    {
        return (string) config('ml.storage.disk', 'ml-local');
    }

    private function cacheDisk(): string
    {
        return (string) config('ml.storage.cache_disk', 'ml-cache');
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
