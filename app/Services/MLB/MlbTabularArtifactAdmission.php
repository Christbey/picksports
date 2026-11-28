<?php

namespace App\Services\MLB;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/** Read admission only; storage/process IO and later output writes need their own boundaries. */
final class MlbTabularArtifactAdmission
{
    private const COLUMNS = ['id', 'training_run_id', 'sport', 'market_type', 'model_type', 'model_version', 'feature_version',
        'dataset_hash', 'dataset_size', 'artifact_hash', 'artifact_size', 'status', 'evaluation_report_hash', 'evaluation_report_size',
        'artifact_path', 'artifact_disk', 'artifact_object_key', 'artifact_content_type', 'dataset_path', 'dataset_disk', 'dataset_object_key',
        'dataset_content_type', 'evaluation_report_path', 'evaluation_report_disk', 'evaluation_report_object_key', 'evaluation_report_content_type'];

    public function __construct(private readonly MlbTabularSnapshotReader $reader, private readonly MlbTabularSourceFence $fence,
        private readonly MlbTabularDatasetProjector $projector) {}

    /** Current nonlocking discovery, then source-first locks and current target-run/artifact checks. */
    public function admit(ModelArtifact $input, ?string $expectedFingerprint = null): array
    {
        try {
            $connection = $this->reader->connection();
            if ($connection->transactionLevel() !== 0 || $input->getConnection() !== $connection
                || ! $input->exists || ! Str::isUuid((string) $input->getKey())
                || $input->getKey() !== $input->getRawOriginal($input->getKeyName())) {
                throw new RuntimeException('A standalone coherent registered artifact is required.');
            }
            $discovered = $this->artifact($input, false);
            $runTemplate = (new ModelRun)->setConnection($connection->getName());
            $run = MlbModelRunAdmissionGuard::current($runTemplate, $discovered->training_run_id, false);
            $initial = $this->describe($discovered, $run);

            return $this->fence->run($initial['proof']['dataset'], function () use ($input, $initial, $runTemplate, $expectedFingerprint): array {
                // IDs are predetermined. A changed owner never introduces a late new parent lock.
                $run = MlbModelRunAdmissionGuard::current($runTemplate, $initial['artifact']->training_run_id);
                $artifact = $this->artifact($input, true);
                if ($artifact->training_run_id !== $initial['artifact']->training_run_id) {
                    throw new RuntimeException('Artifact training owner changed during source discovery.');
                }
                $current = $this->describe($artifact, $run);
                if (! hash_equals($initial['fingerprint'], $current['fingerprint'])
                    || ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $current['fingerprint']))) {
                    throw new RuntimeException('Artifact admission identity changed; a fresh read is required.');
                }

                return $current;
            });
        } catch (RuntimeException|LogicException $exception) {
            throw new RuntimeException('MLB artifact timing admission refused current ownership or source evidence.', 0, $exception);
        }
    }

    /** Only the source/cohort coordinator may request these predetermined existing target locks. */
    public function currentForOwnedMutation(ModelArtifact $input, array $initial): array
    {
        $connection = $this->reader->connection();
        if ($input->getConnection() !== $connection) {
            throw new RuntimeException('MLB current target cannot use another resolved connection.');
        }
        MlbTabularSourceFence::assertOwned($connection, $initial['proof']['dataset']);
        MlbArtifactCohortSerializer::assertOwned($connection);
        $runTemplate = (new ModelRun)->setConnection($connection->getName());
        $run = MlbModelRunAdmissionGuard::current($runTemplate, $initial['artifact']->training_run_id);
        $artifact = $this->artifact($input, true);
        if ($artifact->training_run_id !== $initial['artifact']->training_run_id) {
            throw new RuntimeException('Artifact training owner changed; new parents cannot be locked late.');
        }
        $current = $this->describe($artifact, $run);
        if (! hash_equals($initial['fingerprint'], $current['fingerprint'])) {
            throw new RuntimeException('Current artifact differs from its prepared mutation context.');
        }

        return $current;
    }

    /** Scalar CASE projections bound retained path fields as well as nominal SQL varchar fields. */
    private function artifact(ModelArtifact $input, bool $lock): ModelArtifact
    {
        if ($input->getTable() !== (new ModelArtifact)->getTable() || $input->getKeyName() !== (new ModelArtifact)->getKeyName()) {
            throw new RuntimeException('Artifact admission requires the literal registered model store identity.');
        }
        $connection = $input->getConnection();
        $mysql = $connection->getDriverName() === 'mysql';
        if (! $mysql && $connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Artifact scalar projection dialect is unsupported.');
        }
        $grammar = $connection->getQueryGrammar();
        $length = $mysql ? 'OCTET_LENGTH' : 'length';
        $cast = $mysql ? 'BINARY' : 'BLOB';
        $select = $oversized = [];
        foreach (self::COLUMNS as $field) {
            $column = $grammar->wrap($field);
            $bytes = "COALESCE($length(CAST($column AS $cast)), 0)";
            $select[] = "CASE WHEN $bytes <= 4096 THEN $column ELSE NULL END AS $column";
            $oversized[] = "$bytes > 4096";
        }
        $query = $input->newModelQuery()->useWritePdo()->whereKey($input->getRawOriginal($input->getKeyName()))
            ->selectRaw(implode(', ', $select).', CASE WHEN ('.implode(' OR ', $oversized).') THEN 1 ELSE 0 END AS _artifact_oversized');
        if ($lock) {
            $query->lockForUpdate();
        }
        $artifact = $query->first();
        if ($artifact === null || (int) $artifact->getAttribute('_artifact_oversized') !== 0) {
            throw new RuntimeException('Current artifact is missing or exceeds its bounded scalar field scope.');
        }

        return $artifact;
    }

    private function describe(ModelArtifact $artifact, ?ModelRun $run): array
    {
        if ($run === null || $run->sport !== 'mlb' || $run->run_type !== 'training' || $run->status !== 'completed'
            || $artifact->sport !== 'mlb' || $artifact->model_type !== 'mlb_tabular_bundle'
            || ! in_array($artifact->status, ['challenger', 'promotion_eligible', 'promoted'], true)) {
            throw new RuntimeException('Current typed artifact and completed training owner are required.');
        }
        $proof = MlbModelRunAdmissionGuard::proof($run);
        $expected = ['id' => $proof['artifact_id'], 'training_run_id' => $run->getKey(), 'market_type' => $proof['market_type'],
            'model_version' => $run->model_version, 'feature_version' => $run->feature_version,
            'dataset_hash' => $proof['dataset_sha256'], 'artifact_hash' => $proof['artifact_sha256'], 'evaluation_report_hash' => $proof['evaluation_sha256']];
        foreach ($expected as $field => $value) {
            if ($artifact->getAttribute($field) !== $value) {
                throw new RuntimeException('Current artifact does not match its recorded run proof.');
            }
        }
        foreach (['artifact_size' => $proof['artifact_bytes'], 'dataset_size' => $proof['dataset']['dataset_bytes'], 'evaluation_report_size' => $proof['evaluation_bytes']] as $field => $value) {
            $raw = $artifact->getRawOriginal($field);
            if ((! is_int($raw) && (! is_string($raw) || ! preg_match('/^(0|[1-9][0-9]{0,9})$/D', $raw))) || (int) $raw !== $value) {
                throw new RuntimeException('Artifact byte length does not match its protected proof.');
            }
        }
        if ($run->artifact_hash !== $proof['artifact_sha256']) {
            throw new RuntimeException('Training owner archive digest drifted from its protected proof.');
        }
        $authority = $artifact->getAttributes();
        unset($authority['artifact_path'], $authority['dataset_path'], $authority['evaluation_report_path'], $authority['_artifact_oversized']);
        if (! is_string($artifact->artifact_disk) || $artifact->artifact_disk === '' || ! is_string($artifact->artifact_object_key) || $artifact->artifact_object_key === '') {
            $authority['legacy_artifact_path'] = $artifact->artifact_path;
        }
        $fingerprint = $this->projector->stableHash(['artifact' => $authority, 'proof' => $proof['context_sha256']]);
        // Existing bundle/output lineage readers receive only checked headers, never eager parameters/metadata.
        $lineage = (new ModelRun)->setConnection($run->getConnectionName());
        $lineage->setRawAttributes(array_intersect_key($run->getAttributes(), array_flip(['id', 'sport', 'run_type', 'model_version', 'feature_version', 'blend_version', 'config_hash', 'code_version', 'status', 'artifact_hash'])));
        $lineage->setAttribute('metadata', ['python_model_run_id' => $proof['python_model_run_id']]);
        $lineage->exists = true;
        $artifact->setRelation('trainingRun', $lineage);

        return ['artifact' => $artifact, 'proof' => $proof, 'fingerprint' => $fingerprint];
    }
}
