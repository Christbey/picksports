<?php

namespace App\Services\MLB;

use App\Exceptions\ExistingArtifactOwnershipRefused;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\MlArtifactStorage;
use App\Services\Sports\Elo\EloSqlClockPolicy;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use WeakMap;

/** Pointer relocation only. Staged objects remain as auditable orphans if the SQL commit refuses. */
final class MlbExistingArtifactStorage
{
    private WeakMap $prepared;

    public function __construct(private readonly MlbTabularArtifactAdmission $artifacts,
        private readonly MlbTabularSourceFence $sources, private readonly MlbArtifactCohortSerializer $cohorts,
        private readonly MlbArtifactCohortState $state, private readonly EloSqlClockPolicy $clocks,
        private readonly MlArtifactStorage $storage)
    {
        $this->prepared = new WeakMap;
    }

    public function archive(ModelArtifact $input): ModelArtifact
    {
        return $this->commit($this->prepare($input));
    }

    /** Cache hint relocation is not archival upload, training approval or a ModelRun mutation. */
    public function materializeArtifact(ModelArtifact $input, bool $persistCachePath, int $maximumBytes): string
    {
        return $this->refused(function () use ($input, $persistCachePath, $maximumBytes): string {
            $context = $this->discover($input);
            $initial = $context['admission'];
            $artifact = $initial['artifact'];
            $proof = $initial['proof'];
            if ($maximumBytes < 0 || $maximumBytes > 1073741824 || $proof['artifact_bytes'] > min($maximumBytes, 268435456)) {
                throw new RuntimeException('MLB cache content exceeds its existing bounded archive policy.');
            }
            $path = filled($artifact->artifact_disk) && filled($artifact->artifact_object_key)
                ? $this->storage->materialize($artifact->artifact_disk, $artifact->artifact_object_key, $proof['artifact_sha256'],
                    $artifact->artifact_content_type, expectedBytes: $proof['artifact_bytes'])
                : $this->absolutePath((string) $artifact->artifact_path);
            if (strlen($path) > ($persistCachePath ? 255 : 4096)
                || ! hash_equals($proof['artifact_sha256'], MlbTabularFiles::hash($path, $proof['artifact_bytes']))) {
                throw new RuntimeException('MLB cache path/content violates its reviewed byte or column policy.');
            }
            $this->assertContext($context);
            $context['action'] = $persistCachePath ? 'cache_path' : 'archive_read';
            $prepared = new PreparedMlbArtifactMutation($artifact->id, $artifact->training_run_id, $initial['fingerprint'],
                $context['action'], ['artifact_attributes' => ['artifact_path' => $path]]);
            $this->prepared[$prepared] = $context;

            return $this->commit($prepared)->artifact_path;
        });
    }

    public function prepare(ModelArtifact $input): PreparedMlbArtifactMutation
    {
        return $this->refused(function () use ($input): PreparedMlbArtifactMutation {
            $context = $this->discover($input);
            $initial = $context['admission'];
            $artifact = $initial['artifact'];
            $proof = $initial['proof'];
            if ($proof['artifact_bytes'] > 268435456) {
                throw new RuntimeException('MLB unchanged archive exceeds its existing 256 MiB staging policy.');
            }
            $path = filled($artifact->artifact_disk) && filled($artifact->artifact_object_key)
                ? $this->storage->materialize($artifact->artifact_disk, $artifact->artifact_object_key, $proof['artifact_sha256'],
                    $artifact->artifact_content_type, expectedBytes: $proof['artifact_bytes'])
                : $this->absolutePath((string) $artifact->artifact_path);
            if (filled($artifact->artifact_disk) && filled($artifact->artifact_object_key)) {
                if (! hash_equals($proof['artifact_sha256'], MlbTabularFiles::hash($path, $proof['artifact_bytes']))) {
                    throw new RuntimeException('MLB canonical archive cache bytes changed during materialization.');
                }
                $this->assertContext($context);
                $context['action'] = 'archive_read';
                $prepared = new PreparedMlbArtifactMutation($artifact->id, $artifact->training_run_id, $initial['fingerprint'],
                    'archive_read', ['artifact_attributes' => ['artifact_path' => $path]]);
                $this->prepared[$prepared] = $context;

                return $prepared;
            }
            // This is the bounded header synthesized by current admission, never the caller's cached relation.
            $run = $artifact->getRelation('trainingRun');
            $stored = $this->storage->storeArtifact($run, $artifact->id, $path,
                expectedBytes: $proof['artifact_bytes'], expectedSha256: $proof['artifact_sha256']);
            $attributes = ['artifact_path' => $stored->localPath, 'artifact_disk' => $stored->disk,
                'artifact_object_key' => $stored->objectKey, 'artifact_uri' => $stored->uri,
                'artifact_hash' => $stored->sha256, 'artifact_size' => $stored->size,
                'artifact_content_type' => $stored->contentType];
            foreach (['artifact_path' => 255, 'artifact_disk' => 64, 'artifact_object_key' => 4096,
                'artifact_uri' => 4096, 'artifact_hash' => 64, 'artifact_content_type' => 128] as $field => $maximum) {
                if (! is_string($attributes[$field]) || $attributes[$field] === '' || strlen($attributes[$field]) > $maximum) {
                    throw new RuntimeException('MLB staged archive descriptor exceeds its scalar/column policy.');
                }
            }
            if ($stored->sha256 !== $proof['artifact_sha256'] || $stored->size !== $proof['artifact_bytes']) {
                throw new RuntimeException('MLB staged archive differs from its original protected content.');
            }
            $this->assertContext($context);
            $prepared = new PreparedMlbArtifactMutation($artifact->id, $artifact->training_run_id, $initial['fingerprint'],
                'archive', ['artifact_attributes' => $attributes]);
            $context['action'] = 'archive';
            $this->prepared[$prepared] = $context;

            return $prepared;
        });
    }

    public function commit(PreparedMlbArtifactMutation $prepared): ModelArtifact
    {
        return $this->refused(function () use ($prepared): ModelArtifact {
            $context = $this->prepared[$prepared] ?? null;
            if ($context === null || $prepared->action !== ($context['action'] ?? null)
                || ! in_array($prepared->action, ['archive', 'archive_read', 'cache_path', 'dataset_cache_path'], true)
                || $prepared->artifactId !== $context['admission']['artifact']->id
                || $prepared->trainingRunId !== $context['admission']['artifact']->training_run_id
                || ! hash_equals($prepared->fingerprint, $context['admission']['fingerprint'])) {
                throw new RuntimeException('MLB archive requires its exact owned staged context.');
            }
            $this->assertContext($context);
            if ($prepared->action === 'archive_read') {
                $proof = $context['admission']['proof'];
                if (! hash_equals($proof['artifact_sha256'], MlbTabularFiles::hash($prepared->payload['artifact_attributes']['artifact_path'], $proof['artifact_bytes']))) {
                    throw new RuntimeException('MLB prepared canonical archive cache bytes changed.');
                }
                $current = $this->artifacts->admit($context['admission']['artifact'], $prepared->fingerprint);
                $this->assertContext($context);
                $current['artifact']->setAttribute('artifact_path', $prepared->payload['artifact_attributes']['artifact_path']);

                return $current['artifact'];
            }
            $proof = $context['admission']['proof']['dataset'];

            return $this->sources->run($proof, function () use ($prepared, $context, $proof): ModelArtifact {
                $this->assertContext($context);
                $connection = $context['connection'];

                return $this->cohorts->run($connection, function () use ($prepared, $context, $proof, $connection): ModelArtifact {
                    $members = $this->state->read($connection);
                    $member = $members->firstWhere('id', $prepared->artifactId);
                    if ($member === null || $member->training_run_id !== $prepared->trainingRunId || $member->model_type !== 'mlb_tabular_bundle') {
                        throw new RuntimeException('MLB archive current target left its predetermined cohort or training owner.');
                    }
                    $current = $this->artifacts->currentForOwnedMutation($context['admission']['artifact'], $context['admission']);
                    $artifact = $current['artifact'];
                    $this->assertContext($context);
                    MlbTabularSourceFence::assertOwned($connection, $proof);
                    MlbArtifactCohortSerializer::assertOwned($connection);
                    $cacheField = $prepared->action === 'dataset_cache_path' ? 'dataset_path' : 'artifact_path';
                    $write = ! in_array($prepared->action, ['cache_path', 'dataset_cache_path'], true)
                        || $prepared->payload['artifact_attributes'][$cacheField] !== $artifact->$cacheField;
                    if ($write && $artifact->forceFill($prepared->payload['artifact_attributes'])->save() !== true) {
                        throw new RuntimeException('MLB archive pointer persistence was cancelled.');
                    }
                    $this->assertContext($context);
                    MlbTabularSourceFence::assertOwned($connection, $proof);
                    MlbArtifactCohortSerializer::assertOwned($connection);

                    if ($prepared->action === 'dataset_cache_path') {
                        $persisted = $this->artifacts->currentForOwnedMutation($context['admission']['artifact'], $context['admission'])['artifact'];
                        if ($persisted->dataset_path !== $prepared->payload['artifact_attributes']['dataset_path']) {
                            throw new RuntimeException('Dataset cache pointer differs from its staged requested path.');
                        }

                        return $persisted;
                    }

                    // Original ModelRun metadata/path/hash/proof are deliberately never rewritten.
                    return $artifact;
                });
            });
        });
    }

    /** Original protected dataset content only: never rewrite its training proof or storage metadata. */
    public function attachTrainingDataset(ModelArtifact $input, string $path): ModelArtifact
    {
        $this->checkedDataset($input, $path, false, null);

        // Return its clean stored descriptor, not a dirty in-memory cache annotation.
        return $input;
    }

    public function materializeTrainingDataset(ModelArtifact $input, bool $persistCachePath, ?int $maximumBytes): string
    {
        return $this->checkedDataset($input, null, $persistCachePath, $maximumBytes)[1];
    }

    private function checkedDataset(ModelArtifact $input, ?string $suppliedPath, bool $persistCachePath, ?int $maximumBytes): array
    {
        return $this->refused(function () use ($input, $suppliedPath, $persistCachePath, $maximumBytes): array {
            $context = $this->discover($input);
            $initial = $context['admission'];
            $artifact = $initial['artifact'];
            $proof = $initial['proof']['dataset'];
            $bytes = $proof['dataset_bytes'];
            if ($maximumBytes !== null && ($maximumBytes < 0 || $maximumBytes > 2147483647 || $bytes > $maximumBytes)) {
                throw new RuntimeException('Protected dataset exceeds its explicit caller byte policy.');
            }
            if ($suppliedPath !== null && ! hash_equals($proof['dataset_sha256'], MlbTabularFiles::hash($this->absolutePath($suppliedPath), $bytes))) {
                throw new RuntimeException('Supplied dataset differs from its original protected training content.');
            }
            $path = filled($artifact->dataset_disk) && filled($artifact->dataset_object_key)
                ? $this->storage->materialize($artifact->dataset_disk, $artifact->dataset_object_key, $proof['dataset_sha256'],
                    $artifact->dataset_content_type, expectedBytes: $bytes)
                : $this->absolutePath((string) $artifact->dataset_path);
            if (strlen($path) > ($persistCachePath ? 255 : 4096)
                || ! hash_equals($proof['dataset_sha256'], MlbTabularFiles::hash($path, $bytes))) {
                throw new RuntimeException('Protected dataset cache path/content exceeds its reviewed policy.');
            }
            $this->assertContext($context);
            if (! $persistCachePath) {
                $current = $this->artifacts->admit($artifact, $initial['fingerprint']);
                $this->assertContext($context);

                return [$current['artifact'], $path];
            }
            $context['action'] = 'dataset_cache_path';
            $prepared = new PreparedMlbArtifactMutation($artifact->id, $artifact->training_run_id, $initial['fingerprint'],
                'dataset_cache_path', ['artifact_attributes' => ['dataset_path' => $path]]);
            $this->prepared[$prepared] = $context;

            return [$this->commit($prepared), $path];
        });
    }

    public function attachTrainingReport(ModelArtifact $input, string $path): ModelArtifact
    {
        [$current, $materialized] = $this->checkedReport($input, $path);
        // In-memory path only: the protected training report descriptor remains unchanged in SQL.
        $current->setAttribute('evaluation_report_path', $materialized);

        return $current;
    }

    public function materializeTrainingReport(ModelArtifact $input): string
    {
        return $this->checkedReport($input, null)[1];
    }

    private function checkedReport(ModelArtifact $input, ?string $suppliedPath): array
    {
        return $this->refused(function () use ($input, $suppliedPath): array {
            $context = $this->discover($input);
            $initial = $context['admission'];
            $artifact = $initial['artifact'];
            $proof = $initial['proof'];
            if ($proof['evaluation_bytes'] > 2097152) {
                throw new RuntimeException('MLB original training report exceeds its existing 2 MiB policy.');
            }
            if ($suppliedPath !== null && ! hash_equals($proof['evaluation_sha256'], MlbTabularFiles::hash($this->absolutePath($suppliedPath), $proof['evaluation_bytes']))) {
                throw new RuntimeException('The ML evaluation report does not match the registered SHA-256.');
            }
            $path = filled($artifact->evaluation_report_disk) && filled($artifact->evaluation_report_object_key)
                ? $this->storage->materialize($artifact->evaluation_report_disk, $artifact->evaluation_report_object_key,
                    $proof['evaluation_sha256'], $artifact->evaluation_report_content_type, expectedBytes: $proof['evaluation_bytes'])
                : $this->absolutePath((string) $artifact->evaluation_report_path);
            if (! hash_equals($proof['evaluation_sha256'], MlbTabularFiles::hash($path, $proof['evaluation_bytes']))) {
                throw new RuntimeException('MLB original training report bytes changed during materialization.');
            }
            $this->assertContext($context);
            $current = $this->artifacts->admit($artifact, $initial['fingerprint']);
            $this->assertContext($context);

            return [$current['artifact'], $path];
        });
    }

    private function discover(ModelArtifact $input): array
    {
        $connection = (new ModelArtifact)->getConnection();
        if ($connection->transactionLevel() !== 0 || $input->getConnection() !== $connection || ! $input->exists || $input->isDirty()
            || ! Str::isUuid((string) $input->getKey()) || $input->getKey() !== $input->getRawOriginal($input->getKeyName())) {
            throw new RuntimeException('MLB storage requires a clean standalone coherent current identity.');
        }
        $this->cohorts->assertSupported($connection);
        $clock = $this->clocks->capturePrimary($connection);
        $initial = $this->artifacts->admit($input);
        $context = ['admission' => $initial, 'connection' => $connection, 'clock' => $clock];
        $this->assertContext($context);

        return $context;
    }

    private function assertContext(array $context): void
    {
        if ((new ModelArtifact)->getConnection() !== $context['connection'] || (new ModelRun)->getConnection() !== $context['connection']
            || $context['admission']['artifact']->getConnection() !== $context['connection']) {
            throw new RuntimeException('MLB storage cannot switch its source/target connection.');
        }
        $this->clocks->assertUnchanged($context['clock'], $context['connection']);
    }

    private function absolutePath(string $path): string
    {
        if ($path === '' || strlen($path) > 4096) {
            throw new RuntimeException('MLB storage source path is empty or oversized.');
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function refused(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RuntimeException|LogicException $failure) {
            throw new ExistingArtifactOwnershipRefused('MLB current storage admission refused: '.$failure->getMessage(), 0, $failure);
        }
    }
}
