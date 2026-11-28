<?php

namespace App\Services\MLB;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\ModelTrainingAttempt;
use App\Models\ModelTrainingWorker;
use App\Services\ML\ModelPromotionEvaluator;
use App\Services\ML\TrainingAttemptService;
use RuntimeException;
use WeakMap;

/** Preparation owns I/O; commit owns sources -> credential/uploads -> cohort -> cycle/attempt -> new UUIDs. */
final class MlbTrainingCompletion
{
    public const REMOTE_PROTOCOL = 'mlb-staged-source-v1';

    private WeakMap $owned;

    public function __construct(
        private readonly MlbTabularModelRunRegistrar $registrar,
        private readonly MlbTabularSourceFence $sources,
        private readonly MlbArtifactCohortSerializer $cohorts,
        private readonly MlbArtifactCohortState $state,
        private readonly MlbTabularDatasetProjector $hashes,
        private readonly TrainingAttemptService $attempts,
        private readonly ModelPromotionEvaluator $promotion,
    ) {
        $this->owned = new WeakMap;
    }

    public function prepare(ModelRun $cycle, ModelTrainingAttempt $attempt, string $directory, string $datasetPath,
        string $outputHash, ?ModelTrainingWorker $credential = null, ?array $uploads = null): PreparedMlbTrainingCompletion
    {
        $connection = (new ModelRun)->getConnection();
        if ($connection->transactionLevel() !== 0 || $cycle->getConnection() !== $connection || $attempt->getConnection() !== $connection) {
            throw new RuntimeException('MLB completion preparation requires a standalone coherent connection.');
        }
        $this->cohorts->assertSupported($connection);
        $snapshotBinding = $this->cycleBinding($cycle);
        $current = MlbTrainingCompletionState::cycle($connection, $cycle->id, false);
        if (! hash_equals($this->hashes->stableHash($snapshotBinding), $this->hashes->stableHash($this->cycleBinding($current)))) {
            throw new RuntimeException('MLB completion frozen cycle binding changed after its caller snapshot.');
        }
        $remoteId = data_get($current->metadata, 'remote_worker_id');
        if (($credential === null) !== ($remoteId === null) || ($credential !== null && ($remoteId !== $credential->id || $uploads === null))) {
            throw new RuntimeException('MLB completion must bind its actual remote credential and uploads.');
        }
        if ($cycle->id !== $attempt->cycle_run_id || ! is_string($outputHash) || preg_match('/^[a-f0-9]{64}$/D', $outputHash) !== 1) {
            throw new RuntimeException('MLB completion owner or digest is invalid.');
        }
        if ($uploads !== null) {
            $uploads = $this->uploadBindings($uploads, (int) $attempt->id);
        }
        $remoteContext = $remoteId === null ? null : $this->remoteContext($current);
        $registration = $this->registrar->prepare($directory, $datasetPath);
        $datasetHash = (string) data_get($current->metadata, 'dataset_hash');
        $fingerprint = (string) data_get($current->metadata, 'training_fingerprint');
        $specification = (array) data_get($current->metadata, 'training_specification');
        if ($registration->registration->trainingRunId !== $attempt->python_run_id
            || ! hash_equals($datasetHash, $registration->registration->admission['dataset_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || ($specification['model_type'] ?? null) !== 'mlb_tabular_bundle') {
            throw new RuntimeException('MLB prepared completion differs from its frozen cycle lineage.');
        }
        foreach (['model_type' => 'model_type', 'model_version' => 'model_version', 'feature_schema_version' => 'feature_version'] as $field => $requested) {
            if (($registration->manifest[$field] ?? null) !== ($specification[$requested] ?? null)) {
                throw new RuntimeException('MLB prepared artifact differs from its frozen requested version binding.');
            }
        }
        if ($current->model_version !== ($specification['model_version'] ?? null) || $current->feature_version !== ($specification['feature_version'] ?? null)) {
            throw new RuntimeException('MLB cycle declared version binding differs from its frozen specification.');
        }
        $prototype = new ModelArtifact(['market_type' => 'multi_market']);
        $decision = $this->promotion->evaluateReport($prototype, $registration->evaluation);
        $prepared = new PreparedMlbTrainingCompletion($registration, $current->id, (int) $attempt->id,
            $attempt->owner_token, $attempt->python_run_id, $outputHash, $datasetHash, $fingerprint, $specification, $decision);
        $this->owned[$prepared] = ['remote_worker_id' => $remoteId, 'token_hash' => $credential?->getRawOriginal('token_hash'),
            'uploads' => $uploads === null ? null : $this->hashes->stableHash($uploads),
            'remote_context' => $remoteContext, 'cycle_binding' => $snapshotBinding];

        return $prepared;
    }

    public function commit(PreparedMlbTrainingCompletion $prepared, ?ModelTrainingWorker $credential = null): array
    {
        $ownership = $this->owned[$prepared] ?? null;
        if ($ownership === null) {
            throw new RuntimeException('MLB completion requires its exact owned prepared carrier.');
        }
        $proof = $prepared->registration->registration->admission;

        return $this->sources->run($proof, function () use ($prepared, $credential, $ownership, $proof): array {
            $connection = (new ModelRun)->getConnection();
            $worker = null;
            if ($ownership['remote_worker_id'] !== null) {
                if ($credential === null || $credential->getConnection() !== $connection
                    || $credential->id !== $ownership['remote_worker_id']
                    || ! hash_equals((string) $ownership['token_hash'], (string) $credential->getRawOriginal('token_hash'))) {
                    throw new RuntimeException('MLB completion credential differs from its prepared owner.');
                }
                $worker = MlbTrainingCompletionState::worker($connection, $credential->id);
                $this->assertWorker($worker, (string) $ownership['token_hash']);
                $uploads = $this->uploadBindings(MlbTrainingCompletionState::uploads($connection, $prepared->attemptId), $prepared->attemptId);
                if (! hash_equals($ownership['uploads'], $this->hashes->stableHash($uploads))) {
                    throw new RuntimeException('MLB completion uploads changed after preparation.');
                }
            } elseif ($credential !== null) {
                throw new RuntimeException('Local MLB completion cannot acquire a remote credential.');
            }

            return $this->cohorts->run($connection, function () use ($prepared, $ownership, $worker, $connection, $proof): array {
                $cohort = $this->state->read($connection);
                $active = $cohort->filter(fn (ModelArtifact $artifact): bool => $artifact->model_type === 'mlb_tabular_bundle'
                    && in_array($artifact->status, ['challenger', 'promotion_eligible'], true)
                    && data_get($artifact->metrics, 'shadow_selection.active') === true)
                    ->sortByDesc(fn (ModelArtifact $artifact): string => (string) $artifact->getRawOriginal('created_at'))
                    ->sortByDesc(fn (ModelArtifact $artifact): string => (string) $artifact->getRawOriginal('updated_at'))->first();
                $cycle = MlbTrainingCompletionState::cycle($connection, $prepared->cycleId);
                $this->assertCycle($cycle, $prepared, $ownership);
                $attempt = MlbTrainingCompletionState::latestAttempt($connection, $cycle->id);
                if ($attempt === null || (int) $attempt->id !== $prepared->attemptId
                    || ! hash_equals($attempt->owner_token, $prepared->ownerToken) || $attempt->python_run_id !== $prepared->pythonRunId) {
                    throw new RuntimeException('MLB completion latest attempt owner changed.');
                }
                $result = $this->attempts->completeMlb($attempt, $prepared->outputHash, $proof, function () use ($prepared, $active, $cohort): array {
                    if ($cohort->count() >= 1000) {
                        throw new RuntimeException('MLB completion would exceed the reviewed cohort capacity.');
                    }
                    $decision = $this->newArtifactDecision($prepared->decision);
                    $metrics = (array) $prepared->registration->registration->artifactAttributes['metrics'];
                    $metrics['automation'] = ['weekly_training' => true, 'cycle_run_id' => $prepared->cycleId,
                        'training_fingerprint' => $prepared->fingerprint, 'dataset_hash' => $prepared->datasetHash,
                        'trained_at' => now()->toIso8601String()];
                    $selected = $active?->id;
                    if ($selected === null && $decision['eligible']) {
                        $metrics['shadow_selection'] = ['active' => true, 'activated_at' => now()->toIso8601String(),
                            'deactivated_at' => null, 'deactivation_reason' => null,
                            'metadata' => ['reason' => 'offline_gates_passed', 'cycle_run_id' => $prepared->cycleId,
                                'training_fingerprint' => $prepared->fingerprint]];
                        $selected = $prepared->registration->registration->artifactId;
                    }
                    $rawMetrics = MlbTabularJsonBudget::encode($metrics, 262144, 50000, 24);
                    $totalBytes = strlen($rawMetrics);
                    $totalTokens = MlbTabularJsonBudget::inspect($rawMetrics, 262144, 50000, 24);
                    foreach ($cohort as $member) {
                        $existing = $member->getRawOriginal('metrics');
                        $totalBytes += strlen((string) $existing);
                        if ($existing !== null) {
                            $totalTokens += MlbTabularJsonBudget::inspect($existing, 262144, 50000, 24);
                        }
                    }
                    if ($totalBytes > 2097152 || $totalTokens > 50000) {
                        throw new RuntimeException('MLB completion would exceed the reviewed cohort metrics budget.');
                    }
                    $artifact = $this->registrar->persistPrepared($prepared->registration);
                    $artifact->forceFill(['metrics' => $metrics, 'status' => $decision['eligible'] ? 'promotion_eligible' : 'challenger',
                        'promotion_criteria' => $decision['criteria'], 'promotion_decision' => $decision, 'promoted_at' => null])->save();

                    return ['status' => 'completed', 'message' => 'MLB weekly challenger trained, registered, and evaluated.',
                        'cycle_run_id' => $prepared->cycleId, 'model_run_id' => $prepared->pythonRunId, 'artifact_id' => $artifact->id,
                        'dataset_hash' => $prepared->datasetHash, 'training_fingerprint' => $prepared->fingerprint,
                        'shadow_artifact_id' => $selected, 'artifact_status' => $artifact->status];
                });
                if ($worker !== null) {
                    $this->assertWorker($worker, (string) $ownership['token_hash']);
                }

                return $result;
            });
        });
    }

    private function uploadBindings(array $rows, int $attemptId): array
    {
        if (count($rows) > 64) {
            throw new RuntimeException('MLB completion upload binding count exceeds its bound.');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) !== 7
                || ! is_int($row['id'] ?? null) || $row['id'] < 1 || ($row['attempt_id'] ?? null) !== $attemptId
                || ! is_int($row['bytes'] ?? null) || $row['bytes'] < 0 || $row['bytes'] > 8388608) {
                throw new RuntimeException('MLB completion upload binding shape is invalid.');
            }
            foreach (['path' => 191, 'disk' => 255, 'object_key' => 4096, 'sha256' => 64] as $field => $maximum) {
                if (! is_string($row[$field] ?? null) || $row[$field] === '' || strlen($row[$field]) > $maximum) {
                    throw new RuntimeException('MLB completion upload binding scalar is invalid.');
                }
            }
            if (preg_match('/^[a-f0-9]{64}$/D', $row['sha256']) !== 1) {
                throw new RuntimeException('MLB completion upload binding digest is invalid.');
            }
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $rows;
    }

    private function assertCycle(ModelRun $cycle, PreparedMlbTrainingCompletion $prepared, array $ownership): void
    {
        if (! hash_equals($this->hashes->stableHash($ownership['cycle_binding']), $this->hashes->stableHash($this->cycleBinding($cycle)))
            || data_get($cycle->metadata, 'remote_worker_id') !== $ownership['remote_worker_id']
            || ($ownership['remote_worker_id'] !== null && ! hash_equals($ownership['remote_context'], $this->remoteContext($cycle)))
            || ! hash_equals((string) data_get($cycle->metadata, 'dataset_hash'), $prepared->datasetHash)
            || ! hash_equals((string) data_get($cycle->metadata, 'training_fingerprint'), $prepared->fingerprint)
            || ! hash_equals($this->hashes->stableHash((array) data_get($cycle->metadata, 'training_specification')), $this->hashes->stableHash($prepared->specification))) {
            throw new RuntimeException('MLB completion current cycle binding changed.');
        }
    }

    private function cycleBinding(ModelRun $cycle): array
    {
        if (! $cycle->exists || $cycle->getKey() !== $cycle->getRawOriginal($cycle->getKeyName())) {
            throw new RuntimeException('MLB completion requires its persisted original cycle identity.');
        }
        $metadata = MlbTrainingCompletionState::metadata($cycle);
        $columns = [];
        $attributes = $cycle->getAttributes();
        foreach (['id' => 36, 'sport' => 16, 'run_type' => 64, 'model_version' => 256, 'feature_version' => 256,
            'blend_version' => 256, 'config_hash' => 64, 'code_version' => 64] as $field => $maximum) {
            $value = $attributes[$field] ?? null;
            if (($value === null && $field !== 'code_version') || ($value !== null && (! is_string($value) || strlen($value) > $maximum))) {
                throw new RuntimeException('MLB completion cycle binding scalar exceeds its scope.');
            }
            $columns[$field] = $value;
        }

        return ['columns' => $columns, 'dataset_hash' => $metadata['dataset_hash'] ?? null,
            'fingerprint' => $metadata['training_fingerprint'] ?? null, 'specification' => $metadata['training_specification'] ?? null,
            'remote_worker_id' => $metadata['remote_worker_id'] ?? null,
            'remote_context' => ($metadata['remote_worker_id'] ?? null) === null ? null : $this->remoteContext($cycle)];
    }

    private function remoteContext(ModelRun $cycle): string
    {
        if (data_get($cycle->metadata, 'mlb_remote_protocol') !== self::REMOTE_PROTOCOL) {
            throw new RuntimeException('MLB completion requires its server-owned inspected dataset proof protocol.');
        }

        $inputs = data_get($cycle->metadata, 'remote_inputs');
        $sourceHash = data_get($cycle->metadata, 'remote_source_hash');
        if (! is_array($inputs) || count($inputs) !== 3 || ! is_string($sourceHash) || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1) {
            throw new RuntimeException('MLB remote completion input binding shape is invalid.');
        }
        foreach (['dataset' => 67108864, 'schema' => 65536, 'dataset_proof' => 2097152] as $kind => $maximum) {
            $descriptor = $inputs[$kind] ?? null;
            if (! is_array($descriptor) || count($descriptor) !== 4 || ! is_int($descriptor['bytes'] ?? null)
                || $descriptor['bytes'] < 1 || $descriptor['bytes'] > $maximum) {
                throw new RuntimeException('MLB remote completion input descriptor exceeds its bound.');
            }
            foreach (['disk' => 255, 'object_key' => 4096, 'sha256' => 64] as $field => $limit) {
                if (! is_string($descriptor[$field] ?? null) || $descriptor[$field] === '' || strlen($descriptor[$field]) > $limit) {
                    throw new RuntimeException('MLB remote completion input descriptor scalar is invalid.');
                }
            }
            if (preg_match('/^[a-f0-9]{64}$/D', $descriptor['sha256']) !== 1) {
                throw new RuntimeException('MLB remote completion input descriptor digest is invalid.');
            }
        }

        return $this->hashes->stableHash(['protocol' => self::REMOTE_PROTOCOL, 'inputs' => $inputs, 'source_hash' => $sourceHash]);
    }

    private function assertWorker(ModelTrainingWorker $worker, string $tokenHash): void
    {
        if (! hash_equals((string) $worker->token_hash, $tokenHash) || $worker->revoked_at !== null
            || $worker->expires_at === null || ! $worker->expires_at->isFuture()
            || ! in_array('mlb', (array) $worker->sports, true)
            || ! in_array('training:complete', (array) $worker->abilities, true)) {
            throw new RuntimeException('MLB completion credential is expired, revoked or no longer authorized.');
        }
    }

    /** This UUID was inserted in this transaction; FK-bound observations cannot predate its creation. */
    private function newArtifactDecision(array $decision): array
    {
        $minimumObservations = max(0, (int) config('ml.promotion.live_shadow.minimum_observations', 25));
        $minimumSettled = max(0, (int) config('ml.promotion.live_shadow.minimum_settled_decisions', 10));
        $passed = $minimumObservations === 0 && $minimumSettled === 0;
        $live = [];
        foreach ($decision['eligible_markets'] as $market) {
            $live[$market] = ['passed' => $passed, 'checks' => ['minimum_live_observations' => $minimumObservations === 0,
                'minimum_settled_decisions' => $minimumSettled === 0], 'live_pregame_safe_observations' => 0,
                'settled_pregame_safe_decisions' => 0, 'minimum_live_pregame_safe_observations' => $minimumObservations,
                'minimum_settled_pregame_safe_decisions' => $minimumSettled];
        }
        $criteria = ['minimum_live_shadow_observations' => $minimumObservations, 'minimum_settled_shadow_decisions' => $minimumSettled,
            'availability_status' => 'observed_pregame', 'requires_pregame_safe_features' => true];
        $decision['live_shadow_evidence'] = ['passed' => $live === [] || $passed, 'markets' => $live, 'criteria' => $criteria];
        $decision['criteria']['live_shadow'] = $criteria;
        $decision['promoted_markets'] = $decision['requested_markets'] = [];
        foreach ($decision['markets'] as $market => $item) {
            $marketPassed = $live[$market]['passed'] ?? false;
            $decision['markets'][$market]['checks']['live_shadow_evidence'] = $marketPassed;
            $decision['markets'][$market]['promotion_ready'] = $item['eligible'] && $marketPassed;
            $decision['markets'][$market]['promoted'] = false;
            $decision['checks'][$market.'.live_shadow_evidence'] = $marketPassed;
        }

        return $decision;
    }
}
