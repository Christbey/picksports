<?php

namespace App\Services\ML;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\MLB\MlbTabularModelRunRegistrar;
use App\Services\NFL\NflTabularModelRunRegistrar;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WeeklyChallengerTrainingService
{
    public function __construct(
        private readonly MlbTabularModelRunRegistrar $mlbRegistrar,
        private readonly NflTabularModelRunRegistrar $nflRegistrar,
        private readonly ShadowArtifactSelector $shadowArtifacts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(
        string $sport,
        bool $force = false,
        bool $allowPromotion = true,
        bool $retainWorkDirectory = false,
    ): array {
        $specification = $this->specification($sport);
        if (! (bool) $specification['enabled'] && ! $force) {
            return [
                'status' => 'disabled',
                'message' => strtoupper($sport).' weekly model training is disabled.',
            ];
        }

        $lock = Cache::lock(
            "ml:weekly-challenger-training:{$sport}",
            (int) $specification['lock_seconds'],
        );
        if (! $lock->get()) {
            throw new RuntimeException(strtoupper($sport).' weekly model training is already running.');
        }

        try {
            return $this->runLocked(
                $sport,
                $specification,
                $force,
                $allowPromotion,
                $retainWorkDirectory,
            );
        } finally {
            $this->release($lock);
        }
    }

    /**
     * @param  array<string, mixed>  $specification
     * @return array<string, mixed>
     */
    private function runLocked(
        string $sport,
        array $specification,
        bool $force,
        bool $allowPromotion,
        bool $retainWorkDirectory,
    ): array {
        $cycleId = (string) Str::uuid();
        $modelRunId = (string) Str::uuid();
        $cycleDirectory = rtrim((string) $specification['work_directory'], '/').'/'.$cycleId;
        $datasetPath = $cycleDirectory.'/training-data.csv';
        $outputDirectory = $cycleDirectory.'/artifacts';
        $expectedRunDirectory = $outputDirectory.'/'.$modelRunId;
        $this->pruneWorkDirectories(
            (string) $specification['work_directory'],
            (int) $specification['retention_days'],
        );
        $cycle = $this->startCycle($cycleId, $sport, $specification);

        File::ensureDirectoryExists($cycleDirectory, 0700, true);

        try {
            $this->updateCycle($cycle, 'promotion_evaluation');
            $active = $this->shadowArtifacts->activeChallenger(
                $sport,
                (string) $specification['model_type'],
            );
            if ($active) {
                $this->evaluatePromotion(
                    $active,
                    $allowPromotion && (bool) $specification['auto_promote'],
                );
                $active->refresh();
                if ($active->status === 'promoted') {
                    $this->shadowArtifacts->deactivateChallenger($active, 'promoted');
                    $active = null;
                } elseif ($this->liveEvidencePassed($active) && $active->status !== 'promotion_eligible') {
                    $this->shadowArtifacts->deactivateChallenger($active, 'live_evaluation_blocked');
                    $active = null;
                }
            }

            $this->updateCycle($cycle, 'historical_refresh');
            $this->refreshHistoricalRows($sport, $specification);

            $this->updateCycle($cycle, 'dataset_export');
            $this->exportDataset($sport, $datasetPath, $specification);
            $this->assertDataset($datasetPath);
            $datasetHash = hash_file('sha256', $datasetPath);
            if (! is_string($datasetHash)) {
                throw new RuntimeException('Unable to hash the exported training dataset.');
            }

            $fingerprint = $this->trainingFingerprint($datasetHash, $specification);
            $this->updateCycle($cycle, 'idempotency_check', [
                'dataset_hash' => $datasetHash,
                'training_fingerprint' => $fingerprint,
            ]);
            $duplicate = ModelArtifact::query()
                ->where('sport', $sport)
                ->where('model_type', $specification['model_type'])
                ->get()
                ->first(fn (ModelArtifact $artifact): bool => hash_equals(
                    $fingerprint,
                    (string) data_get($artifact->metrics, 'automation.training_fingerprint', ''),
                ));
            if ($duplicate && ! $force) {
                $this->completeCycle($cycle, 'skipped', [
                    'reason' => 'training_fingerprint_unchanged',
                    'artifact_id' => $duplicate->id,
                    'dataset_hash' => $datasetHash,
                    'training_fingerprint' => $fingerprint,
                ]);
                File::deleteDirectory($cycleDirectory);

                return [
                    'status' => 'skipped',
                    'message' => 'No trusted data, schema, configuration, or training-code changes were found.',
                    'cycle_run_id' => $cycle->id,
                    'artifact_id' => $duplicate->id,
                    'dataset_hash' => $datasetHash,
                    'training_fingerprint' => $fingerprint,
                    'shadow_artifact_id' => $active?->id,
                ];
            }

            $this->updateCycle($cycle, 'python_training');
            $this->runPythonTraining(
                $sport,
                $modelRunId,
                $datasetPath,
                $datasetHash,
                $outputDirectory,
                $specification,
            );
            $this->assertPythonRun(
                $expectedRunDirectory,
                $modelRunId,
                $datasetHash,
                (string) $specification['model_type'],
            );

            $this->updateCycle($cycle, 'artifact_registration');
            $artifact = $this->register(
                $sport,
                $expectedRunDirectory,
                $datasetPath,
            );
            if ($artifact->training_run_id !== $modelRunId) {
                throw new RuntimeException('Registered training-run lineage does not match the requested Python run ID.');
            }

            $this->mergeAutomationMetrics($artifact, [
                'weekly_training' => true,
                'cycle_run_id' => $cycle->id,
                'training_fingerprint' => $fingerprint,
                'dataset_hash' => $datasetHash,
                'trained_at' => now()->toIso8601String(),
            ]);

            $this->updateCycle($cycle, 'offline_promotion_evaluation', [
                'artifact_id' => $artifact->id,
                'model_run_id' => $modelRunId,
            ]);
            $this->evaluatePromotion($artifact, false);
            $artifact->refresh();

            if ($active === null && $artifact->status === 'promotion_eligible') {
                $this->shadowArtifacts->activateChallenger($artifact, [
                    'reason' => 'offline_gates_passed',
                    'cycle_run_id' => $cycle->id,
                    'training_fingerprint' => $fingerprint,
                ]);
                $active = $artifact->refresh();
            }

            $this->completeCycle($cycle, 'completed', [
                'artifact_id' => $artifact->id,
                'model_run_id' => $modelRunId,
                'dataset_hash' => $datasetHash,
                'training_fingerprint' => $fingerprint,
                'shadow_artifact_id' => $active?->id,
                'artifact_status' => $artifact->status,
            ]);

            if (! $retainWorkDirectory) {
                File::deleteDirectory($cycleDirectory);
            }

            return [
                'status' => 'completed',
                'message' => strtoupper($sport).' weekly challenger trained, registered, and evaluated.',
                'cycle_run_id' => $cycle->id,
                'model_run_id' => $modelRunId,
                'artifact_id' => $artifact->id,
                'dataset_hash' => $datasetHash,
                'training_fingerprint' => $fingerprint,
                'shadow_artifact_id' => $active?->id,
            ];
        } catch (Throwable $exception) {
            $this->failCycle($cycle, $exception);
            $this->writeFailureReport($cycleDirectory, $cycle, $exception);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $specification
     */
    private function startCycle(string $id, string $sport, array $specification): ModelRun
    {
        return ModelRun::query()->create([
            'id' => $id,
            'sport' => $sport,
            'run_type' => 'weekly_training_cycle',
            'model_version' => (string) $specification['model_version'],
            'feature_version' => (string) $specification['feature_version'],
            'blend_version' => 'automated-weekly-v1',
            'config_hash' => hash('sha256', json_encode($specification, JSON_THROW_ON_ERROR)),
            'code_version' => $this->codeVersion(),
            'parameters' => [
                'schema_path' => $specification['schema_path'],
                'auto_promote' => $specification['auto_promote'],
            ],
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'stage' => 'starting',
                'host' => gethostname() ?: null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function updateCycle(ModelRun $cycle, string $stage, array $metadata = []): void
    {
        $cycle->forceFill([
            'metadata' => [
                ...(array) $cycle->metadata,
                ...$metadata,
                'stage' => $stage,
                'stage_updated_at' => now()->toIso8601String(),
            ],
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function completeCycle(ModelRun $cycle, string $status, array $metadata): void
    {
        $cycle->forceFill([
            'status' => $status,
            'completed_at' => now(),
            'metadata' => [
                ...(array) $cycle->metadata,
                ...$metadata,
                'stage' => $status,
            ],
        ])->save();
    }

    private function failCycle(ModelRun $cycle, Throwable $exception): void
    {
        $cycle->forceFill([
            'status' => 'failed',
            'completed_at' => now(),
            'metadata' => [
                ...(array) $cycle->metadata,
                'failed_stage' => data_get($cycle->metadata, 'stage'),
                'exception' => $exception::class,
                'error' => Str::limit($exception->getMessage(), 8000, ''),
                'stage' => 'failed',
            ],
        ])->save();
    }

    private function writeFailureReport(string $directory, ModelRun $cycle, Throwable $exception): void
    {
        File::ensureDirectoryExists($directory, 0700, true);
        File::put($directory.'/failure.json', json_encode([
            'cycle_run_id' => $cycle->id,
            'sport' => $cycle->sport,
            'failed_stage' => data_get($cycle->metadata, 'stage'),
            'exception' => $exception::class,
            'error' => Str::limit($exception->getMessage(), 8000, ''),
            'failed_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, true);
        @chmod($directory.'/failure.json', 0600);
    }

    /**
     * @param  array<string, mixed>  $specification
     */
    private function refreshHistoricalRows(string $sport, array $specification): void
    {
        if ($sport !== 'nfl') {
            return;
        }

        $exitCode = Artisan::call('nfl:backfill-historical-predictions', [
            '--season' => (int) $specification['to_season'],
            '--season-type' => 2,
            '--profile' => (string) $specification['profile'],
            '--only-missing-profile' => true,
            '--regrade' => true,
        ]);
        if ($exitCode !== 0) {
            throw new RuntimeException('NFL current-season historical reconstruction failed: '.Artisan::output());
        }
    }

    /**
     * @param  array<string, mixed>  $specification
     */
    private function exportDataset(string $sport, string $datasetPath, array $specification): void
    {
        $arguments = $sport === 'mlb'
            ? [
                '--season' => range(
                    (int) $specification['from_season'],
                    (int) $specification['to_season'],
                ),
                '--path' => $datasetPath,
            ]
            : [
                '--from-season' => (int) $specification['from_season'],
                '--to-season' => (int) $specification['to_season'],
                '--feature-version' => (string) $specification['feature_version'],
                '--profile' => (string) $specification['profile'],
                '--path' => $datasetPath,
            ];
        $exitCode = Artisan::call("{$sport}:export-training-data", $arguments);
        if ($exitCode !== 0) {
            throw new RuntimeException(strtoupper($sport).' trusted training export failed: '.Artisan::output());
        }
    }

    private function assertDataset(string $path): void
    {
        clearstatcache(true, $path);
        if (! File::isFile($path) || File::size($path) <= 1) {
            throw new RuntimeException('Trusted training export did not produce a non-empty dataset.');
        }

        $handle = fopen($path, 'rb');
        $header = is_resource($handle) ? fgetcsv($handle) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (! is_array($header) || ! in_array('target_home_win', $header, true)) {
            throw new RuntimeException('Trusted training export is missing the stable home-win target.');
        }
    }

    /**
     * @param  array<string, mixed>  $specification
     */
    private function runPythonTraining(
        string $sport,
        string $runId,
        string $datasetPath,
        string $datasetHash,
        string $outputDirectory,
        array $specification,
    ): void {
        File::ensureDirectoryExists($outputDirectory, 0700, true);
        $command = [
            ...(array) $specification['python_command'],
            'train',
            '--input',
            $datasetPath,
            '--schema',
            (string) $specification['schema_path'],
            '--expected-dataset-sha256',
            $datasetHash,
            '--output-dir',
            $outputDirectory,
            '--run-id',
            $runId,
        ];

        if ($sport === 'nfl') {
            $command[] = (bool) $specification['tune'] ? '--tune' : '--no-tune';
            $command[] = (bool) $specification['explain'] ? '--explain' : '--no-explain';
        }

        $result = Process::path((string) $specification['package_directory'])
            ->timeout((int) $specification['timeout_seconds'])
            ->env([
                'OMP_NUM_THREADS' => (string) $specification['threads'],
                'OPENBLAS_NUM_THREADS' => (string) $specification['threads'],
                'MKL_NUM_THREADS' => (string) $specification['threads'],
                'NUMEXPR_NUM_THREADS' => (string) $specification['threads'],
                'PYTHONPATH' => (string) $specification['package_directory'].'/src',
                'PYTHONUNBUFFERED' => '1',
            ])
            ->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                '%s Python training failed with exit code %s: %s',
                strtoupper($sport),
                (string) $result->exitCode(),
                Str::limit(trim($result->errorOutput() ?: $result->output()), 8000, ''),
            ));
        }
    }

    private function assertPythonRun(
        string $runDirectory,
        string $runId,
        string $datasetHash,
        string $modelType,
    ): void {
        $manifestPath = $runDirectory.'/manifest.json';
        if (! File::isFile($manifestPath)) {
            throw new RuntimeException('Python training did not produce the expected immutable run directory.');
        }

        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        foreach ([
            'model_run_id' => $runId,
            'dataset_hash' => $datasetHash,
            'model_type' => $modelType,
        ] as $key => $expected) {
            if (! is_string($manifest[$key] ?? null)
                || ! hash_equals($expected, (string) $manifest[$key])) {
                throw new RuntimeException("Python manifest {$key} does not match the requested training lineage.");
            }
        }
    }

    private function register(string $sport, string $runDirectory, string $datasetPath): ModelArtifact
    {
        return $sport === 'mlb'
            ? $this->mlbRegistrar->register($runDirectory, $datasetPath)
            : $this->nflRegistrar->register($runDirectory, $datasetPath);
    }

    private function evaluatePromotion(ModelArtifact $artifact, bool $promote): void
    {
        $arguments = ['artifact' => $artifact->id];
        if ($promote) {
            $arguments['--promote'] = true;
        }
        $exitCode = Artisan::call('sports:evaluate-model-promotion', $arguments);
        if ($exitCode !== 0) {
            throw new RuntimeException('Model promotion evaluation failed: '.Artisan::output());
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function mergeAutomationMetrics(ModelArtifact $artifact, array $metadata): void
    {
        $metrics = (array) $artifact->metrics;
        $metrics['automation'] = [
            ...(array) ($metrics['automation'] ?? []),
            ...$metadata,
        ];
        $artifact->forceFill(['metrics' => $metrics])->save();
    }

    private function liveEvidencePassed(ModelArtifact $artifact): bool
    {
        return (bool) data_get($artifact->promotion_decision, 'live_shadow_evidence.passed', false);
    }

    /**
     * @param  array<string, mixed>  $specification
     */
    private function trainingFingerprint(string $datasetHash, array $specification): string
    {
        return hash('sha256', json_encode([
            'dataset_hash' => $datasetHash,
            'schema_hash' => hash_file('sha256', (string) $specification['schema_path']),
            'package_source_hash' => $this->directoryHash((string) $specification['package_directory']),
            'python_command' => $specification['python_command'],
            'tune' => $specification['tune'],
            'explain' => $specification['explain'],
        ], JSON_THROW_ON_ERROR));
    }

    private function directoryHash(string $directory): string
    {
        $files = collect(File::allFiles($directory))
            ->filter(fn (\SplFileInfo $file): bool => in_array(
                strtolower($file->getExtension()),
                ['py', 'yaml', 'yml', 'toml', 'txt'],
                true,
            ))
            ->sortBy(fn (\SplFileInfo $file): string => $file->getRelativePathname())
            ->mapWithKeys(fn (\SplFileInfo $file): array => [
                $file->getRelativePathname() => hash_file('sha256', $file->getPathname()),
            ])
            ->all();

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function specification(string $sport): array
    {
        if (! in_array($sport, ['mlb', 'nfl'], true)) {
            throw new RuntimeException('Weekly model training supports only MLB and NFL.');
        }

        $config = (array) config("{$sport}_ml.weekly_training", []);
        $season = $sport === 'nfl'
            ? (now()->month <= 2 ? now()->year - 1 : now()->year)
            : now()->year;
        $historySeasons = max(2, (int) ($config['history_seasons'] ?? ($sport === 'mlb' ? 6 : 10)));

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'auto_promote' => (bool) ($config['auto_promote'] ?? false),
            'model_type' => "{$sport}_tabular_bundle",
            'model_version' => $sport === 'nfl' ? 'nfl-tabular-v2' : 'mlb-tabular-v1',
            'feature_version' => (string) ($config['feature_version'] ?? ($sport === 'nfl'
                ? 'nfl-pregame-ml-v3'
                : 'mlb-pregame-ml-v1')),
            'profile' => (string) ($config['profile'] ?? 'full-historical'),
            'from_season' => max(2000, (int) ($config['from_season'] ?? ($season - $historySeasons + 1))),
            'to_season' => $season,
            'schema_path' => (string) ($config['schema_path']
                ?? base_path("ml/{$sport}/config/feature_schema.yaml")),
            'package_directory' => (string) ($config['package_directory']
                ?? base_path("ml/{$sport}")),
            'python_command' => (array) ($config['python_command']
                ?? config("{$sport}_ml.process.command", ['python3', '-m', "picksports_{$sport}_ml"])),
            'work_directory' => (string) ($config['work_directory']
                ?? storage_path("app/ml/automated-training/{$sport}")),
            'timeout_seconds' => max(300, (int) ($config['timeout_seconds'] ?? 14_400)),
            'lock_seconds' => max(600, (int) ($config['lock_seconds'] ?? 18_000)),
            'threads' => max(1, (int) ($config['threads'] ?? 2)),
            'tune' => (bool) ($config['tune'] ?? false),
            'explain' => (bool) ($config['explain'] ?? false),
            'retention_days' => max(1, (int) ($config['retention_days'] ?? 14)),
        ];
    }

    private function pruneWorkDirectories(string $root, int $retentionDays): void
    {
        if (! File::isDirectory($root)) {
            return;
        }

        $cutoff = now()->subDays($retentionDays)->getTimestamp();
        foreach (File::directories($root) as $directory) {
            $modifiedAt = File::lastModified($directory);
            if ($modifiedAt < $cutoff) {
                File::deleteDirectory($directory);
            }
        }
    }

    private function codeVersion(): ?string
    {
        $head = base_path('.git/HEAD');
        if (! File::isFile($head)) {
            return null;
        }

        $value = trim(File::get($head));
        if (! str_starts_with($value, 'ref: ')) {
            return preg_match('/^[a-f0-9]{40,64}$/i', $value) === 1 ? $value : null;
        }

        $reference = trim(substr($value, 5));
        $referencePath = base_path('.git/'.$reference);
        if (File::isFile($referencePath)) {
            $commit = trim(File::get($referencePath));

            return preg_match('/^[a-f0-9]{40,64}$/i', $commit) === 1 ? $commit : null;
        }

        return null;
    }

    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // The bounded lease still prevents an abandoned permanent lock.
        }
    }
}
