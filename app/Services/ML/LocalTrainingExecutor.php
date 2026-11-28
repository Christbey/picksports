<?php

namespace App\Services\ML;

use App\Support\BoundedTrainingSourceInventory;
use App\Support\Json\JsonAllocationBudget;
use App\Support\Processes\InferenceProcessEnvironment;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/** Executes training locally; dataset preparation and artifact registration belong to the caller. */
class LocalTrainingExecutor
{
    /**
     * @param  array<string, mixed>  $specification
     */
    public function execute(
        string $sport,
        string $runId,
        string $datasetPath,
        string $datasetHash,
        string $outputDirectory,
        array $specification,
        ?Closure $onOutput = null,
    ): string {
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

        // Preserve the explicit nonsecret producer label; source hashes remain independently verified.
        $codeVersion = $_ENV['PICKSPORTS_CODE_VERSION'] ?? $_SERVER['PICKSPORTS_CODE_VERSION'] ?? getenv('PICKSPORTS_CODE_VERSION');
        $result = Process::path((string) $specification['package_directory'])
            ->timeout((int) $specification['timeout_seconds'])
            ->env([
                ...InferenceProcessEnvironment::forPython((string) $specification['package_directory'].'/src'),
                'OMP_NUM_THREADS' => (string) $specification['threads'],
                'OPENBLAS_NUM_THREADS' => (string) $specification['threads'],
                'MKL_NUM_THREADS' => (string) $specification['threads'],
                'NUMEXPR_NUM_THREADS' => (string) $specification['threads'],
                'PYTHONPATH' => (string) $specification['package_directory'].'/src',
                'PYTHONUNBUFFERED' => '1',
                'PICKSPORTS_CODE_VERSION' => is_string($codeVersion) ? $codeVersion : false,
            ])
            ->run($command, $onOutput);

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                '%s Python training failed with exit code %s: %s',
                strtoupper($sport),
                (string) $result->exitCode(),
                Str::limit(trim($result->errorOutput() ?: $result->output()), 8000, ''),
            ));
        }

        $runDirectory = $outputDirectory.'/'.$runId;
        $this->assertPythonRun($runDirectory, $runId, $datasetHash, (string) $specification['model_type']);

        return $runDirectory;
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

        $files = BoundedTrainingSourceInventory::capture($runDirectory, static fn (string $relative): bool => $relative === 'manifest.json');
        $raw = $files->read('manifest.json');
        JsonAllocationBudget::inspect($raw, 2097152, 100000, 24, 'Training manifest JSON');
        $manifest = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
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
}
