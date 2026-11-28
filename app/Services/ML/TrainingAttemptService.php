<?php

namespace App\Services\ML;

use App\Models\ModelRun;
use App\Models\ModelTrainingAttempt;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbTabularSourceFence;
use App\Services\MLB\MlbTrainingCompletionState;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Locks cycle then latest attempt. Python execution always runs outside the transaction. */
class TrainingAttemptService
{
    public function claim(string $cycleId, int $leaseSeconds): ModelTrainingAttempt
    {
        [$attempt, $refusal] = DB::transaction(function () use ($cycleId, $leaseSeconds): array {
            $cycle = ModelRun::query()->lockForUpdate()->findOrFail($cycleId);
            if ($cycle->run_type !== 'weekly_training_cycle' || ! in_array($cycle->status, ['running', 'failed'], true)) {
                throw new RuntimeException('Training cycle cannot be claimed.');
            }
            $latest = $this->latest($cycleId);
            if ($latest?->status === 'succeeded') {
                throw new RuntimeException('Training cycle already has a completed attempt.');
            }
            if ($latest?->status === 'running') {
                if ($latest->lease_expires_at->isFuture()) {
                    throw new RuntimeException('Training cycle already has an active attempt.');
                }
                $latest->update(['status' => 'abandoned', 'completed_at' => now(), 'failure_message' => 'Training attempt lease expired.']);
            }
            if (($latest?->attempt_number ?? 0) >= max(1, (int) config('ml.training.maximum_attempts', 3))) {
                $cycle->update(['status' => 'failed', 'completed_at' => now()]);

                return [null, 'Training attempt limit reached.'];
            }
            $attempt = ModelTrainingAttempt::query()->create([
                'cycle_run_id' => $cycleId,
                'attempt_number' => ($latest?->attempt_number ?? 0) + 1,
                'owner_token' => (string) Str::uuid(),
                'python_run_id' => (string) Str::uuid(),
                'status' => 'running',
                'started_at' => now(),
                'lease_expires_at' => now()->addSeconds(max(1, $leaseSeconds)),
            ]);
            $cycle->update(['status' => 'running', 'completed_at' => null, 'metadata' => [
                ...(array) $cycle->metadata, 'stage' => 'python_training', 'attempt_id' => $attempt->id,
                'model_run_id' => $attempt->python_run_id,
            ]]);

            return [$attempt, null];
        });
        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        return $attempt;
    }

    /** @param Closure(): array<string, mixed> $persist @return array<string, mixed> */
    public function complete(ModelTrainingAttempt $owner, string $outputHash, Closure $persist): array
    {
        return $this->completeUsing($owner, $outputHash, $persist);
    }

    /** DB-only staged entry; arbitrary inherited transactions cannot acquire this authority. */
    public function completeMlb(ModelTrainingAttempt $owner, string $outputHash, array $sourceProof, Closure $persist): array
    {
        $this->assertMlbOwned($owner, $sourceProof);

        return $this->completeUsing($owner, $outputHash, $persist, $sourceProof);
    }

    private function assertMlbOwned(ModelTrainingAttempt $owner, array $proof): void
    {
        $connection = $owner->getConnection();
        if ((new ModelRun)->getConnection() !== $connection) {
            throw new RuntimeException('MLB completion requires one coherent target connection.');
        }
        MlbTabularSourceFence::assertOwned($connection, $proof);
        MlbArtifactCohortSerializer::assertOwned($connection);
    }

    private function completeUsing(ModelTrainingAttempt $owner, string $outputHash, Closure $persist, ?array $sourceProof = null): array
    {
        $connection = $sourceProof === null ? DB::connection() : $owner->getConnection();

        return $connection->transaction(function () use ($owner, $outputHash, $persist, $sourceProof): array {
            if ($sourceProof !== null) {
                $this->assertMlbOwned($owner, $sourceProof);
            }
            $cycle = $sourceProof === null
                ? ModelRun::query()->lockForUpdate()->findOrFail($owner->cycle_run_id)
                : MlbTrainingCompletionState::cycle($owner->getConnection(), $owner->cycle_run_id);
            $attempt = $sourceProof === null ? $this->owned($owner) : $this->ownedMlb($owner);
            if ($attempt->status === 'succeeded') {
                if (! hash_equals((string) $attempt->output_hash, $outputHash)) {
                    throw new RuntimeException('Conflicting training completion.');
                }

                return $attempt->result;
            }
            if ($cycle->sport === 'mlb' && $sourceProof === null) {
                throw new RuntimeException('MLB training completion requires its staged source-first entry.');
            }
            $this->assertActive($attempt);
            $result = $persist();
            // A slow registration cannot commit after its lease expires.
            $this->assertActive($attempt);
            $attempt->update(['status' => 'succeeded', 'output_hash' => $outputHash, 'result' => $result, 'completed_at' => now()]);
            $cycle = $sourceProof === null ? $cycle->refresh()
                : MlbTrainingCompletionState::cycle($owner->getConnection(), $owner->cycle_run_id);
            $cycle->update(['status' => 'completed', 'completed_at' => now(), 'metadata' => [
                ...(array) $cycle->metadata, ...$result, 'stage' => 'completed',
            ]]);

            if ($sourceProof !== null) {
                $this->assertMlbOwned($owner, $sourceProof);
            }

            return $result;
        });
    }

    private function ownedMlb(ModelTrainingAttempt $owner): ModelTrainingAttempt
    {
        $latest = MlbTrainingCompletionState::latestAttempt($owner->getConnection(), $owner->cycle_run_id);
        if ($latest?->id !== $owner->id || ! hash_equals($latest->owner_token, $owner->owner_token)) {
            throw new RuntimeException('Stale training attempt owner.');
        }

        return $latest;
    }

    /** Returns false for stale owners, which must not change a recovered cycle. */
    public function fail(ModelTrainingAttempt $owner, Throwable $error, ?string $failedStage = null): bool
    {
        return DB::transaction(function () use ($owner, $error, $failedStage): bool {
            $cycle = ModelRun::query()->lockForUpdate()->findOrFail($owner->cycle_run_id);
            $latest = $this->latest($owner->cycle_run_id);
            if ($latest?->id !== $owner->id || ! hash_equals($latest->owner_token, $owner->owner_token)
                || $latest->status !== 'running' || ! $latest->lease_expires_at->isFuture()) {
                return false;
            }
            $message = Str::limit($error->getMessage(), 8000, '');
            $latest->update(['status' => 'failed', 'completed_at' => now(), 'failure_message' => $message]);
            $cycle->update(['status' => 'failed', 'completed_at' => now(), 'metadata' => [
                ...(array) $cycle->metadata, 'failed_stage' => $failedStage ?? data_get($cycle->metadata, 'stage'),
                'stage' => 'failed', 'exception' => $error::class, 'error' => $message,
            ]]);

            return true;
        });
    }

    /** Observe an owned active attempt without altering its execution state or lease. */
    public function observe(ModelTrainingAttempt $owner, Closure $record): void
    {
        DB::transaction(function () use ($owner, $record): void {
            ModelRun::query()->lockForUpdate()->findOrFail($owner->cycle_run_id);
            $attempt = $this->owned($owner);
            $this->assertActive($attempt);
            $record();
        });
    }

    private function latest(string $cycleId): ?ModelTrainingAttempt
    {
        return ModelTrainingAttempt::query()->where('cycle_run_id', $cycleId)->orderByDesc('attempt_number')->lockForUpdate()->first();
    }

    private function owned(ModelTrainingAttempt $owner): ModelTrainingAttempt
    {
        $latest = $this->latest($owner->cycle_run_id);
        if ($latest?->id !== $owner->id || ! hash_equals($latest->owner_token, $owner->owner_token)) {
            throw new RuntimeException('Stale training attempt owner.');
        }

        return $latest;
    }

    private function assertActive(ModelTrainingAttempt $attempt): void
    {
        if ($attempt->status !== 'running' || ! $attempt->lease_expires_at->isFuture()) {
            throw new RuntimeException('Training attempt is no longer active.');
        }
    }
}
