<?php

namespace App\Services\NFL;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

class NflTabularModelBundle
{
    private const BUNDLE_MANIFEST = 'bundle.json';

    private const MAX_JSON_BYTES = 2097152;

    private const EXECUTION_FORMAT = 'picksports-nfl-tabular-numeric-v1';

    /**
     * @var list<string>
     */
    private const REQUIRED_RUN_FILES = [
        'calibrators/logistic_regression_isotonic.json',
        'calibrators/logistic_regression_platt.json',
        'calibrators/xgboost_isotonic.json',
        'calibrators/xgboost_platt.json',
        'evaluation.json',
        'feature_schema.json',
        'models/logistic_classifier.json',
        'models/xgboost_classifier.json',
        'models/xgboost_home_margin.json',
        'models/xgboost_total_points.json',
        'prediction_example.json',
        'preprocessor.json',
        'tuning/optuna_provenance.json',
        'explanations/global_shap_importance.json',
        'explanations/xgboost_feature_importance.json',
        'explanations/shap_values.parquet',
    ];

    /**
     * @return array{
     *     path: string,
     *     temporary_directory: string,
     *     manifest: array<string, mixed>,
     *     evaluation: array<string, mixed>,
     *     bundle: array<string, mixed>,
     *     owned_dataset_path: string|null,
     *     owned_report_path: string,
     *     storage_expectations: array<string, array{sha256: string, bytes: int}>
     * }
     */
    public function create(string $runDirectory, ?string $datasetPath = null): array
    {
        $runDirectory = $this->directoryPath($runDirectory);
        [$manifest, $manifestMetadata] = $this->jsonFileEvidence($runDirectory.'/manifest.json', 'Python run manifest');
        [$evaluation, $evaluationMetadata] = $this->jsonFileEvidence($runDirectory.'/evaluation.json', 'Python evaluation report');
        $this->assertRunLineage($manifest, $evaluation);
        $runFiles = $this->verifiedRunFiles($runDirectory, $manifest);
        if ($evaluationMetadata !== $runFiles['evaluation.json']) {
            throw new RuntimeException('NFL parsed evaluation bytes differ from the admitted inventory.');
        }

        $files = [
            'manifest.json' => $manifestMetadata,
            ...$runFiles,
        ];
        $datasetEntry = null;

        if ($datasetPath !== null) {
            $datasetPath = $this->filePath($datasetPath, 'Exact training dataset');
            $datasetHash = (string) ($manifest['dataset_hash'] ?? '');
            $datasetMetadata = $this->fileMetadata($datasetPath, 67108864);
            $actualDatasetHash = $datasetMetadata['sha256'];

            if (! is_string($actualDatasetHash) || ! hash_equals($datasetHash, $actualDatasetHash)) {
                throw new RuntimeException('The exact training dataset does not match the Python manifest dataset hash.');
            }

            $extension = strtolower(pathinfo($datasetPath, PATHINFO_EXTENSION));
            if (! in_array($extension, ['csv', 'parquet'], true)) {
                throw new RuntimeException('NFL numeric bundles support only exact CSV or Parquet training datasets.');
            }
            $datasetEntry = 'dataset/training-data.'.$extension;
            $files[$datasetEntry] = $datasetMetadata;
        }

        ksort($files);
        $bundle = [
            'bundle_version' => 2,
            'execution_format' => self::EXECUTION_FORMAT,
            'model_type' => 'nfl_tabular_bundle',
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

        try {
            foreach ($files as $entry => $metadata) {
                $source = match (true) {
                    $entry === $datasetEntry => $datasetPath,
                    default => $runDirectory.'/'.$entry,
                };
                $owned = $temporaryDirectory.'/inputs/'.$entry;
                File::ensureDirectoryExists(dirname($owned), 0700, true);
                NflTabularFiles::copy((string) $source, $owned, $metadata['bytes'], $metadata['sha256']);
            }
        } catch (Throwable $exception) {
            File::deleteDirectory($temporaryDirectory);
            throw $exception;
        }

        $zip = new ZipArchive;
        if ($zip->open($bundlePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            File::deleteDirectory($temporaryDirectory);

            throw new RuntimeException('Unable to create the NFL tabular model bundle.');
        }

        try {
            try {
                foreach (array_keys($files) as $entry) {
                    $sourcePath = $temporaryDirectory.'/inputs/'.$entry;

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
        } catch (Throwable $exception) {
            File::deleteDirectory($temporaryDirectory);

            throw $exception;
        }

        return [
            'path' => $bundlePath,
            'temporary_directory' => $temporaryDirectory,
            'manifest' => $manifest,
            'evaluation' => $evaluation,
            'bundle' => $bundle,
            'owned_dataset_path' => $datasetEntry !== null ? $temporaryDirectory.'/inputs/'.$datasetEntry : null,
            'owned_report_path' => $temporaryDirectory.'/inputs/evaluation.json',
            'storage_expectations' => [
                'artifact' => $this->fileMetadata($bundlePath, 205598720),
                'report' => $runFiles['evaluation.json'],
                ...($datasetEntry !== null ? ['dataset' => $files[$datasetEntry]] : []),
            ],
        ];
    }

    public function extractAndVerify(ModelArtifact $artifact, string $bundlePath): string
    {
        if (! Str::isUuid((string) $artifact->getKey()) || ! $this->isSha256($artifact->artifact_hash)) {
            throw new RuntimeException('NFL numeric cache identity requires an artifact UUID and SHA-256.');
        }
        $bundlePath = $this->filePath($bundlePath, 'Registered NFL tabular model bundle');
        NflTabularZipSafety::inspect($bundlePath, 19,
            67108864, 203423744);
        $zip = new ZipArchive;
        if ($zip->open($bundlePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The registered NFL tabular model bundle is not a readable ZIP archive.');
        }

        try {
            $bundle = $this->bundleManifest($zip);
            $this->assertBundleLineage($bundle, $artifact);
            $files = $this->bundleFiles($bundle);
            $this->assertZipInventory($zip, $files);
            $root = rtrim((string) config(
                'nfl_ml.bundle.extraction_directory',
                storage_path('app/ml/nfl-tabular/extracted'),
            ), '/').'/'.$artifact->id.'/'.$artifact->artifact_hash;
            $runRoot = $root.'/run';

            if (File::isDirectory($root)) {
                $this->verifyExtractedFiles($root, $files);

                return $runRoot;
            }

            $temporaryRoot = $root.'.tmp-'.Str::uuid();
            File::ensureDirectoryExists($temporaryRoot, 0700, true);

            try {
                foreach ($files as $entry => $metadata) {
                    $target = $this->extractedTarget($temporaryRoot, $entry);
                    File::ensureDirectoryExists(dirname($target), 0700, true);
                    $source = $zip->getStream($entry);
                    $destination = fopen($target, 'xb');
                    if (! is_resource($source) || ! is_resource($destination)) {
                        is_resource($source) && fclose($source);
                        is_resource($destination) && fclose($destination);

                        throw new RuntimeException("Unable to extract NFL tabular bundle entry [{$entry}].");
                    }
                    try {
                        if (! hash_equals($metadata['sha256'], NflTabularFiles::digest($source, $metadata['bytes'], $destination)) || ! fflush($destination)) {
                            throw new RuntimeException("Unable to extract exact NFL tabular bundle entry [{$entry}].");
                        }
                    } finally {
                        fclose($source);
                        fclose($destination);
                    }
                    @chmod($target, 0600);
                    $this->assertFileMetadata($target, $metadata, $entry);
                }

                File::ensureDirectoryExists(dirname($root), 0700, true);
                if (! @rename($temporaryRoot, $root)) {
                    if (! File::isDirectory($root)) {
                        throw new RuntimeException('Unable to publish the verified NFL tabular model extraction.');
                    }
                    File::deleteDirectory($temporaryRoot);
                }
            } catch (Throwable $exception) {
                File::deleteDirectory($temporaryRoot);

                throw $exception;
            }

            $this->verifyExtractedFiles($root, $files);

            return $runRoot;
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
            throw new RuntimeException('The Python NFL run artifact inventory is missing or invalid.');
        }
        $actualEntries = array_keys($inventory);
        $expectedEntries = self::REQUIRED_RUN_FILES;
        sort($actualEntries);
        sort($expectedEntries);
        if ($actualEntries !== $expectedEntries) {
            throw new RuntimeException('The Python NFL numeric run inventory must match the exact supported file set.');
        }
        foreach (self::REQUIRED_RUN_FILES as $entry) {
            if (! array_key_exists($entry, $inventory)) {
                throw new RuntimeException("The Python NFL run is missing required artifact [{$entry}].");
            }
        }

        $maximumEntries = 16;
        if ($maximumEntries < 1 || $maximumEntries > 256 || count($inventory) > $maximumEntries) {
            throw new RuntimeException('NFL run inventory file count exceeds the bounded supported limit.');
        }
        $verified = [];
        $total = 0;
        foreach ($inventory as $entry => $metadata) {
            $entry = $this->safeEntryName($entry);
            $metadata = $this->validFileMetadata($metadata, $entry);
            $total += $metadata['bytes'];
            if ($total > 134217728) {
                throw new RuntimeException('NFL run inventory exceeds the 128 MiB streaming content budget.');
            }
            $verified[$entry] = $metadata;
        }
        $actual = [];
        $visited = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($runDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (++$visited > 4 * $maximumEntries + 1 || $iterator->getDepth() > 8 || $file->isLink()) {
                throw new RuntimeException('NFL run traversal count/depth/link budget exceeded before artifact hashing.');
            }
            if ($file->isFile()) {
                $entry = str_replace('\\', '/', substr($file->getPathname(), strlen($runDirectory) + 1));
                if ($entry !== 'manifest.json') {
                    $actual[] = $entry;
                    if (count($actual) > $maximumEntries) {
                        throw new RuntimeException('NFL run actual file count exceeds the inventory budget before artifact hashing.');
                    }
                }
            } elseif (! $file->isDir()) {
                throw new RuntimeException('NFL run contains an unsupported filesystem object.');
            }
        }
        $declared = array_keys($verified);
        sort($declared);
        sort($actual);
        if ($actual !== $declared) {
            throw new RuntimeException('The Python NFL run directory does not exactly match its artifact inventory.');
        }
        foreach ($verified as $entry => $metadata) {
            $this->assertFileMetadata($runDirectory.'/'.$entry, $metadata, $entry);
        }

        return $verified;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $evaluation
     */
    private function assertRunLineage(array $manifest, array $evaluation): void
    {
        if (($manifest['execution_format'] ?? null) !== self::EXECUTION_FORMAT || ($manifest['manifest_version'] ?? null) !== 2
            || ($manifest['model_type'] ?? null) !== 'nfl_tabular_bundle'
            || ($manifest['model_version'] ?? null) !== 'nfl-tabular-v3'
            || ($manifest['blend_version'] ?? null) !== 'baseline-anchored-v2') {
            throw new RuntimeException('Only the NFL numeric-state execution format is supported; regenerate legacy runs.');
        }

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

        if (($bundle['bundle_version'] ?? null) !== 2 || ($bundle['execution_format'] ?? null) !== self::EXECUTION_FORMAT || ($bundle['model_type'] ?? null) !== 'nfl_tabular_bundle') {
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

        $expected = ['manifest.json', ...self::REQUIRED_RUN_FILES];
        $datasetEntry = $bundle['dataset_entry'] ?? null;
        if ($datasetEntry !== null) {
            if (! in_array($datasetEntry, ['dataset/training-data.csv', 'dataset/training-data.parquet'], true)) {
                throw new RuntimeException('Unsupported NFL dataset entry.');
            }
            $expected[] = $datasetEntry;
        }
        $actual = array_keys($files);
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('NFL numeric bundle inventory must match the exact supported file set.');
        }
        $maximumEntries = 18;
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

        if ($totalBytes > 203423744) {
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
            $name = $zip->getNameIndex($index);
            if (! is_string($name)) {
                throw new RuntimeException('The NFL tabular bundle contains an invalid entry.');
            }
            $name = $name === self::BUNDLE_MANIFEST ? self::BUNDLE_MANIFEST : $this->safeEntryName($name);
            if (isset($actual[$name])) {
                throw new RuntimeException("The NFL tabular bundle contains duplicate entry [{$name}].");
            }
            $stat = $zip->statIndex($index);
            if ($name !== self::BUNDLE_MANIFEST && (! is_array($stat) || ($stat['size'] ?? null) !== ($files[$name]['bytes'] ?? null))) {
                throw new RuntimeException('NFL ZIP entry length differs from its admitted descriptor.');
            }
            $actual[$name] = true;
        }
        $expected = [...array_keys($files), self::BUNDLE_MANIFEST];
        sort($expected);
        $actual = array_keys($actual);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('The NFL tabular bundle ZIP inventory does not match its manifest.');
        }
    }

    /**
     * @param  array<string, array{sha256: string, bytes: int}>  $files
     */
    private function verifyExtractedFiles(string $root, array $files): void
    {
        foreach ($files as $entry => $metadata) {
            $this->assertFileMetadata($this->extractedTarget($root, $entry), $metadata, $entry);
        }
        $expected = array_map(fn (string $entry): string => str_starts_with($entry, 'dataset/') ? $entry : 'run/'.$entry, array_keys($files));
        $actual = [];
        $visited = 0;
        if (is_link($root)) {
            throw new RuntimeException('NFL extraction root cannot be a symbolic link.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (++$visited > 24 || $iterator->getDepth() > 2 || $file->isLink()) {
                throw new RuntimeException('NFL extraction traversal exceeds its exact layout budget.');
            }
            $entry = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isFile()) {
                $actual[] = $entry;
            } elseif (! $file->isDir() || ! in_array($entry, ['run', 'run/models', 'run/calibrators', 'run/tuning', 'run/explanations', 'dataset'], true)) {
                throw new RuntimeException('NFL extraction contains an unsupported filesystem entry.');
            }
        }
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('NFL extracted layout must match the exact numeric inventory.');
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
            || $metadata['bytes'] > $this->entryMaximumBytes($entry)) {
            throw new RuntimeException("The NFL tabular artifact metadata for [{$entry}] is invalid.");
        }

        return ['sha256' => $metadata['sha256'], 'bytes' => $metadata['bytes']];
    }

    /**
     * @param  array{sha256: string, bytes: int}  $metadata
     */
    private function assertFileMetadata(string $path, array $metadata, string $entry): void
    {
        if (! is_file($path) || is_link($path) || filesize($path) !== $metadata['bytes']) {
            throw new RuntimeException("NFL tabular artifact [{$entry}] failed size verification.");
        }
        $hash = NflTabularFiles::hash($path, $metadata['bytes']);
        if (! is_string($hash) || ! hash_equals($metadata['sha256'], $hash)) {
            throw new RuntimeException("NFL tabular artifact [{$entry}] failed hash verification.");
        }
    }

    /**
     * @return array{sha256: string, bytes: int}
     */
    private function fileMetadata(string $path, int $maximumBytes): array
    {
        $path = $this->filePath($path, 'NFL tabular artifact');

        $size = filesize($path);
        if (! is_int($size) || $size < 0 || $size > min($maximumBytes, (int) config('nfl_ml.bundle.max_file_bytes', 268435456))) {
            throw new RuntimeException('NFL artifact file exceeds its declared file budget.');
        }

        return ['sha256' => NflTabularFiles::hash($path, $size), 'bytes' => $size];
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
     * @return array{0: array<string, mixed>, 1: array{sha256: string, bytes: int}}
     */
    private function jsonFileEvidence(string $path, string $label): array
    {
        $handle = fopen($this->filePath($path, $label), 'rb');
        if ($handle === false) {
            throw new RuntimeException($label.' cannot be opened.');
        }
        try {
            $raw = stream_get_contents($handle, self::MAX_JSON_BYTES + 1);
            if ($raw === false || strlen($raw) > self::MAX_JSON_BYTES || ! feof($handle)) {
                throw new RuntimeException($label.' exceeds its bounded JSON read budget.');
            }

            return [$this->decodeJson($raw, $label), ['sha256' => hash('sha256', $raw), 'bytes' => strlen($raw)]];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string|false $contents, string $label): array
    {
        if (! is_string($contents)) {
            throw new RuntimeException("{$label} could not be read.");
        }
        try {
            NflTabularJsonBudget::inspect($contents, self::MAX_JSON_BYTES, 50000, 24);
            $decoded = NflTabularJsonBudget::decode($contents, self::MAX_JSON_BYTES, 50000);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$label} contains invalid JSON.", previous: $exception);
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

    private function bundleManifest(ZipArchive $zip): array
    {
        $maxEntries = (int) config('nfl_ml.bundle.max_entries', 64);
        if ($zip->numFiles < 2 || $zip->numFiles > $maxEntries + 1) {
            throw new RuntimeException('The NFL tabular bundle file count exceeds the configured limit.');
        }
        $stat = $zip->statName(self::BUNDLE_MANIFEST);
        if ($stat === false || ! is_int($stat['size'] ?? null) || $stat['size'] > self::MAX_JSON_BYTES) {
            throw new RuntimeException('The NFL tabular bundle manifest exceeds its JSON budget.');
        }
        $contents = $zip->getFromName(self::BUNDLE_MANIFEST, self::MAX_JSON_BYTES + 1);
        if (! is_string($contents)
            || strlen($contents) > self::MAX_JSON_BYTES) {
            throw new RuntimeException('The NFL tabular bundle manifest is missing or too large.');
        }

        return $this->decodeJson($contents, 'NFL tabular bundle manifest');
    }

    private function extractedTarget(string $root, string $entry): string
    {
        return str_starts_with($entry, 'dataset/')
            ? $root.'/'.$entry
            : $root.'/run/'.$entry;
    }

    private function entryMaximumBytes(string $entry): int
    {
        if (in_array($entry, ['manifest.json', 'feature_schema.json', 'evaluation.json', 'prediction_example.json'], true)) {
            return self::MAX_JSON_BYTES;
        }
        if ($entry === 'explanations/shap_values.parquet') {
            return 67108864;
        }
        if (in_array($entry, ['tuning/optuna_provenance.json', 'explanations/global_shap_importance.json', 'explanations/xgboost_feature_importance.json'], true)) {
            return self::MAX_JSON_BYTES;
        }
        if (in_array($entry, self::REQUIRED_RUN_FILES, true)) {
            return 8388608;
        }

        return min(67108864, (int) config('nfl_ml.bundle.max_file_bytes', 268435456));
    }
}
