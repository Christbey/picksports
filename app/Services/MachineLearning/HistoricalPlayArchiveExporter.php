<?php

namespace App\Services\MachineLearning;

use App\Models\DatasetExportManifest;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class HistoricalPlayArchiveExporter
{
    /** @var array<string, array{plays:string,games:string}> */
    private const TABLES = [
        'cbb' => ['plays' => 'cbb_plays', 'games' => 'cbb_games'],
        'cfb' => ['plays' => 'cfb_plays', 'games' => 'cfb_games'],
        'mlb' => ['plays' => 'mlb_plays', 'games' => 'mlb_games'],
        'nba' => ['plays' => 'nba_plays', 'games' => 'nba_games'],
        'nfl' => ['plays' => 'nfl_plays', 'games' => 'nfl_games'],
        'wcbb' => ['plays' => 'wcbb_plays', 'games' => 'wcbb_games'],
        'wnba' => ['plays' => 'wnba_plays', 'games' => 'wnba_games'],
    ];

    public function __construct(private ParquetExportRuntime $parquetRuntime) {}

    public function export(
        string $sport,
        int $season,
        string $format = 'jsonl',
        ?string $diskName = null,
        ?string $prefix = null,
        int $chunkSize = 1000,
    ): DatasetExportManifest {
        $sport = strtolower(trim($sport));
        $format = strtolower(trim($format));

        if (! isset(self::TABLES[$sport])) {
            throw new InvalidArgumentException('Unsupported sport. Expected: '.implode(', ', array_keys(self::TABLES)).'.');
        }
        if ($season < 1900 || $season > 2200) {
            throw new InvalidArgumentException('Season must be between 1900 and 2200.');
        }
        if (! in_array($format, ['jsonl', 'parquet'], true)) {
            throw new InvalidArgumentException('Format must be jsonl or parquet.');
        }
        if ($chunkSize < 1 || $chunkSize > 10000) {
            throw new InvalidArgumentException('Chunk size must be between 1 and 10000.');
        }
        if ($format === 'parquet') {
            $this->parquetRuntime->assertAvailable();
        }

        $tables = self::TABLES[$sport];
        $diskName ??= (string) config('ml.storage.disk', 'ml-local');
        $prefix = $this->prefix($prefix ?? (string) config('ml.storage.prefix', 'ml'));
        $schema = $this->schema($tables['plays']);
        $schemaHash = hash('sha256', $this->json($schema));
        $sourceMaxId = (int) ($this->partitionQuery($tables, $season)->max("{$tables['plays']}.id") ?? 0);

        if ($sourceMaxId === 0) {
            throw new RuntimeException("No {$sport} play rows exist for season {$season}.");
        }

        $jsonlPath = tempnam(sys_get_temp_dir(), 'picksports-plays-');
        if (! is_string($jsonlPath)) {
            throw new RuntimeException('Unable to allocate a temporary export file.');
        }
        $outputPath = $jsonlPath;
        $parquetPath = $jsonlPath.'.parquet';

        try {
            $rowCount = $this->writeJsonl(
                $jsonlPath,
                $tables,
                $season,
                $sourceMaxId,
                $schema,
                $chunkSize,
            );

            if ($format === 'parquet') {
                $this->parquetRuntime->convert($jsonlPath, $parquetPath);
                $outputPath = $parquetPath;
            }

            return $this->store(
                outputPath: $outputPath,
                diskName: $diskName,
                prefix: $prefix,
                sport: $sport,
                season: $season,
                format: $format,
                sourceTable: $tables['plays'],
                sourceMaxId: $sourceMaxId,
                rowCount: $rowCount,
                schema: $schema,
                schemaHash: $schemaHash,
            );
        } finally {
            @unlink($jsonlPath);
            @unlink($parquetPath);
        }
    }

    /**
     * @param  array{plays:string,games:string}  $tables
     * @param  list<array{name:string,type:string,nullable:bool}>  $schema
     */
    private function writeJsonl(
        string $path,
        array $tables,
        int $season,
        int $sourceMaxId,
        array $schema,
        int $chunkSize,
    ): int {
        $handle = fopen($path, 'wb');
        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to open the temporary export file.');
        }

        $columns = array_column($schema, 'name');
        $rowCount = 0;

        try {
            $rows = $this->partitionQuery($tables, $season)
                ->where("{$tables['plays']}.id", '<=', $sourceMaxId)
                ->select("{$tables['plays']}.*")
                ->lazyById($chunkSize, "{$tables['plays']}.id", 'id');

            foreach ($rows as $row) {
                $record = [];
                foreach ($columns as $column) {
                    $record[$column] = $row->{$column};
                }

                if (fwrite($handle, $this->json($record)."\n") === false) {
                    throw new RuntimeException('Unable to write the temporary export file.');
                }
                $rowCount++;
            }
        } finally {
            fclose($handle);
        }

        if ($rowCount === 0) {
            throw new RuntimeException('The play partition became empty during export.');
        }

        return $rowCount;
    }

    /**
     * @param  array{plays:string,games:string}  $tables
     */
    private function partitionQuery(array $tables, int $season): Builder
    {
        return DB::table($tables['plays'])
            ->join($tables['games'], "{$tables['plays']}.game_id", '=', "{$tables['games']}.id")
            ->where("{$tables['games']}.season", $season);
    }

    /**
     * @return list<array{name:string,type:string,nullable:bool}>
     */
    private function schema(string $table): array
    {
        return collect(DB::connection()->getSchemaBuilder()->getColumns($table))
            ->map(fn (array $column): array => [
                'name' => (string) $column['name'],
                'type' => (string) ($column['type_name'] ?? $column['type']),
                'nullable' => (bool) ($column['nullable'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{name:string,type:string,nullable:bool}>  $schema
     */
    private function store(
        string $outputPath,
        string $diskName,
        string $prefix,
        string $sport,
        int $season,
        string $format,
        string $sourceTable,
        int $sourceMaxId,
        int $rowCount,
        array $schema,
        string $schemaHash,
    ): DatasetExportManifest {
        $sha256 = hash_file('sha256', $outputPath);
        $size = filesize($outputPath);
        if (! is_string($sha256) || ! is_int($size)) {
            throw new RuntimeException('Unable to inspect the completed export.');
        }

        $extension = $format === 'parquet' ? 'parquet' : 'jsonl';
        $contentType = $format === 'parquet' ? 'application/vnd.apache.parquet' : 'application/x-ndjson';
        $directory = "{$prefix}/datasets/historical-plays/sport={$sport}/season={$season}/{$sha256}";
        $objectKey = "{$directory}/part-00000.{$extension}";
        $manifestKey = "{$directory}/manifest.json";
        $disk = Storage::disk($diskName);

        $this->storeImmutableFile($disk, $objectKey, $outputPath, $sha256, $contentType);

        $manifest = [
            'manifest_version' => 1,
            'dataset' => 'historical-plays',
            'partition' => ['sport' => $sport, 'season' => $season],
            'format' => $format,
            'content_type' => $contentType,
            'object_key' => $objectKey,
            'sha256' => $sha256,
            'size_bytes' => $size,
            'row_count' => $rowCount,
            'schema_hash' => $schemaHash,
            'schema' => $schema,
            'source' => [
                'connection' => DB::getDefaultConnection(),
                'table' => $sourceTable,
                'maximum_id_included' => $sourceMaxId,
                'deletion_performed' => false,
            ],
        ];
        $manifestContents = $this->json($manifest)."\n";
        $manifestSha256 = hash('sha256', $manifestContents);
        $this->storeImmutableContents($disk, $manifestKey, $manifestContents, $manifestSha256);

        return DatasetExportManifest::query()->firstOrCreate(
            [
                'dataset' => 'historical-plays',
                'sport' => $sport,
                'season' => $season,
                'format' => $format,
                'sha256' => $sha256,
            ],
            [
                'content_type' => $contentType,
                'disk' => $diskName,
                'object_key' => $objectKey,
                'manifest_key' => $manifestKey,
                'uri' => $this->uri($diskName, $objectKey),
                'manifest_sha256' => $manifestSha256,
                'schema_hash' => $schemaHash,
                'row_count' => $rowCount,
                'size_bytes' => $size,
                'source_table' => $sourceTable,
                'source_max_id' => $sourceMaxId,
                'exported_at' => now(),
                'metadata' => [
                    'partition' => $manifest['partition'],
                    'source' => $manifest['source'],
                ],
            ],
        );
    }

    private function storeImmutableFile(
        FilesystemAdapter $disk,
        string $key,
        string $path,
        string $sha256,
        string $contentType,
    ): void {
        if ($disk->exists($key)) {
            $this->assertStoredHash($disk, $key, $sha256);
            $disk->setVisibility($key, 'private');

            return;
        }

        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to open the completed export for storage.');
        }

        try {
            $written = $disk->put($key, $stream, ['visibility' => 'private', 'ContentType' => $contentType]);
        } finally {
            fclose($stream);
        }

        if (! $written) {
            throw new RuntimeException("Unable to store dataset object: {$key}");
        }
        $this->assertStoredHash($disk, $key, $sha256);
        $disk->setVisibility($key, 'private');
    }

    private function storeImmutableContents(
        FilesystemAdapter $disk,
        string $key,
        string $contents,
        string $sha256,
    ): void {
        if ($disk->exists($key)) {
            $this->assertStoredHash($disk, $key, $sha256);
            $disk->setVisibility($key, 'private');

            return;
        }

        if (! $disk->put($key, $contents, ['visibility' => 'private', 'ContentType' => 'application/json'])) {
            throw new RuntimeException("Unable to store dataset manifest: {$key}");
        }
        $this->assertStoredHash($disk, $key, $sha256);
        $disk->setVisibility($key, 'private');
    }

    private function assertStoredHash(FilesystemAdapter $disk, string $key, string $expected): void
    {
        $stream = $disk->readStream($key);
        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read stored dataset object: {$key}");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            $actual = hash_final($context);
        } finally {
            fclose($stream);
        }

        if (! hash_equals($expected, $actual)) {
            throw new RuntimeException("Refusing to overwrite immutable dataset object: {$key}");
        }
    }

    private function uri(string $diskName, string $key): string
    {
        $configuration = (array) config("filesystems.disks.{$diskName}", []);
        if (($configuration['driver'] ?? null) === 's3' && filled($configuration['bucket'] ?? null)) {
            return 's3://'.trim((string) $configuration['bucket'], '/').'/'.$key;
        }

        return "storage://{$diskName}/{$key}";
    }

    private function prefix(string $prefix): string
    {
        $segments = array_filter(explode('/', trim($prefix, '/')), static fn (string $segment): bool => $segment !== '');
        foreach ($segments as $segment) {
            if ($segment === '..' || ! preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
                throw new InvalidArgumentException('The storage prefix contains an unsafe path segment.');
            }
        }

        return $segments === [] ? 'ml' : implode('/', $segments);
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }
}
