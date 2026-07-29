<?php

namespace App\Services\NFL;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use ZipArchive;

class NflTabularModelBundle
{
    private const BUNDLE_MANIFEST = 'bundle.json';

    /**
     * @var list<string>
     */
    private const REQUIRED_RUN_FILES = [
        'calibrators/logistic_regression_isotonic.joblib',
        'calibrators/logistic_regression_platt.joblib',
        'calibrators/xgboost_isotonic.joblib',
        'calibrators/xgboost_platt.joblib',
        'evaluation.json',
        'feature_schema.yaml',
        'models/logistic_classifier.joblib',
        'models/xgboost_classifier.ubj',
        'models/xgboost_home_margin.ubj',
        'models/xgboost_total_points.ubj',
        'prediction_example.json',
        'preprocessor.joblib',
    ];

    /**
     * @return array{
     *     path: string,
     *     temporary_directory: string,
     *     manifest: array<string, mixed>,
     *     evaluation: array<string, mixed>,
     *     bundle: array<string, mixed>
     * }
     */
    public function create(string $runDirectory, ?string $datasetPath = null): array
    {
        $runDirectory = $this->directoryPath($runDirectory);
        $manifest = $this->jsonFile($runDirectory.'/manifest.json', 'Python run manifest');
        $evaluation = $this->jsonFile($runDirectory.'/evaluation.json', 'Python evaluation report');
        $runFiles = $this->verifiedRunFiles($runDirectory, $manifest);

        $this->assertRunLineage($manifest, $evaluation);

        $files = [
            'manifest.json' => $this->fileMetadata($runDirectory.'/manifest.json'),
            ...$runFiles,
        ];
        $datasetEntry = null;

        if ($datasetPath !== null) {
            $datasetPath = $this->filePath($datasetPath, 'Exact training dataset');
            $datasetHash = (string) ($manifest['dataset_hash'] ?? '');
            $actualDatasetHash = hash_file('sha256', $datasetPath);

            if (! is_string($actualDatasetHash) || ! hash_equals($datasetHash, $actualDatasetHash)) {
                throw new RuntimeException('The exact training dataset does not match the Python manifest dataset hash.');
            }

            $extension = strtolower(pathinfo($datasetPath, PATHINFO_EXTENSION));
            $datasetEntry = 'dataset/training-data'.($extension !== '' ? '.'.$extension : '');
            $files[$datasetEntry] = $this->fileMetadata($datasetPath);
        }

        ksort($files);
        $bundle = [
            'bundle_version' => 1,
            'model_run_id' => $manifest['model_run_id'],
            'artifact_id' => $manifest['artifact_id'],
            'dataset_hash' => $manifest['dataset_hash'],
            'feature_schema_hash' => $manifest['feature_schema_hash'],
            'config_hash' => $manifest['config_hash'],
            'source_manifest_sha256' => $files['manifest.json']['sha256'],
            'dataset_entry' => $datasetEntry,
            'files' => $files,
        ];

        $temporaryDirectory = rtrim((string) config(
            'nfl_ml.bundle.staging_directory',
            storage_path('app/ml/nfl-tabular/staging'),
        ), '/').'/'.Str::uuid();
        File::ensureDirectoryExists($temporaryDirectory, 0700, true);
        $bundlePath = $temporaryDirectory.'/nfl-tabular-'.$manifest['artifact_id'].'.zip';
        $bundleManifestPath = $temporaryDirectory.'/'.self::BUNDLE_MANIFEST;

        File::put(
            $bundleManifestPath,
            json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            true,
        );
        @chmod($bundleManifestPath, 0600);

        $zip = new ZipArchive;
        if ($zip->open($bundlePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            File::deleteDirectory($temporaryDirectory);

            throw new RuntimeException('Unable to create the NFL tabular model bundle.');
        }

        try {
            try {
                foreach (array_keys($files) as $entry) {
                    $sourcePath = $entry === 'manifest.json'
                        ? $runDirectory.'/manifest.json'
                        : ($entry === $datasetEntry ? $datasetPath : $runDirectory.'/'.$entry);

                    if (! $zip->addFile((string) $sourcePath, $entry)) {
                        throw new RuntimeException("Unable to add [{$entry}] to the NFL tabular model bundle.");
                    }
                    $zip->setMtimeName($entry, 315532800);
                }

                if (! $zip->addFile($bundleManifestPath, self::BUNDLE_MANIFEST)) {
                    throw new RuntimeException('Unable to add the bundle manifest to the NFL tabular model bundle.');
                }
                $zip->setMtimeName(self::BUNDLE_MANIFEST, 315532800);
            } finally {
                $zip->close();
            }
        } catch (\Throwable $exception) {
            File::deleteDirectory($temporaryDirectory);

            throw $exception;
        }

        return [
            'path' => $bundlePath,
            'temporary_directory' => $temporaryDirectory,
            'manifest' => $manifest,
            'evaluation' => $evaluation,
            'bundle' => $bundle,
        ];
    }

    public function extractAndVerify(ModelArtifact $artifact, string $bundlePath): string
    {
        $bundlePath = $this->filePath($bundlePath, 'Registered NFL tabular model bundle');
        $zip = new ZipArchive;

        if ($zip->open($bundlePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The registered NFL tabular model bundle is not a readable ZIP archive.');
        }

        try {
            if ($zip->numFiles < 2 || $zip->numFiles > (int) config('nfl_ml.bundle.max_entries', 64) + 1) {
                throw new RuntimeException('The NFL tabular bundle file count exceeds the configured limit.');
            }
            $bundleStat = $zip->statName(self::BUNDLE_MANIFEST);
            if (! is_array($bundleStat)
                || (int) ($bundleStat['size'] ?? -1) > (int) config('nfl_ml.bundle.max_file_bytes', 268_435_456)) {
                throw new RuntimeException('The NFL tabular bundle manifest exceeds the configured size limit.');
            }

            $bundle = $this->decodeJson(
                $zip->getFromName(self::BUNDLE_MANIFEST),
                'NFL tabular bundle manifest',
            );
            $this->assertBundleLineage($bundle, $artifact);
            $files = $this->bundleFiles($bundle);
            $this->assertZipInventory($zip, $files);

            $root = rtrim((string) config(
                'nfl_ml.bundle.extraction_directory',
                storage_path('app/ml/nfl-tabular/extracted'),
            ), '/').'/'.$artifact->id.'/'.$artifact->artifact_hash;

            if (File::isDirectory($root)) {
                $this->verifyExtractedFiles($root, $files);

                return $root;
            }

            $temporaryRoot = $root.'.tmp-'.Str::uuid();
            File::ensureDirectoryExists($temporaryRoot, 0700, true);

            try {
                foreach ($files as $entry => $metadata) {
                    $target = $temporaryRoot.'/'.$entry;
                    File::ensureDirectoryExists(dirname($target), 0700, true);
                    $source = $zip->getStream($entry);
                    $destination = fopen($target, 'xb');

                    if (! is_resource($source) || ! is_resource($destination)) {
                        if (is_resource($source)) {
                            fclose($source);
                        }
                        if (is_resource($destination)) {
                            fclose($destination);
                        }

                        throw new RuntimeException("Unable to extract NFL tabular bundle entry [{$entry}].");
                    }

                    try {
                        if (stream_copy_to_stream($source, $destination) === false) {
                            throw new RuntimeException("Unable to extract NFL tabular bundle entry [{$entry}].");
                        }
                    } finally {
                        fclose($source);
                        fclose($destination);
                    }
                    @chmod($target, 0600);
                    $this->assertFileMetadata($target, $metadata, $entry);
                }

                File::put(
                    $temporaryRoot.'/'.self::BUNDLE_MANIFEST,
                    json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
                    true,
                );
                @chmod($temporaryRoot.'/'.self::BUNDLE_MANIFEST, 0600);
                File::ensureDirectoryExists(dirname($root), 0700, true);

                if (! @rename($temporaryRoot, $root)) {
                    if (! File::isDirectory($root)) {
                        throw new RuntimeException('Unable to publish the verified NFL tabular model extraction.');
                    }
                    File::deleteDirectory($temporaryRoot);
                }
            } catch (\Throwable $exception) {
                File::deleteDirectory($temporaryRoot);

                throw $exception;
            }

            $this->verifyExtractedFiles($root, $files);

            return $root;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array{sha256: string, bytes: int}>
     */
    private function verifiedRunFiles(string $runDirectory, array $manifest): array
    {
        $inventory = $manifest['artifacts'] ?? null;
        if (! is_array($inventory) || array_is_list($inventory)) {
            throw new RuntimeException('The Python run manifest artifact inventory is missing or invalid.');
        }

        foreach (self::REQUIRED_RUN_FILES as $requiredFile) {
            if (! array_key_exists($requiredFile, $inventory)) {
                throw new RuntimeException("The Python run is missing required artifact [{$requiredFile}].");
            }
        }

        $verified = [];
        foreach ($inventory as $entry => $metadata) {
            $entry = $this->safeEntryName($entry);
            $metadata = $this->validFileMetadata($metadata, $entry);
            $path = $runDirectory.'/'.$entry;
            $this->assertFileMetadata($path, $metadata, $entry);
            $verified[$entry] = $metadata;
        }

        $actualFiles = collect(File::allFiles($runDirectory))
            ->map(function (\SplFileInfo $file) use ($runDirectory): string {
                if ($file->isLink()) {
                    throw new RuntimeException('Symbolic links are not allowed in an NFL tabular model run.');
                }

                return str_replace('\\', '/', substr($file->getPathname(), strlen($runDirectory) + 1));
            })
            ->reject(fn (string $entry): bool => $entry === 'manifest.json')
            ->sort()
            ->values()
            ->all();
        $declaredFiles = array_keys($verified);
        sort($declaredFiles);

        if ($actualFiles !== $declaredFiles) {
            throw new RuntimeException('The Python run directory does not exactly match its manifest artifact inventory.');
        }

        return $verified;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $evaluation
     */
    private function assertRunLineage(array $manifest, array $evaluation): void
    {
        if (! is_string($manifest['model_run_id'] ?? null)
            || trim($manifest['model_run_id']) === ''
            || strlen($manifest['model_run_id']) > 191) {
            throw new RuntimeException('The Python run manifest [model_run_id] must be a non-empty stable identifier.');
        }

        if (! is_string($manifest['artifact_id'] ?? null) || ! Str::isUuid($manifest['artifact_id'])) {
            throw new RuntimeException('The Python run manifest [artifact_id] must be a UUID.');
        }

        foreach (['dataset_hash', 'feature_schema_hash', 'config_hash'] as $hashField) {
            if (! $this->isSha256($manifest[$hashField] ?? null)) {
                throw new RuntimeException("The Python run manifest [{$hashField}] must be a SHA-256 hash.");
            }
        }

        foreach (['manifest_version', 'model_version', 'feature_schema_version'] as $requiredField) {
            if (! filled($manifest[$requiredField] ?? null)) {
                throw new RuntimeException("The Python run manifest is missing [{$requiredField}].");
            }
        }

        if (($evaluation['report_type'] ?? null) !== 'nfl_tabular_walk_forward_evaluation') {
            throw new RuntimeException('The Python evaluation report type is invalid.');
        }

        $comparisons = [
            'model_run_id' => data_get($evaluation, 'model_run_id'),
            'artifact_id' => data_get($evaluation, 'artifact_id'),
            'dataset_hash' => data_get($evaluation, 'dataset.sha256'),
            'feature_schema_hash' => data_get($evaluation, 'feature_schema.sha256'),
        ];

        foreach ($comparisons as $manifestField => $evaluationValue) {
            if (! hash_equals((string) $manifest[$manifestField], (string) $evaluationValue)) {
                throw new RuntimeException("The Python evaluation [{$manifestField}] does not match its run manifest.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function assertBundleLineage(array $bundle, ModelArtifact $artifact): void
    {
        $trainingRun = $artifact->trainingRun;
        if (! $trainingRun instanceof ModelRun) {
            throw new RuntimeException('The registered NFL tabular bundle has no training run.');
        }

        $expected = [
            'artifact_id' => (string) $artifact->id,
            'model_run_id' => (string) data_get($trainingRun->metadata, 'python_model_run_id', ''),
            'dataset_hash' => (string) $artifact->dataset_hash,
            'config_hash' => (string) $trainingRun->config_hash,
            'feature_schema_hash' => (string) data_get($trainingRun->parameters, 'feature_schema_hash', ''),
        ];

        foreach ($expected as $field => $value) {
            if (! hash_equals($value, (string) ($bundle[$field] ?? ''))) {
                throw new RuntimeException("The NFL tabular bundle [{$field}] does not match registered lineage.");
            }
        }

        if (($bundle['bundle_version'] ?? null) !== 1) {
            throw new RuntimeException('The NFL tabular bundle version is unsupported.');
        }

        $bundleFiles = is_array($bundle['files'] ?? null) ? $bundle['files'] : [];
        $manifestMetadata = is_array($bundleFiles['manifest.json'] ?? null)
            ? $bundleFiles['manifest.json']
            : [];
        if (! hash_equals(
            (string) ($manifestMetadata['sha256'] ?? ''),
            (string) ($bundle['source_manifest_sha256'] ?? ''),
        )) {
            throw new RuntimeException('The NFL tabular bundle source manifest hash is inconsistent.');
        }

        $datasetEntry = $bundle['dataset_entry'] ?? null;
        if ($datasetEntry !== null) {
            $datasetEntry = $this->safeEntryName($datasetEntry);
            $datasetMetadata = is_array($bundleFiles[$datasetEntry] ?? null)
                ? $bundleFiles[$datasetEntry]
                : [];
            if (! hash_equals(
                (string) $artifact->dataset_hash,
                (string) ($datasetMetadata['sha256'] ?? ''),
            )) {
                throw new RuntimeException('The bundled NFL training dataset failed lineage verification.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, array{sha256: string, bytes: int}>
     */
    private function bundleFiles(array $bundle): array
    {
        $files = $bundle['files'] ?? null;
        if (! is_array($files) || array_is_list($files)) {
            throw new RuntimeException('The NFL tabular bundle file inventory is missing or invalid.');
        }

        $maximumEntries = (int) config('nfl_ml.bundle.max_entries', 64);
        if (count($files) === 0 || count($files) > $maximumEntries) {
            throw new RuntimeException('The NFL tabular bundle file count exceeds the configured limit.');
        }

        $validated = [];
        $totalBytes = 0;
        foreach ($files as $entry => $metadata) {
            $entry = $this->safeEntryName($entry);
            $validated[$entry] = $this->validFileMetadata($metadata, $entry);
            $totalBytes += $validated[$entry]['bytes'];
        }

        if ($totalBytes > (int) config('nfl_ml.bundle.max_uncompressed_bytes', 1_073_741_824)) {
            throw new RuntimeException('The NFL tabular bundle exceeds the configured uncompressed size limit.');
        }

        return $validated;
    }

    /**
     * @param  array<string, array{sha256: string, bytes: int}>  $files
     */
    private function assertZipInventory(ZipArchive $zip, array $files): void
    {
        $actual = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (! is_array($stat) || ! isset($stat['name'], $stat['size'])) {
                throw new RuntimeException('Unable to inspect the NFL tabular bundle inventory.');
            }

            $entry = (string) $stat['name'] === self::BUNDLE_MANIFEST
                ? self::BUNDLE_MANIFEST
                : $this->safeEntryName((string) $stat['name']);
            if (isset($actual[$entry])) {
                throw new RuntimeException("The NFL tabular bundle contains duplicate entry [{$entry}].");
            }
            $actual[$entry] = (int) $stat['size'];
        }

        $expected = array_keys($files);
        $expected[] = self::BUNDLE_MANIFEST;
        sort($expected);
        $actualEntries = array_keys($actual);
        sort($actualEntries);

        if ($actualEntries !== $expected) {
            throw new RuntimeException('The NFL tabular ZIP does not exactly match its bundle inventory.');
        }

        foreach ($files as $entry => $metadata) {
            if ($actual[$entry] !== $metadata['bytes']) {
                throw new RuntimeException("NFL tabular bundle entry [{$entry}] has an unexpected size.");
            }
        }
    }

    /**
     * @param  array<string, array{sha256: string, bytes: int}>  $files
     */
    private function verifyExtractedFiles(string $root, array $files): void
    {
        foreach ($files as $entry => $metadata) {
            $this->assertFileMetadata($root.'/'.$entry, $metadata, $entry);
        }

        $actual = collect(File::allFiles($root))
            ->map(fn (\SplFileInfo $file): string => str_replace(
                '\\',
                '/',
                substr($file->getPathname(), strlen($root) + 1),
            ))
            ->sort()
            ->values()
            ->all();
        $expected = [...array_keys($files), self::BUNDLE_MANIFEST];
        sort($expected);

        if ($actual !== $expected) {
            throw new RuntimeException('The extracted NFL tabular run contains unexpected files.');
        }
    }

    /**
     * @return array{sha256: string, bytes: int}
     */
    private function validFileMetadata(mixed $metadata, string $entry): array
    {
        if (! is_array($metadata)
            || ! $this->isSha256($metadata['sha256'] ?? null)
            || ! is_int($metadata['bytes'] ?? null)
            || $metadata['bytes'] < 0
            || $metadata['bytes'] > (int) config('nfl_ml.bundle.max_file_bytes', 268_435_456)) {
            throw new RuntimeException("NFL tabular file metadata is invalid for [{$entry}].");
        }

        return [
            'sha256' => $metadata['sha256'],
            'bytes' => $metadata['bytes'],
        ];
    }

    /**
     * @param  array{sha256: string, bytes: int}  $metadata
     */
    private function assertFileMetadata(string $path, array $metadata, string $entry): void
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException("NFL tabular artifact [{$entry}] is missing or is not a regular file.");
        }

        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        if (! is_int($size)
            || $size !== $metadata['bytes']
            || ! is_string($hash)
            || ! hash_equals($metadata['sha256'], $hash)) {
            throw new RuntimeException("NFL tabular artifact [{$entry}] failed hash verification.");
        }
    }

    /**
     * @return array{sha256: string, bytes: int}
     */
    private function fileMetadata(string $path): array
    {
        $hash = hash_file('sha256', $path);
        $size = filesize($path);
        if (! is_string($hash) || ! is_int($size)) {
            throw new RuntimeException("Unable to inspect NFL tabular artifact [{$path}].");
        }

        return ['sha256' => $hash, 'bytes' => $size];
    }

    private function safeEntryName(mixed $entry): string
    {
        if (! is_string($entry)
            || $entry === ''
            || str_contains($entry, "\0")
            || str_contains($entry, '\\')
            || str_starts_with($entry, '/')
            || str_ends_with($entry, '/')
            || in_array('..', explode('/', $entry), true)
            || in_array('.', explode('/', $entry), true)
            || in_array('', explode('/', $entry), true)
            || $entry === self::BUNDLE_MANIFEST) {
            throw new RuntimeException('The NFL tabular bundle contains an unsafe file path.');
        }

        return $entry;
    }

    private function directoryPath(string $path): string
    {
        $absolute = $this->absolutePath($path);
        $real = realpath($absolute);
        if ($real === false || ! is_dir($real) || ! is_readable($real)) {
            throw new RuntimeException("NFL tabular run directory not found or unreadable: {$absolute}");
        }

        return rtrim($real, '/');
    }

    private function filePath(string $path, string $label): string
    {
        $absolute = $this->absolutePath($path);
        $real = realpath($absolute);
        if ($real === false || ! is_file($real) || ! is_readable($real) || is_link($absolute)) {
            throw new RuntimeException("{$label} not found or unreadable: {$absolute}");
        }

        return $real;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFile(string $path, string $label): array
    {
        return $this->decodeJson(File::exists($path) ? File::get($path) : false, $label);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string|false $json, string $label): array
    {
        if (! is_string($json)) {
            throw new RuntimeException("{$label} is missing.");
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$label} is not valid JSON.", previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("{$label} must be a JSON object.");
        }

        return $decoded;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
