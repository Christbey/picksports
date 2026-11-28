<?php

namespace App\Services\ML;

use App\Models\ModelRun;
use App\Models\ModelTrainingWorker;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbTabularDatasetAdmission;
use App\Services\MLB\MlbTabularFiles;
use App\Services\MLB\MlbTabularJsonBudget;
use App\Services\MLB\MlbTrainingCompletion;
use App\Support\BoundedTrainingSourceInventory;
use RuntimeException;

class RemoteTrainingPreparation
{
    public function __construct(private readonly MlArtifactStorage $storage) {}

    /** @param array<string, mixed> $specification @return array<string, mixed> */
    public function prepare(ModelRun $cycle, string $workerId, array $specification): array
    {
        $worker = $this->assertAvailable($workerId, $cycle->sport);
        $ownedProof = null;
        if ($cycle->sport === 'mlb') {
            if ($cycle->getConnection()->transactionLevel() !== 0 || $cycle->getConnection() !== (new ModelRun)->getConnection()) {
                throw new RuntimeException('MLB remote preparation requires a standalone coherent source boundary.');
            }
            app(MlbArtifactCohortSerializer::class)->assertSupported($cycle->getConnection());
            $admission = app(MlbTabularDatasetAdmission::class);
            $proof = $admission->inspect((string) data_get($cycle->metadata, 'dataset_path'));
            $admission->assertProof($proof);
            $proofJson = MlbTabularJsonBudget::encode($proof, 2097152, 100000, 24);
            $dataset = $this->storage->storeDataset($cycle, $cycle->id, (string) data_get($cycle->metadata, 'dataset_path'),
                expectedBytes: $proof['dataset_bytes'], expectedSha256: $proof['dataset_sha256']);
            $schemaPath = (string) $specification['schema_path'];
            clearstatcache(true, $schemaPath);
            $schemaBytes = filesize($schemaPath);
            if (! is_int($schemaBytes) || $schemaBytes < 1 || $schemaBytes > 65536) {
                throw new RuntimeException('MLB requested schema exceeds its fixed byte contract.');
            }
            $schema = $this->storage->storeDataset($cycle, $cycle->id, $schemaPath,
                expectedBytes: $schemaBytes, expectedSha256: MlbTabularFiles::hash($schemaPath, $schemaBytes));
            $ownedProof = $this->storeInspectedMlbProof($cycle, $proofJson);
        } else {
            $dataset = $this->storage->storeDataset($cycle, $cycle->id, (string) data_get($cycle->metadata, 'dataset_path'));
            $schema = $this->storage->storeDataset($cycle, $cycle->id, (string) $specification['schema_path']);
        }
        $metadata = [
            ...(array) $cycle->metadata,
            'remote_worker_id' => $worker->id,
            'stage' => 'remote_ready',
            'remote_inputs' => [
                'dataset' => $this->descriptor($dataset),
                'schema' => $this->descriptor($schema),
                ...($ownedProof === null ? [] : ['dataset_proof' => $this->descriptor($ownedProof)]),
            ],
            'remote_source_hash' => $this->sourceHash($cycle->sport, (string) $specification['package_directory']),
            ...($ownedProof === null ? [] : ['mlb_remote_protocol' => MlbTrainingCompletion::REMOTE_PROTOCOL]),
        ];
        $cycle->update(['metadata' => $metadata]);

        return ['status' => 'prepared', 'message' => 'Training inputs prepared for the assigned remote worker.', 'cycle_run_id' => $cycle->id, 'dataset_hash' => $dataset->sha256];
    }

    /** Canonical inspected proof, not an archive of the original sidecar bytes or a worker assertion. */
    private function storeInspectedMlbProof(ModelRun $cycle, string $json): MlStoredObject
    {
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new RuntimeException('Unable to allocate owned MLB dataset proof staging.');
        }
        try {
            $path = stream_get_meta_data($temporary)['uri'];
            if (fwrite($temporary, $json) !== strlen($json) || ! fflush($temporary) || ! chmod($path, 0400)) {
                throw new RuntimeException('Unable to seal owned MLB dataset proof staging.');
            }

            return $this->storage->storeReport($cycle, $cycle->id, $path, 'application/json',
                expectedBytes: strlen($json), expectedSha256: hash('sha256', $json));
        } finally {
            fclose($temporary);
        }
    }

    public function assertAvailable(string $workerId, string $sport): ModelTrainingWorker
    {
        if (! config('ml.remote.enabled', false)) {
            throw new RuntimeException('Remote training is disabled.');
        }
        $worker = ModelTrainingWorker::query()->findOrFail($workerId);
        if ($worker->revoked_at !== null || ! $worker->expires_at->isFuture() || ! in_array($sport, $worker->sports, true)) {
            throw new RuntimeException('Worker is unavailable or is not scoped to this sport.');
        }

        return $worker;
    }

    /** Match the source identity emitted by the existing Python pipelines. */
    public function sourceHash(string $sport, string $directory): string
    {
        $paths = ['Dockerfile', 'pyproject.toml', 'requirements.lock.txt'];
        $inventory = BoundedTrainingSourceInventory::capture($directory, static function (string $relative) use ($sport, $paths): bool {
            return in_array($relative, $paths, true)
                || ($sport === 'mlb' && str_starts_with($relative, 'config/') && pathinfo($relative, PATHINFO_EXTENSION) === 'yaml')
                || (str_starts_with($relative, 'src/') && pathinfo($relative, PATHINFO_EXTENSION) === 'py');
        });
        foreach ($sport === 'mlb' ? ['config' => 'yaml', 'src' => 'py'] : ['src' => 'py'] as $part => $extension) {
            $selected = array_filter(array_keys($inventory->descriptors()), static fn (string $relative): bool => str_starts_with($relative, $part.'/')
                && pathinfo($relative, PATHINFO_EXTENSION) === $extension);
            asort($selected, SORT_REGULAR);
            $paths = [...$paths, ...array_values($selected)];
        }

        return $inventory->concatenatedHash($paths);
    }

    /** @return array{disk:string,object_key:string,sha256:string,bytes:int} */
    private function descriptor(MlStoredObject $object): array
    {
        return ['disk' => $object->disk, 'object_key' => $object->objectKey, 'sha256' => $object->sha256, 'bytes' => $object->size];
    }
}
