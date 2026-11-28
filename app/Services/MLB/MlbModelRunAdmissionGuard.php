<?php

namespace App\Services\MLB;

use App\Models\ModelRun;
use App\Services\ML\MlPreparedArtifactRegistration;
use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Support\Str;
use LogicException;
use WeakMap;

/** Instance admission only. Bulk SQL remains a trusted boundary; no source/parent locks are acquired here. */
final class MlbModelRunAdmissionGuard
{
    private const BINDINGS = ['id', 'sport', 'run_type', 'model_version', 'feature_version', 'blend_version', 'config_hash', 'code_version'];

    private static ?WeakMap $inserts = null;

    public static function envelope(ModelRun $run, string $artifactId, array $dataset, array $artifact): array
    {
        $proof = ['schema' => 'mlb-tabular-run-admission-v1', 'run' => array_combine(self::BINDINGS, array_map(fn (string $field) => $run->getAttribute($field), self::BINDINGS)),
            'artifact_id' => $artifactId, 'artifact_sha256' => $artifact['artifact_hash'], 'dataset_sha256' => $artifact['dataset_hash'],
            'manifest_sha256' => $artifact['manifest_sha256'], 'dataset' => $dataset,
            'artifact_bytes' => $artifact['artifact_bytes'], 'evaluation_sha256' => $artifact['evaluation_sha256'],
            'evaluation_bytes' => $artifact['evaluation_bytes'], 'python_model_run_id' => $artifact['python_model_run_id'],
            'feature_schema_hash' => $artifact['feature_schema_hash'], 'execution_format' => $artifact['execution_format'],
            'market_type' => $artifact['market_type']];
        $proof['context_sha256'] = app(MlbTabularDatasetProjector::class)->stableHash($proof);
        MlbTabularJsonBudget::inspect(json_encode($proof, JSON_THROW_ON_ERROR), 2097152, 100000, 24);

        return $proof;
    }

    /** The registry checks exact prepared-object ownership, then the already-owned source fence. */
    public static function insert(ModelArtifactRegistry $registry, MlPreparedArtifactRegistration $prepared, ModelRun $run): void
    {
        $registry->assertPreparedMlbOwned($prepared);
        MlbTabularSourceFence::assertOwned($run->getConnection(), $prepared->admission);
        MlbArtifactCohortSerializer::assertOwned($run->getConnection());
        app(MlbArtifactCohortState::class)->assertAddition($run->getConnection(), $prepared->artifactAttributes['metrics']);
        if ($run->exists || (string) $run->getKey() !== $prepared->trainingRunId) {
            throw new LogicException('MLB run admission requires a new owned run UUID.');
        }
        $metadata = self::metadata($run->getAttributes()['metadata'] ?? null);
        if (array_key_exists('mlb_tabular_admission', $metadata)) {
            throw new LogicException('Unstamped new MLB run metadata is required.');
        }
        $proof = $prepared->runUpdates['metadata']['mlb_tabular_admission'];
        self::assertBinding($run, $proof);
        $run->setAttribute('metadata', [...$metadata, 'mlb_tabular_admission' => $proof]);
        self::$inserts ??= new WeakMap;
        self::$inserts[$run] = $proof;
        try {
            $run->save();
        } finally {
            unset(self::$inserts[$run]);
        }
    }

    /** Lock only scalar columns and the bounded nested proof, never parameters or the whole metadata body. */
    public static function current(ModelRun $model, mixed $key, bool $lock = true): ?ModelRun
    {
        $connection = $model->getConnection();
        $grammar = $connection->getQueryGrammar();
        $column = $grammar->wrap('metadata');
        $mysql = $connection->getDriverName() === 'mysql';
        if (! $mysql && $connection->getDriverName() !== 'sqlite') {
            throw new LogicException('MLB run proof metadata dialect is unsupported.');
        }
        $bytes = ($mysql ? 'OCTET_LENGTH' : 'length')."(CAST($column AS ".($mysql ? 'BINARY' : 'BLOB').'))';
        $safe = "CASE WHEN $column IS NULL THEN '{}' WHEN $bytes <= 67108864 THEN $column ELSE NULL END";
        $valid = "CASE WHEN $safe IS NULL THEN 0 ELSE JSON_VALID($safe) END";
        $json = "CASE WHEN $valid = 1 THEN $safe ELSE '{}' END";
        $type = $mysql ? "JSON_TYPE(JSON_EXTRACT($json, '$.mlb_tabular_admission'))" : "json_type($json, '$.mlb_tabular_admission')";
        $extract = $mysql ? "CAST(JSON_EXTRACT($json, '$.mlb_tabular_admission') AS CHAR)" : "json_quote(json_extract($json, '$.mlb_tabular_admission'))";
        $proofBytes = ($mysql ? 'OCTET_LENGTH' : 'length')."($extract)";
        $status = $grammar->wrap('status');
        $archive = $grammar->wrap('artifact_hash');
        $length = $mysql ? 'OCTET_LENGTH' : 'length';
        $cast = $mysql ? 'BINARY' : 'BLOB';
        $query = $model->newModelQuery()->useWritePdo()->whereKey($key)->select(self::BINDINGS)
            ->selectRaw("CASE WHEN $length(CAST($status AS $cast)) <= 255 THEN $status ELSE NULL END AS status,
                CASE WHEN $length(CAST($archive AS $cast)) <= 64 THEN $archive ELSE NULL END AS artifact_hash,
                $valid AS _proof_valid, $type AS _proof_type, CASE WHEN $proofBytes <= 2097152 THEN $extract ELSE NULL END AS _proof_body");
        if ($lock) {
            $query->lockForUpdate();
        }
        $current = $query->first();
        if ($current === null) {
            return null;
        }
        if ((int) $current->getAttribute('_proof_valid') !== 1) {
            throw new LogicException('Model run metadata exceeds its reviewed work ceiling or is malformed.');
        }
        $typeValue = $current->getAttribute('_proof_type');
        $proof = null;
        if ($typeValue !== null) {
            if (strtolower((string) $typeValue) !== 'object' || ! is_string($current->getAttribute('_proof_body'))) {
                throw new LogicException('Recorded MLB run proof is partial or exceeds its byte budget.');
            }
            try {
                $proof = self::decode($current->getAttribute('_proof_body'));
            } catch (\RuntimeException|\JsonException $exception) {
                throw new LogicException('Recorded MLB run proof is malformed or exceeds its structural budget.', 0, $exception);
            }
        }
        $attributes = $current->getAttributes();
        unset($attributes['_proof_body'], $attributes['_proof_type'], $attributes['_proof_valid']);
        $current->setRawAttributes($attributes, true);
        $current->setRelation('boundedMlbAdmissionProof', $proof);

        return $current;
    }

    public static function admit(ModelRun $model, ?ModelRun $current, string $operation, array $patch): void
    {
        foreach (array_keys($patch) as $field) {
            if (str_contains((string) $field, '->')) {
                throw new LogicException('Model run JSON selector instance writes require an explicit whole metadata update.');
            }
        }
        $newMetadata = array_key_exists('metadata', $patch) ? self::metadata($patch['metadata']) : null;
        $hasNew = $newMetadata !== null && array_key_exists('mlb_tabular_admission', $newMetadata);
        if ($current === null) {
            if ($hasNew && (! isset(self::$inserts[$model]) || self::$inserts[$model] !== $newMetadata['mlb_tabular_admission'])) {
                throw new LogicException('New MLB run proof requires exact owned prepared admission.');
            }

            return;
        }
        $proof = $current->getRelation('boundedMlbAdmissionProof');
        if ($proof === null) {
            if ($hasNew) {
                throw new LogicException('Existing model runs cannot acquire MLB admission proof.');
            }

            return;
        }
        if ($operation === 'delete') {
            throw new LogicException('Recorded MLB run admission cannot be deleted through instance methods.');
        }
        self::assertBinding($current, $proof);
        foreach (self::BINDINGS as $field) {
            if (array_key_exists($field, $patch) && $patch[$field] !== $current->getAttribute($field)) {
                throw new LogicException('Recorded MLB run proof bindings cannot change.');
            }
        }
        if ($newMetadata !== null && (! $hasNew || app(MlbTabularDatasetProjector::class)->stableHash($newMetadata['mlb_tabular_admission']) !== app(MlbTabularDatasetProjector::class)->stableHash($proof))) {
            throw new LogicException('Recorded MLB run admission proof is immutable.');
        }
    }

    /** Only a bounded current-row projection may supply read admission, never a caller's eager metadata. */
    public static function proof(ModelRun $run): array
    {
        if (! $run->relationLoaded('boundedMlbAdmissionProof') || ! is_array($proof = $run->getRelation('boundedMlbAdmissionProof'))) {
            throw new LogicException('Current MLB run admission proof is unavailable.');
        }
        self::assertBinding($run, $proof);
        try {
            app(MlbTabularDatasetAdmission::class)->assertProof($proof['dataset']);
        } catch (\RuntimeException $exception) {
            throw new LogicException('Recorded MLB run dataset proof is unsupported.', 0, $exception);
        }

        return $proof;
    }

    private static function decode(string $raw): mixed
    {
        MlbTabularJsonBudget::inspect($raw, 2097152, 100000, 24);

        return MlbTabularJsonBudget::decode($raw, 2097152, 100000);
    }

    private static function metadata(mixed $raw): array
    {
        try {
            $value = is_string($raw) ? self::decode($raw) : $raw;
            if ($value === null) {
                return [];
            }
            if (! is_array($value)) {
                throw new LogicException('Model run metadata must be an object.');
            }
            MlbTabularJsonBudget::inspect(json_encode($value, JSON_THROW_ON_ERROR), 2097152, 100000, 24);

            return $value;
        } catch (\RuntimeException|\JsonException $exception) {
            throw new LogicException('Model run metadata update exceeds its explicit input budget.', 0, $exception);
        }
    }

    private static function assertBinding(ModelRun $run, array $proof): void
    {
        $keys = array_keys($proof);
        $expected = ['schema', 'run', 'artifact_id', 'artifact_sha256', 'artifact_bytes', 'dataset_sha256', 'manifest_sha256', 'evaluation_sha256',
            'evaluation_bytes', 'python_model_run_id', 'feature_schema_hash', 'execution_format', 'market_type', 'dataset', 'context_sha256'];
        sort($keys);
        sort($expected);
        if ($keys !== $expected || ($proof['schema'] ?? null) !== 'mlb-tabular-run-admission-v1' || ! is_array($proof['run'] ?? null)
            || ($proof['execution_format'] ?? null) !== 'picksports-tabular-numeric-v1'
            || ! is_string($proof['python_model_run_id'] ?? null) || trim($proof['python_model_run_id']) === '' || strlen($proof['python_model_run_id']) > 191
            || ! is_string($proof['market_type'] ?? null) || strlen($proof['market_type']) > 191
            || ! is_int($proof['artifact_bytes'] ?? null) || $proof['artifact_bytes'] < 1 || $proof['artifact_bytes'] > 268435456
            || ! is_int($proof['evaluation_bytes'] ?? null) || $proof['evaluation_bytes'] < 1 || $proof['evaluation_bytes'] > 2097152
            || ! is_array($proof['dataset'] ?? null) || ($proof['dataset']['dataset_sha256'] ?? null) !== ($proof['dataset_sha256'] ?? null)
            || ! Str::isUuid((string) ($proof['artifact_id'] ?? ''))) {
            throw new LogicException('Recorded MLB run proof protocol is unsupported.');
        }
        $runKeys = array_keys($proof['run']);
        $expectedRunKeys = self::BINDINGS;
        sort($runKeys);
        sort($expectedRunKeys);
        if ($runKeys !== $expectedRunKeys) {
            throw new LogicException('Recorded MLB run proof bindings are partial.');
        }
        foreach (['artifact_sha256', 'dataset_sha256', 'manifest_sha256', 'evaluation_sha256', 'feature_schema_hash', 'context_sha256'] as $field) {
            if (! is_string($proof[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $proof[$field])) {
                throw new LogicException('Recorded MLB run proof digest is malformed.');
            }
        }
        $context = $proof;
        unset($context['context_sha256']);
        if (! is_string($proof['context_sha256'] ?? null) || ! hash_equals(app(MlbTabularDatasetProjector::class)->stableHash($context), $proof['context_sha256'])) {
            throw new LogicException('Recorded MLB run proof content no longer matches its context.');
        }
        foreach (self::BINDINGS as $field) {
            if (($proof['run'][$field] ?? null) !== $run->getAttribute($field)) {
                throw new LogicException('Recorded MLB run proof no longer matches its current run.');
            }
        }
    }
}
