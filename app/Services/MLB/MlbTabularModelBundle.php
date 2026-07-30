<?php

namespace App\Services\MLB;

use App\Models\ModelArtifact;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

class MlbTabularModelBundle
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
        $this->assertRunLineage($manifest, $evaluation);
        $files = [
            'manifest.json' => $this->fileMetadata($runDirectory.'/manifest.json'),
            ...$this->verifiedRunFiles($runDirectory, $manifest),
        ];
        $datasetEntry = null;

        if ($datasetPath !== null) {
            $datasetPath = $this->filePath($datasetPath, 'Exact training dataset');
            $actualDatasetHash = hash_file('sha256', $datasetPath);
            if (! is_string($actualDatasetHash)
                || ! hash_equals((string) $manifest['dataset_hash'], $actualDatasetHash)) {
                throw new RuntimeException('The exact training dataset does not match the Python manifest dataset hash.');
            }

            $extension = strtolower(pathinfo($datasetPath, PATHINFO_EXTENSION));
            $datasetEntry = 'dataset/training-data'.($extension !== '' ? '.'.$extension : '');
            $files[$datasetEntry] = $this->fileMetadata($datasetPath);
        }

        ksort($files);
        $bundle = [
            'bundle_version' => 1,
            'model_type' => 'mlb_tabular_bundle',
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
            'mlb_ml.bundle.staging_directory',
            storage_path('app/ml/mlb-tabular/staging'),
        ), '/').'/'.Str::uuid();
        File::ensureDirectoryExists($temporaryDirectory, 0700, true);
        $bundlePath = $temporaryDirectory.'/mlb-tabular-'.$manifest['artifact_id'].'.zip';
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

            throw new RuntimeException('Unable to create the MLB tabular model bundle.');
        }

        try {
            foreach (array_keys($files) as $entry) {
                $sourcePath = match (true) {
                    $entry === 'manifest.json' => $runDirectory.'/manifest.json',
                    $entry === $datasetEntry => $datasetPath,
                    default => $runDirectory.'/'.$entry,
                };
                if (! $zip->addFile((string) $sourcePath, $entry)) {
                    throw new RuntimeException("Unable to add [{$entry}] to the MLB tabular model bundle.");
                }
                $zip->setMtimeName($entry, 315532800);
            }
            if (! $zip->addFile($bundleManifestPath, self::BUNDLE_MANIFEST)) {
                throw new RuntimeException('Unable to add the MLB bundle manifest.');
            }
            $zip->setMtimeName(self::BUNDLE_MANIFEST, 315532800);
        } catch (Throwable $exception) {
            $zip->close();
            File::deleteDirectory($temporaryDirectory);

            throw $exception;
        }
        $zip->close();

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
        $bundlePath = $this->filePath($bundlePath, 'Registered MLB tabular model bundle');
        $zip = new ZipArchive;
        if ($zip->open($bundlePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The registered MLB tabular model bundle is not a readable ZIP archive.');
        }

        try {
            $bundle = $this->bundleManifest($zip);
            $this->assertBundleLineage($bundle, $artifact);
            $files = $this->bundleFiles($bundle);
            $this->assertZipInventory($zip, $files);
            $root = rtrim((string) config(
                'mlb_ml.bundle.extraction_directory',
                storage_path('app/ml/mlb-tabular/extracted'),
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

                        throw new RuntimeException("Unable to extract MLB tabular bundle entry [{$entry}].");
                    }
                    try {
                        if (stream_copy_to_stream($source, $destination) === false) {
                            throw new RuntimeException("Unable to extract MLB tabular bundle entry [{$entry}].");
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
                        throw new RuntimeException('Unable to publish the verified MLB tabular model extraction.');
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
            throw new RuntimeException('The Python MLB run artifact inventory is missing or invalid.');
        }
        foreach (self::REQUIRED_RUN_FILES as $entry) {
            if (! array_key_exists($entry, $inventory)) {
                throw new RuntimeException("The Python MLB run is missing required artifact [{$entry}].");
            }
        }

        $verified = [];
        foreach ($inventory as $entry => $metadata) {
            $entry = $this->safeEntryName($entry);
            $metadata = $this->validFileMetadata($metadata, $entry);
            $this->assertFileMetadata($runDirectory.'/'.$entry, $metadata, $entry);
            $verified[$entry] = $metadata;
        }

        $actual = collect(File::allFiles($runDirectory))
            ->map(function (\SplFileInfo $file) use ($runDirectory): string {
                if ($file->isLink()) {
                    throw new RuntimeException('Symbolic links are not allowed in an MLB tabular model run.');
                }

                return str_replace('\\', '/', substr($file->getPathname(), strlen($runDirectory) + 1));
            })
            ->reject(fn (string $entry): bool => $entry === 'manifest.json')
            ->sort()
            ->values()
            ->all();
        $declared = array_keys($verified);
        sort($declared);
        if ($actual !== $declared) {
            throw new RuntimeException('The Python MLB run directory does not exactly match its artifact inventory.');
        }

        return $verified;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $evaluation
     */
    private function assertRunLineage(array $manifest, array $evaluation): void
    {
        if (($manifest['model_type'] ?? null) !== 'mlb_tabular_bundle'
            || ($manifest['package'] ?? null) !== 'picksports_mlb_ml'
            || ($manifest['module'] ?? null) !== 'picksports_mlb_ml') {
            throw new RuntimeException('The Python run manifest is not an MLB tabular bundle.');
        }
        if (! is_string($manifest['model_run_id'] ?? null)
            || trim($manifest['model_run_id']) === ''
            || strlen($manifest['model_run_id']) > 191) {
            throw new RuntimeException('The Python run manifest [model_run_id] must be a stable identifier.');
        }
        if (! is_string($manifest['artifact_id'] ?? null) || ! Str::isUuid($manifest['artifact_id'])) {
            throw new RuntimeException('The Python run manifest [artifact_id] must be a UUID.');
        }
        foreach (['dataset_hash', 'feature_schema_hash', 'config_hash'] as $field) {
            if (! $this->isSha256($manifest[$field] ?? null)) {
                throw new RuntimeException("The Python run manifest [{$field}] must be a SHA-256 hash.");
            }
        }
        foreach (['manifest_version', 'model_version', 'feature_schema_version'] as $field) {
            if (! filled($manifest[$field] ?? null)) {
                throw new RuntimeException("The Python run manifest is missing [{$field}].");
            }
        }
        if (($evaluation['report_type'] ?? null) !== 'mlb_tabular_walk_forward_evaluation'
            || ($evaluation['model_type'] ?? null) !== 'mlb_tabular_bundle') {
            throw new RuntimeException('The Python MLB evaluation report type is invalid.');
        }

        $comparisons = [
            'model_run_id' => data_get($evaluation, 'model_run_id'),
            'artifact_id' => data_get($evaluation, 'artifact_id'),
            'dataset_hash' => data_get($evaluation, 'dataset.sha256'),
            'feature_schema_hash' => data_get($evaluation, 'feature_schema.sha256'),
        ];
        foreach ($comparisons as $field => $value) {
            if (! is_scalar($value)
                || ! hash_equals((string) $manifest[$field], (string) $value)) {
                throw new RuntimeException("The Python MLB evaluation [{$field}] does not match its run manifest.");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bundleManifest(ZipArchive $zip): array
    {
        $maxEntries = (int) config('mlb_ml.bundle.max_entries', 64);
        if ($zip->numFiles < 2 || $zip->numFiles > $maxEntries + 1) {
            throw new RuntimeException('The MLB tabular bundle file count exceeds the configured limit.');
        }
        $contents = $zip->getFromName(self::BUNDLE_MANIFEST);
        if (! is_string($contents)
            || strlen($contents) > (int) config('mlb_ml.bundle.max_file_bytes', 268_435_456)) {
            throw new RuntimeException('The MLB tabular bundle manifest is missing or too large.');
        }

        return $this->decodeJson($contents, 'MLB tabular bundle manifest');
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function assertBundleLineage(array $bundle, ModelArtifact $artifact): void
    {
        $expected = [
            'model_type' => 'mlb_tabular_bundle',
            'artifact_id' => (string) $artifact->id,
            'dataset_hash' => (string) $artifact->dataset_hash,
            'model_run_id' => (string) data_get($artifact->trainingRun?->metadata, 'python_model_run_id', ''),
        ];
        foreach ($expected as $field => $value) {
            if (! is_string($bundle[$field] ?? null)
                || ! hash_equals($value, (string) $bundle[$field])) {
                throw new RuntimeException("The MLB tabular bundle [{$field}] failed lineage verification.");
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
        if (! is_array($files) || array_is_list($files) || $files === []) {
            throw new RuntimeException('The MLB tabular bundle file inventory is invalid.');
        }
        $validated = [];
        $total = 0;
        foreach ($files as $entry => $metadata) {
            $entry = $this->safeEntryName($entry);
            $validated[$entry] = $this->validFileMetadata($metadata, $entry);
            $total += $validated[$entry]['bytes'];
        }
        if ($total > (int) config('mlb_ml.bundle.max_uncompressed_bytes', 1_073_741_824)) {
            throw new RuntimeException('The MLB tabular bundle exceeds the uncompressed size limit.');
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
                throw new RuntimeException('The MLB tabular bundle contains an invalid entry.');
            }
            $name = $this->safeEntryName($name);
            if (isset($actual[$name])) {
                throw new RuntimeException("The MLB tabular bundle contains duplicate entry [{$name}].");
            }
            $actual[$name] = true;
        }
        $expected = [...array_keys($files), self::BUNDLE_MANIFEST];
        sort($expected);
        $actual = array_keys($actual);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('The MLB tabular bundle ZIP inventory does not match its manifest.');
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
    }

    private function extractedTarget(string $root, string $entry): string
    {
        return str_starts_with($entry, 'dataset/')
            ? $root.'/'.$entry
            : $root.'/run/'.$entry;
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
            || $metadata['bytes'] > (int) config('mlb_ml.bundle.max_file_bytes', 268_435_456)) {
            throw new RuntimeException("The MLB tabular artifact metadata for [{$entry}] is invalid.");
        }

        return ['sha256' => $metadata['sha256'], 'bytes' => $metadata['bytes']];
    }

    /**
     * @param  array{sha256: string, bytes: int}  $metadata
     */
    private function assertFileMetadata(string $path, array $metadata, string $entry): void
    {
        if (! is_file($path) || is_link($path) || filesize($path) !== $metadata['bytes']) {
            throw new RuntimeException("MLB tabular artifact [{$entry}] failed size verification.");
        }
        $hash = hash_file('sha256', $path);
        if (! is_string($hash) || ! hash_equals($metadata['sha256'], $hash)) {
            throw new RuntimeException("MLB tabular artifact [{$entry}] failed hash verification.");
        }
    }

    /**
     * @return array{sha256: string, bytes: int}
     */
    private function fileMetadata(string $path): array
    {
        $path = $this->filePath($path, 'MLB tabular artifact');

        return ['sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)];
    }

    private function safeEntryName(mixed $entry): string
    {
        if (! is_string($entry)
            || $entry === ''
            || str_contains($entry, '\\')
            || str_starts_with($entry, '/')
            || preg_match('#(^|/)\.\.?(/|$)#', $entry) === 1) {
            throw new RuntimeException('The MLB tabular bundle contains an unsafe entry name.');
        }

        return $entry;
    }

    private function directoryPath(string $path): string
    {
        $path = $this->absolutePath($path);
        if (! File::isDirectory($path) || is_link($path)) {
            throw new RuntimeException('The completed MLB Python run directory does not exist.');
        }

        return rtrim($path, '/');
    }

    private function filePath(string $path, string $label): string
    {
        $path = $this->absolutePath($path);
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException("{$label} does not exist or is not a regular file.");
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFile(string $path, string $label): array
    {
        return $this->decodeJson(File::get($this->filePath($path, $label)), $label);
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
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
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
}
