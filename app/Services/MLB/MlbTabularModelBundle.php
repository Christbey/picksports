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
    private ?\WeakMap $validatedBundles = null;

    private const BUNDLE_MANIFEST = 'bundle.json';

    private const MAX_JSON_BYTES = 2097152;

    private const EXECUTION_FORMAT = 'picksports-tabular-numeric-v1';

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
    ];

    /** Fixed writer inventory; callers must still inspect actual bytes and lineage. */
    public static function requiredRunFiles(): array
    {
        return self::REQUIRED_RUN_FILES;
    }

    /** Preserve the existing relative=>SHA JSON output fingerprint with bounded fixed-run inspection. */
    public function runFingerprint(string $runDirectory): string
    {
        $directory = $this->directoryPath($runDirectory);
        $manifest = $this->jsonFile($directory.'/manifest.json', 'Python run manifest');
        $evaluation = $this->jsonFile($directory.'/evaluation.json', 'Python evaluation report');
        $this->assertRunLineage($manifest, $evaluation);
        $files = $this->verifiedRunFiles($directory, $manifest);
        $files['manifest.json'] = $this->fileMetadata($directory.'/manifest.json', self::MAX_JSON_BYTES);
        $hashes = [];
        foreach ($files as $relative => $entry) {
            $hashes[$relative] = $entry['sha256'];
        }
        $order = array_combine(array_keys($hashes), array_keys($hashes));
        asort($order, SORT_REGULAR);
        $sorted = [];
        foreach ($order as $relative) {
            $sorted[$relative] = $hashes[$relative];
        }

        return hash('sha256', json_encode($sorted, JSON_THROW_ON_ERROR));
    }

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
            'manifest.json' => $this->fileMetadata($runDirectory.'/manifest.json', self::MAX_JSON_BYTES),
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
            $files[$datasetEntry] = $this->fileMetadata($datasetPath, 67108864);
        }

        ksort($files);
        $bundle = [
            'bundle_version' => 1,
            'execution_format' => self::EXECUTION_FORMAT,
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

    public function prepareValidated(string $runDirectory, string $datasetPath): ValidatedMlbTabularBundle
    {
        $proof = app(MlbTabularDatasetAdmission::class)->inspect($this->absolutePath($datasetPath));
        $inputs = rtrim((string) config('mlb_ml.bundle.staging_directory', storage_path('app/ml/mlb-tabular/staging')), '/').'/inputs-'.Str::uuid();
        File::ensureDirectoryExists($inputs, 0700, true);
        $bundle = null;
        try {
            $ownedDataset = $inputs.'/training-data.csv';
            MlbTabularFiles::copy($this->absolutePath($datasetPath), $ownedDataset, $proof['dataset_bytes'], $proof['dataset_sha256']);
            $bundle = $this->create($runDirectory, $ownedDataset);
            $bundle['owned_inputs_directory'] = $inputs;
            $bundle['dataset_path'] = $ownedDataset;
            $bundle['report_path'] = $inputs.'/evaluation.json';
            $bundle['report_source_path'] = $this->directoryPath($runDirectory).'/evaluation.json';
            $this->verifyPreparedZip($bundle);
            $size = filesize($bundle['path']);
            if (! is_int($size) || $size > 1073741824 + self::MAX_JSON_BYTES + 1048576) {
                throw new RuntimeException('MLB prepared archive exceeds its streaming byte budget.');
            }
            $hash = MlbTabularFiles::hash($bundle['path'], $size);
            if (! chmod($bundle['path'], 0400)) {
                throw new RuntimeException('Unable to seal owned MLB archive staging bytes.');
            }
            $context = new ValidatedMlbTabularBundle($this, $bundle, $proof, $hash, $size);
            $this->validatedBundles ??= new \WeakMap;
            $this->validatedBundles[$context] = true;

            return $context;
        } catch (Throwable $exception) {
            File::deleteDirectory($inputs);
            if (is_array($bundle)) {
                File::deleteDirectory($bundle['temporary_directory']);
            }
            throw $exception;
        }
    }

    private function verifyPreparedZip(array $prepared): void
    {
        MlbTabularZipSafety::inspect($prepared['path'], (int) config('mlb_ml.bundle.max_entries', 64),
            (int) config('mlb_ml.bundle.max_file_bytes', 268435456), (int) config('mlb_ml.bundle.max_uncompressed_bytes', 1073741824));
        $zip = new ZipArchive;
        if ($zip->open($prepared['path'], ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Unable to inspect prepared MLB archive.');
        }
        try {
            $actualBundle = $this->bundleManifest($zip);
            $files = $this->bundleFiles($actualBundle);
            $this->assertZipInventory($zip, $files);
            if (app(MlbTabularDatasetProjector::class)->stableHash($actualBundle) !== app(MlbTabularDatasetProjector::class)->stableHash($prepared['bundle'])) {
                throw new RuntimeException('Prepared MLB archive manifest changed during inspection.');
            }
            foreach ($files as $entry => $metadata) {
                $stat = $zip->statName($entry);
                if ($stat === false || ($stat['size'] ?? null) !== $metadata['bytes']) {
                    throw new RuntimeException('Prepared MLB archive entry size differs from its inspected inventory.');
                }
                $stream = $zip->getStream($entry);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Unable to open prepared MLB archive entry.');
                }
                try {
                    if ($entry === 'evaluation.json') {
                        MlbTabularFiles::copyStream($stream, $prepared['report_path'], $metadata['bytes'], $metadata['sha256']);
                    } elseif (! hash_equals($metadata['sha256'], MlbTabularFiles::digest($stream, $metadata['bytes']))) {
                        throw new RuntimeException('Prepared MLB archive entry failed exact byte/hash inspection.');
                    }
                } finally {
                    fclose($stream);
                }
            }
            $manifest = $this->decodeJson($zip->getFromName('manifest.json', self::MAX_JSON_BYTES + 1), 'Archived MLB run manifest');
            $report = $this->jsonFile($prepared['report_path'], 'Archived MLB evaluation report');
            foreach ([[$manifest, $prepared['manifest']], [$report, $prepared['evaluation']]] as [$actual, $expected]) {
                if (app(MlbTabularDatasetProjector::class)->stableHash($actual) !== app(MlbTabularDatasetProjector::class)->stableHash($expected)) {
                    throw new RuntimeException('MLB parsed run/report changed while its bundle was being created.');
                }
            }
            $this->assertRunLineage($manifest, $report);
        } finally {
            $zip->close();
        }
    }

    public function assertValidatedOwner(ValidatedMlbTabularBundle $context): void
    {
        if (! ($this->validatedBundles[$context] ?? false)) {
            throw new RuntimeException('MLB staging requires the original opaque inspected bundle context.');
        }
    }

    public function releaseValidated(ValidatedMlbTabularBundle $context): void
    {
        $this->assertValidatedOwner($context);
        unset($this->validatedBundles[$context]);
        foreach (['temporary_directory', 'owned_inputs_directory'] as $directory) {
            if (is_dir($context->bundle[$directory]) && ! File::deleteDirectory($context->bundle[$directory])) {
                throw new RuntimeException('Owned MLB bundle staging cleanup failed; staged objects remain unregistered.');
            }
        }
    }

    public function extractAndVerify(ModelArtifact $artifact, string $bundlePath): string
    {
        $bundlePath = $this->filePath($bundlePath, 'Registered MLB tabular model bundle');
        MlbTabularZipSafety::inspect($bundlePath, (int) config('mlb_ml.bundle.max_entries', 64),
            (int) config('mlb_ml.bundle.max_file_bytes', 268435456), (int) config('mlb_ml.bundle.max_uncompressed_bytes', 1073741824));
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
                        if (! hash_equals($metadata['sha256'], MlbTabularFiles::digest($source, $metadata['bytes'], $destination)) || ! fflush($destination)) {
                            throw new RuntimeException("Unable to extract exact MLB tabular bundle entry [{$entry}].");
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
        $actualEntries = array_keys($inventory);
        $expectedEntries = self::REQUIRED_RUN_FILES;
        sort($actualEntries);
        sort($expectedEntries);
        if ($actualEntries !== $expectedEntries) {
            throw new RuntimeException('The Python MLB numeric run inventory must match the exact supported file set.');
        }
        foreach (self::REQUIRED_RUN_FILES as $entry) {
            if (! array_key_exists($entry, $inventory)) {
                throw new RuntimeException("The Python MLB run is missing required artifact [{$entry}].");
            }
        }

        $maximumEntries = (int) config('mlb_ml.bundle.max_entries', 64);
        if ($maximumEntries < 1 || $maximumEntries > 256 || count($inventory) > $maximumEntries) {
            throw new RuntimeException('MLB run inventory file count exceeds the bounded supported limit.');
        }
        $verified = [];
        $total = 0;
        foreach ($inventory as $entry => $metadata) {
            $entry = $this->safeEntryName($entry);
            $metadata = $this->validFileMetadata($metadata, $entry);
            $total += $metadata['bytes'];
            if ($total > 83886080) {
                throw new RuntimeException('MLB run inventory exceeds the eighty MiB streaming content budget.');
            }
            $verified[$entry] = $metadata;
        }
        $actual = [];
        $visited = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($runDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (++$visited > 4 * $maximumEntries + 1 || $iterator->getDepth() > 8 || $file->isLink()) {
                throw new RuntimeException('MLB run traversal count/depth/link budget exceeded before artifact hashing.');
            }
            if ($file->isFile()) {
                $entry = str_replace('\\', '/', substr($file->getPathname(), strlen($runDirectory) + 1));
                if ($entry !== 'manifest.json') {
                    $actual[] = $entry;
                    if (count($actual) > $maximumEntries) {
                        throw new RuntimeException('MLB run actual file count exceeds the inventory budget before artifact hashing.');
                    }
                }
            } elseif (! $file->isDir()) {
                throw new RuntimeException('MLB run contains an unsupported filesystem object.');
            }
        }
        $declared = array_keys($verified);
        sort($declared);
        sort($actual);
        if ($actual !== $declared) {
            throw new RuntimeException('The Python MLB run directory does not exactly match its artifact inventory.');
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
        if (($manifest['execution_format'] ?? null) !== self::EXECUTION_FORMAT
            || ($manifest['model_type'] ?? null) !== 'mlb_tabular_bundle'
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
        $stat = $zip->statName(self::BUNDLE_MANIFEST);
        if ($stat === false || ! is_int($stat['size'] ?? null) || $stat['size'] > self::MAX_JSON_BYTES) {
            throw new RuntimeException('The MLB tabular bundle manifest exceeds its JSON budget.');
        }
        $contents = $zip->getFromName(self::BUNDLE_MANIFEST, self::MAX_JSON_BYTES + 1);
        if (! is_string($contents)
            || strlen($contents) > self::MAX_JSON_BYTES) {
            throw new RuntimeException('The MLB tabular bundle manifest is missing or too large.');
        }

        return $this->decodeJson($contents, 'MLB tabular bundle manifest');
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function assertBundleLineage(array $bundle, ModelArtifact $artifact): void
    {
        if (($bundle['execution_format'] ?? null) !== self::EXECUTION_FORMAT) {
            throw new RuntimeException('Registered MLB bundle execution format is unsupported; legacy bytes remain retained.');
        }
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
        $expected = [...self::REQUIRED_RUN_FILES, 'manifest.json'];
        $datasetEntry = $bundle['dataset_entry'] ?? null;
        if ($datasetEntry !== null) {
            if (! is_string($datasetEntry) || ! preg_match('/^dataset\/training-data\.[a-z0-9]+$/D', $datasetEntry)) {
                throw new RuntimeException('The MLB numeric archive dataset entry is unsupported.');
            }
            $expected[] = $datasetEntry;
        }
        $actual = array_keys($files);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('The MLB numeric archive inventory must match its fixed run and dataset files.');
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
    private function entryMaximumBytes(string $entry): int
    {
        if (in_array($entry, ['manifest.json', 'feature_schema.json', 'evaluation.json', 'prediction_example.json'], true)) {
            return self::MAX_JSON_BYTES;
        }
        if (in_array($entry, self::REQUIRED_RUN_FILES, true)) {
            return 8388608;
        }

        return min(67108864, (int) config('mlb_ml.bundle.max_file_bytes', 268435456));
    }

    private function validFileMetadata(mixed $metadata, string $entry): array
    {
        if (! is_array($metadata)
            || ! $this->isSha256($metadata['sha256'] ?? null)
            || ! is_int($metadata['bytes'] ?? null)
            || $metadata['bytes'] < 0
            || $metadata['bytes'] > $this->entryMaximumBytes($entry)) {
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
        $hash = MlbTabularFiles::hash($path, $metadata['bytes']);
        if (! is_string($hash) || ! hash_equals($metadata['sha256'], $hash)) {
            throw new RuntimeException("MLB tabular artifact [{$entry}] failed hash verification.");
        }
    }

    /**
     * @return array{sha256: string, bytes: int}
     */
    private function fileMetadata(string $path, int $maximumBytes): array
    {
        $path = $this->filePath($path, 'MLB tabular artifact');

        $size = filesize($path);
        if (! is_int($size) || $size < 0 || $size > min($maximumBytes, (int) config('mlb_ml.bundle.max_file_bytes', 268435456))) {
            throw new RuntimeException('MLB artifact file exceeds its declared file budget.');
        }

        return ['sha256' => MlbTabularFiles::hash($path, $size), 'bytes' => $size];
    }

    private function safeEntryName(mixed $entry): string
    {
        return MlbTabularZipSafety::entryName($entry);
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
        $handle = fopen($this->filePath($path, $label), 'rb');
        if ($handle === false) {
            throw new RuntimeException($label.' cannot be opened.');
        }
        try {
            $raw = stream_get_contents($handle, self::MAX_JSON_BYTES + 1);
            if ($raw === false || strlen($raw) > self::MAX_JSON_BYTES || ! feof($handle)) {
                throw new RuntimeException($label.' exceeds its bounded JSON read budget.');
            }

            return $this->decodeJson($raw, $label);
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
            MlbTabularJsonBudget::inspect($contents, self::MAX_JSON_BYTES, 50000, 24);
            $decoded = MlbTabularJsonBudget::decode($contents, self::MAX_JSON_BYTES, 50000);
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
