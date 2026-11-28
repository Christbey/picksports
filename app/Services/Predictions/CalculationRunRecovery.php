<?php

namespace App\Services\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRun;
use App\Models\CalculationRunAttempt;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use App\Support\Sports\CanonicalEventStart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Owns short event/run/attempt transactions; calculators run outside these locks. */
class CalculationRunRecovery
{
    /** @return array{CalculationRun, CalculationRunAttempt|null} */
    public function claim(SportEvent $event, EventInputSnapshot $snapshot, int $releaseId, string $phase, string $trigger, string $idempotencyKey): array
    {
        [$run, $attempt, $refusal] = DB::transaction(function () use ($event, $snapshot, $releaseId, $phase, $trigger, $idempotencyKey): array {
            $lockedEvent = SportEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $run = CalculationRun::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($run?->status === 'succeeded') {
                return [$run, null, null];
            }
            $this->assertPregameOpen($lockedEvent, $phase);
            $run ??= CalculationRun::query()->create([
                'id' => (string) Str::uuid(),
                'sport_event_id' => $lockedEvent->getKey(),
                'event_input_snapshot_id' => $snapshot->getKey(),
                'calculation_release_id' => $releaseId,
                'phase' => $phase,
                'trigger' => trim($trigger),
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
            ]);
            $latest = $run->attempts()->orderByDesc('attempt_number')->lockForUpdate()->first();
            if ($latest === null && in_array($run->status, ['running', 'failed'], true)) {
                // Preserve pre-upgrade execution evidence; never treat a missing
                // lease/start timestamp as proof that an old worker has stopped.
                $expiresAt = $run->started_at?->addSeconds($this->leaseSeconds());
                if ($run->status === 'running' && ($expiresAt === null || $expiresAt->isFuture())) {
                    return [$run, null, 'Legacy calculation run is still running or has no safe recovery timestamp.'];
                }
                if ($run->status === 'running'
                    && ! (bool) config('prediction_lifecycle.calculation_recovery.allow_legacy_running_recovery', false)) {
                    return [$run, null, 'Legacy running recovery is disabled until old workers are verified drained.'];
                }
                $latest = $run->attempts()->create([
                    'attempt_number' => 1, 'owner_token' => (string) Str::uuid(),
                    'status' => $run->status === 'failed' ? 'failed' : 'abandoned',
                    'started_at' => $run->started_at, 'lease_expires_at' => $expiresAt,
                    'completed_at' => $run->completed_at ?? now(),
                    'failure_code' => $run->failure_code ?? 'legacy_lease_expired',
                    'failure_message' => $run->failure_message,
                    'legacy_import' => true,
                ]);
            }
            if ($latest?->status === 'abandoned' && $run->status === 'running') {
                $run->update(['status' => 'failed', 'completed_at' => now(), 'failure_code' => 'lease_expired']);
            }
            if ($latest?->status === 'running') {
                if ($latest->lease_expires_at === null || $latest->lease_expires_at->isFuture()) {
                    return [$run, null, 'Calculation run already has an active attempt lease.'];
                }
                $latest->update(['status' => 'abandoned', 'completed_at' => now(), 'failure_code' => 'lease_expired']);
                $run->update(['status' => 'failed', 'completed_at' => now(), 'failure_code' => 'lease_expired']);
            }
            if (($latest?->attempt_number ?? 0) >= $this->maximumAttempts()) {
                return [$run, null, 'Calculation run attempt limit reached.'];
            }
            if ($latest?->status === 'failed'
                && ($latest->completed_at ?? $latest->updated_at)->addSeconds($this->retrySeconds())->isFuture()) {
                return [$run, null, 'Calculation run retry delay has not elapsed.'];
            }
            $attempt = $run->attempts()->create([
                'attempt_number' => ($latest?->attempt_number ?? 0) + 1,
                'owner_token' => (string) Str::uuid(), 'status' => 'running',
                'started_at' => now(), 'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
            ]);
            $run->update([
                'status' => 'running', 'started_at' => now(), 'completed_at' => null,
                'failure_code' => null, 'failure_message' => null,
            ]);

            return [$run, $attempt, null];
        });
        if ($refusal !== null) {
            throw new PredictionLifecycleException($refusal);
        }

        return [$run, $attempt];
    }

    /** Called while holding the event and run locks, before any output is written. */
    public function assertOwner(CalculationRun $run, CalculationRunAttempt $claim): CalculationRunAttempt
    {
        $latest = $run->attempts()->orderByDesc('attempt_number')->lockForUpdate()->first();
        if ($run->status !== 'running' || $latest === null || $latest->getKey() !== $claim->getKey()
            || ! hash_equals($latest->owner_token, $claim->owner_token)
            || $latest->status !== 'running' || $latest->lease_expires_at === null || $latest->lease_expires_at->lte(now())) {
            throw new PredictionLifecycleException('Calculation attempt lease is expired or no longer owned.');
        }

        return $latest;
    }

    public function assertPregameOpen(SportEvent $event, string $phase): void
    {
        $start = ($event->sport === 'cfb' ? CanonicalEventStart::forCurrentCfbEvent($event, true) : CanonicalEventStart::forEvent($event, true));
        if ($phase === 'pregame' && ($start === null || now()->greaterThanOrEqualTo($start))) {
            throw new PredictionLifecycleException('Pregame calculation attempts must complete before the event starts.');
        }
    }

    public function fail(CalculationRun $run, CalculationRunAttempt $claim, Throwable $exception): void
    {
        DB::transaction(function () use ($run, $claim, $exception): void {
            SportEvent::query()->lockForUpdate()->findOrFail($run->sport_event_id);
            $lockedRun = CalculationRun::query()->lockForUpdate()->findOrFail($run->getKey());
            try {
                $attempt = $this->assertOwner($lockedRun, $claim);
            } catch (PredictionLifecycleException) {
                // A late worker cannot overwrite a newer owner or successful run.
                return;
            }
            $failure = [
                'status' => 'failed', 'completed_at' => now(),
                'failure_code' => class_basename($exception),
                'failure_message' => Str::limit($exception->getMessage(), 2000, ''),
            ];
            $attempt->update($failure);
            $lockedRun->update($failure);
        });
    }

    private function leaseSeconds(): int
    {
        return min(3600, max(30, (int) config('prediction_lifecycle.calculation_recovery.lease_seconds', 300)));
    }

    private function maximumAttempts(): int
    {
        return min(10, max(1, (int) config('prediction_lifecycle.calculation_recovery.max_attempts', 3)));
    }

    private function retrySeconds(): int
    {
        return min(3600, max(0, (int) config('prediction_lifecycle.calculation_recovery.retry_seconds', 60)));
    }
}
