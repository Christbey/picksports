<?php

namespace App\Services\ProviderData;

use App\Models\ProviderImportManifest;
use App\Models\ProviderSourceFile;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class ProviderSourceStorage
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function archive(
        string $provider,
        string $dataset,
        string $sourcePath,
        array $metadata = [],
    ): ProviderSourceFile {
        $provider = $this->segment($provider);
        $dataset = $this->segment($dataset);
        $sourcePath = $this->absolutePath($sourcePath);

        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException("Provider source file is missing or unreadable: {$sourcePath}");
        }

        $sha256 = hash_file('sha256', $sourcePath);
        $size = filesize($sourcePath);
        if (! is_string($sha256) || ! is_int($size)) {
            throw new RuntimeException("Unable to inspect provider source file: {$sourcePath}");
        }

        $existing = ProviderSourceFile::query()
            ->where('provider', $provider)
            ->where('dataset', $dataset)
            ->where('sha256', $sha256)
            ->first();
        if ($existing !== null) {
            $this->verifyExistingObject($existing);

            return $existing;
        }

        $filename = $this->filename(basename($sourcePath));
        $diskName = (string) config('provider-data.storage.disk', 'provider-local');
        $disk = Storage::disk($diskName);
        $objectKey = implode('/', [
            trim((string) config('provider-data.storage.prefix', 'providers'), '/'),
            $provider,
            $dataset,
            substr($sha256, 0, 2),
            $sha256,
            $filename,
        ]);

        if ($disk->exists($objectKey)) {
            if (! hash_equals($sha256, $this->hashStoredObject($disk, $objectKey))) {
                throw new RuntimeException("Refusing to overwrite immutable provider object: {$objectKey}");
            }
        } else {
            $stream = fopen($sourcePath, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException("Unable to open provider source file: {$sourcePath}");
            }

            try {
                $written = $disk->put($objectKey, $stream, [
                    'visibility' => 'private',
                    'ContentType' => $this->contentType($sourcePath),
                ]);
            } finally {
                fclose($stream);
            }

            if (! $written) {
                throw new RuntimeException("Unable to store provider source object: {$objectKey}");
            }
        }

        if (! hash_equals($sha256, $this->hashStoredObject($disk, $objectKey))) {
            throw new RuntimeException("Stored provider source object failed SHA-256 verification: {$objectKey}");
        }

        $disk->setVisibility($objectKey, 'private');

        return ProviderSourceFile::query()->firstOrCreate(
            compact('provider', 'dataset', 'sha256'),
            [
                'disk' => $diskName,
                'object_key' => $objectKey,
                'uri' => $this->uri($diskName, $objectKey),
                'original_filename' => basename($sourcePath),
                'content_type' => $this->contentType($sourcePath),
                'compression' => $this->compression($sourcePath),
                'size_bytes' => $size,
                'metadata' => $metadata,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function recordImport(
        ProviderSourceFile $sourceFile,
        int $rowsRead,
        int $rowsImported,
        int $rowsSkipped,
        CarbonInterface $startedAt,
        array $options = [],
    ): ProviderImportManifest {
        return ProviderImportManifest::query()->create([
            'provider_source_file_id' => $sourceFile->getKey(),
            'provider' => $sourceFile->provider,
            'dataset' => $sourceFile->dataset,
            'status' => 'completed',
            'options' => $options,
            'rows_read' => max(0, $rowsRead),
            'rows_imported' => max(0, $rowsImported),
            'rows_skipped' => max(0, $rowsSkipped),
            'started_at' => $startedAt,
            'completed_at' => now(),
        ]);
    }

    private function verifyExistingObject(ProviderSourceFile $sourceFile): void
    {
        $disk = Storage::disk($sourceFile->disk);
        if (! $disk->exists($sourceFile->object_key)) {
            throw new RuntimeException("Archived provider source object is missing: {$sourceFile->object_key}");
        }

        if (! hash_equals($sourceFile->sha256, $this->hashStoredObject($disk, $sourceFile->object_key))) {
            throw new RuntimeException("Archived provider source object failed SHA-256 verification: {$sourceFile->object_key}");
        }
    }

    private function hashStoredObject(FilesystemAdapter $disk, string $objectKey): string
    {
        $stream = $disk->readStream($objectKey);
        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read provider source object: {$objectKey}");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function uri(string $disk, string $objectKey): string
    {
        $configuration = (array) config("filesystems.disks.{$disk}", []);
        if (($configuration['driver'] ?? null) === 's3' && filled($configuration['bucket'] ?? null)) {
            return 's3://'.trim((string) $configuration['bucket'], '/').'/'.$objectKey;
        }

        if (($configuration['driver'] ?? null) === 'local') {
            return 'file://'.Storage::disk($disk)->path($objectKey);
        }

        return "storage://{$disk}/{$objectKey}";
    }

    private function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv' => 'text/csv',
            'gz', 'gzip' => 'application/gzip',
            'json' => 'application/json',
            'jsonl' => 'application/x-ndjson',
            'parquet' => 'application/vnd.apache.parquet',
            default => mime_content_type($path) ?: 'application/octet-stream',
        };
    }

    private function compression(string $path): ?string
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['gz', 'gzip'], true)
            ? 'gzip'
            : null;
    }

    private function segment(string $value): string
    {
        $segment = strtolower(trim($value));
        $segment = preg_replace('/[^a-z0-9._-]+/', '-', $segment) ?? '';
        $segment = trim($segment, '.-_');

        if ($segment === '') {
            throw new InvalidArgumentException('Provider source path segments cannot be empty.');
        }

        return $segment;
    }

    private function filename(string $value): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($value)) ?? '';
        $filename = trim($filename, '.-');

        return $filename !== '' ? $filename : 'source.bin';
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
