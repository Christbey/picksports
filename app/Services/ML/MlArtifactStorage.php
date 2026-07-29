<?php

namespace App\Services\ML;

use App\Models\ModelRun;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class MlArtifactStorage
{
    public function storeArtifact(
        ModelRun $trainingRun,
        string $artifactId,
        string $sourcePath,
        ?string $contentType = null,
    ): MlStoredObject {
        return $this->store($trainingRun, $artifactId, 'artifacts', $sourcePath, $contentType);
    }

    public function storeDataset(
        ModelRun $trainingRun,
        string $artifactId,
        string $sourcePath,
        ?string $contentType = null,
    ): MlStoredObject {
        return $this->store($trainingRun, $artifactId, 'datasets', $sourcePath, $contentType);
    }

    public function storeReport(
        ModelRun $trainingRun,
        string $artifactId,
        string $sourcePath,
        ?string $contentType = null,
    ): MlStoredObject {
        return $this->store($trainingRun, $artifactId, 'reports', $sourcePath, $contentType);
    }

    public function materialize(
        string $disk,
        string $objectKey,
        string $sha256,
        ?string $contentType = null,
    ): string {
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
    ): MlStoredObject {
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

    private function hashStoredObject(FilesystemAdapter $disk, string $objectKey): string
    {
        $stream = $disk->readStream($objectKey);
        if (! is_resource($stream)) {
            throw new \RuntimeException("Unable to read ML object for hashing: {$objectKey}");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
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
