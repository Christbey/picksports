<?php

namespace App\Services\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Contracts\Predictions\SportCalculator;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PredictionLifecycleOrchestrator
{
    public function __construct(
        private readonly CalculationReleaseSelector $releaseSelector,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly PredictionPublisher $publisher,
    ) {}

    public function generate(
        SportEvent $event,
        EventInputSnapshotBuilder $snapshotBuilder,
        SportCalculator $calculator,
        string $phase = 'pregame',
        string $trigger = 'scheduled',
        ?CarbonImmutable $effectiveAt = null,
    ): CanonicalPrediction {
        if (! $event->exists) {
            throw new PredictionLifecycleException('Prediction generation requires a persisted canonical event.');
        }

        $release = $this->releaseSelector->select($event, $phase, $effectiveAt);
        $releaseData = CalculationReleaseData::fromModel($release);
        $snapshotData = $snapshotBuilder->build($event, $releaseData);
        $this->validateSnapshot($event, $releaseData, $snapshotData);

        $snapshot = $this->persistSnapshot($event, $phase, $snapshotData);
        $idempotencyKey = $this->hasher->hash([
            'event' => $event->public_id,
            'phase' => $phase,
            'release' => $release->public_id,
            'snapshot' => $snapshot->content_hash,
        ]);
        $run = $this->claimRun($event, $snapshot, $release->getKey(), $phase, $trigger, $idempotencyKey);

        if (! $run->wasRecentlyCreated) {
            $prediction = $run->prediction;

            if ($run->status === 'succeeded' && $prediction !== null) {
                return $prediction->loadMissing('markets', 'calculationRun.release', 'calculationRun.inputSnapshot');
            }

            throw new PredictionLifecycleException("Calculation run {$run->id} already exists with status {$run->status}.");
        }

        try {
            $output = $calculator->calculate($snapshotData, $releaseData);
            $outputHash = $this->hasher->hash($output->hashablePayload());

            return $this->persistPrediction($event, $run, $releaseData, $phase, $output, $outputHash);
        } catch (Throwable $exception) {
            $this->markFailed($run, $exception);

            throw $exception;
        }
    }

    public function generateAndPublish(
        SportEvent $event,
        EventInputSnapshotBuilder $snapshotBuilder,
        SportCalculator $calculator,
        string $phase = 'pregame',
        string $trigger = 'scheduled',
        ?CarbonImmutable $effectiveAt = null,
    ): CanonicalPrediction {
        return $this->publisher->publish($this->generate(
            $event,
            $snapshotBuilder,
            $calculator,
            $phase,
            $trigger,
            $effectiveAt,
        ));
    }

    private function validateSnapshot(
        SportEvent $event,
        CalculationReleaseData $release,
        EventInputSnapshotData $snapshot,
    ): void {
        if ($release->sport !== $event->sport || $snapshot->schemaVersion !== $release->inputSchemaVersion) {
            throw new PredictionLifecycleException('Snapshot event, sport, and schema must match the selected calculation release.');
        }

        if ($snapshot->inputs === []) {
            throw new PredictionLifecycleException('Input snapshots cannot be empty.');
        }

        if ($release->phase === 'pregame') {
            if ($snapshot->pregameSafetyStatus !== 'verified' || $snapshot->cutoffAt === null) {
                throw new PredictionLifecycleException('Pregame snapshots require a verified safety status and cutoff.');
            }

            if ($snapshot->capturedAt->isAfter($snapshot->cutoffAt)
                || $snapshot->latestSourceAvailableAt?->isAfter($snapshot->cutoffAt)) {
                throw new PredictionLifecycleException('Pregame snapshot timing exceeds its prediction cutoff.');
            }
        }
    }

    private function persistSnapshot(
        SportEvent $event,
        string $phase,
        EventInputSnapshotData $snapshot,
    ): EventInputSnapshot {
        $contentHash = $this->hasher->hash([
            'schema_version' => $snapshot->schemaVersion,
            'phase' => $phase,
            'inputs' => $snapshot->inputs,
            'source_timestamps' => $snapshot->sourceTimestamps,
            'cutoff_at' => $snapshot->cutoffAt,
            'latest_source_available_at' => $snapshot->latestSourceAvailableAt,
        ]);

        return EventInputSnapshot::query()->firstOrCreate(
            [
                'sport_event_id' => $event->getKey(),
                'phase' => $phase,
                'schema_version' => $snapshot->schemaVersion,
                'content_hash' => $contentHash,
            ],
            [
                'sport' => $event->sport,
                'captured_at' => $snapshot->capturedAt,
                'cutoff_at' => $snapshot->cutoffAt,
                'latest_source_available_at' => $snapshot->latestSourceAvailableAt,
                'source_timestamps' => $snapshot->sourceTimestamps,
                'inputs' => $snapshot->inputs,
                'object_uri' => $snapshot->objectUri,
                'pregame_safety_status' => $snapshot->pregameSafetyStatus,
                'metadata' => $snapshot->metadata,
            ],
        );
    }

    private function claimRun(
        SportEvent $event,
        EventInputSnapshot $snapshot,
        int $releaseId,
        string $phase,
        string $trigger,
        string $idempotencyKey,
    ): CalculationRun {
        try {
            return CalculationRun::query()->create([
                'id' => (string) Str::uuid(),
                'sport_event_id' => $event->getKey(),
                'event_input_snapshot_id' => $snapshot->getKey(),
                'calculation_release_id' => $releaseId,
                'phase' => $phase,
                'trigger' => trim($trigger),
                'idempotency_key' => $idempotencyKey,
                'status' => 'running',
                'started_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $existing = CalculationRun::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    private function persistPrediction(
        SportEvent $event,
        CalculationRun $run,
        CalculationReleaseData $release,
        string $phase,
        PredictionOutput $output,
        string $outputHash,
    ): CanonicalPrediction {
        return DB::transaction(function () use ($event, $run, $release, $phase, $output, $outputHash): CanonicalPrediction {
            SportEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $run = CalculationRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($run->status !== 'running') {
                throw new PredictionLifecycleException("Calculation run {$run->id} is not available for persistence.");
            }

            $latest = CanonicalPrediction::query()
                ->where('sport_event_id', $event->getKey())
                ->where('phase', $phase)
                ->orderByDesc('revision')
                ->first();

            $prediction = CanonicalPrediction::query()->create([
                'sport_event_id' => $event->getKey(),
                'calculation_run_id' => $run->getKey(),
                'sport' => $event->sport,
                'revision' => ($latest?->revision ?? 0) + 1,
                'supersedes_prediction_id' => $latest?->getKey(),
                'phase' => $phase,
                'publication_state' => 'draft',
                'output_hash' => $outputHash,
                'output_metadata' => $output->metadata,
                'detail_source' => null,
                'detail_sport' => null,
                'detail_id' => null,
                'status' => 'active',
                'model_version' => $release->semanticVersion,
                'feature_version' => $release->inputSchemaVersion,
                'generated_at' => $output->generatedAt ?? now(),
            ]);

            foreach ($output->markets as $market) {
                $this->createMarket($prediction, $market);
            }

            $run->update([
                'status' => 'succeeded',
                'completed_at' => now(),
                'output_hash' => $outputHash,
                'diagnostics' => $output->diagnostics,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return $prediction->load('markets', 'calculationRun.release', 'calculationRun.inputSnapshot');
        });
    }

    private function createMarket(CanonicalPrediction $prediction, PredictionMarketOutput $market): void
    {
        $prediction->markets()->create([
            ...$market->toArray(),
            'is_primary' => true,
        ]);
    }

    private function markFailed(CalculationRun $run, Throwable $exception): void
    {
        $run = CalculationRun::query()->find($run->getKey());

        if ($run === null || $run->status === 'succeeded') {
            return;
        }

        $run->update([
            'status' => 'failed',
            'completed_at' => now(),
            'failure_code' => class_basename($exception),
            'failure_message' => Str::limit($exception->getMessage(), 2000, ''),
        ]);
    }
}
