<?php

namespace App\Services\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Contracts\Predictions\SportCalculator;
use App\Exceptions\EloAdmissionRefused;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\ApplicationSetting;
use App\Models\CalculationRun;
use App\Models\CalculationRunAttempt;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use App\Services\Sports\Elo\EloAdmissionMode;
use App\Services\Sports\Elo\EloFixedScale;
use App\Services\Sports\Elo\EloSqlClockPolicy;
use App\Services\Sports\Elo\EloSqlClockToken;
use App\Services\Sports\Elo\EloWriterProtocol;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class PredictionLifecycleOrchestrator
{
    public function __construct(
        private readonly CalculationReleaseSelector $releaseSelector,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly PredictionPublisher $publisher,
        private readonly CalculationRunRecovery $recovery,
    ) {}

    public function generateForGame(
        Model $game,
        string $sport,
        EventInputSnapshotBuilder $snapshotBuilder,
        SportCalculator $calculator,
        bool $publish = true,
        string $trigger = 'scheduled',
    ): CanonicalPrediction {
        $event = $game->sportEvent;

        if ($event === null) {
            throw new PredictionLifecycleException(strtoupper($sport).' canonical generation requires a linked sport event.');
        }

        return $publish
            ? $this->generateAndPublish($event, $snapshotBuilder, $calculator, trigger: $trigger)
            : $this->generate($event, $snapshotBuilder, $calculator, trigger: $trigger);
    }

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

        $clock = $this->captureManagedClock($event);
        $release = $this->releaseSelector->select($event, $phase, $effectiveAt);
        $releaseData = CalculationReleaseData::fromModel($release);
        $snapshotData = $snapshotBuilder->build($event, $releaseData);
        $this->validateSnapshot($event, $releaseData, $snapshotData);

        $snapshot = $this->persistSnapshot($event, $phase, $snapshotData, $clock);
        $this->assertClock($clock, $event);
        $idempotencyKey = $this->hasher->hash([
            'event' => $event->public_id,
            'phase' => $phase,
            'release' => $release->public_id,
            'snapshot' => $snapshot->content_hash,
        ]);
        [$run, $attempt] = $this->recovery->claim($event, $snapshot, $release->getKey(), $phase, $trigger, $idempotencyKey);

        if ($attempt === null) {
            $this->assertClock($clock, $event);
            $prediction = $run->prediction;
            if ($run->status === 'succeeded' && $prediction !== null) {
                $prediction->loadMissing('markets', 'calculationRun.release', 'calculationRun.inputSnapshot');
                $this->assertClock($clock, $event);

                return $prediction;
            }

            throw new PredictionLifecycleException("Successful calculation run {$run->id} has no prediction.");
        }

        try {
            $this->assertClock($clock, $event);
            $output = $calculator->calculate($snapshotData, $releaseData);
            $outputHash = $this->hasher->hash($output->hashablePayload());

            return $this->persistPrediction($event, $run, $attempt, $releaseData, $phase, $output, $outputHash, $clock);
        } catch (Throwable $exception) {
            $safeFailureConnection = true;
            try {
                $this->assertClock($clock, $event);
            } catch (EloAdmissionRefused) {
                // Do not write an audit into a drifted target/session. The durable lease recovers this attempt.
                $safeFailureConnection = false;
            }
            if ($safeFailureConnection) {
                $this->recovery->fail($run, $attempt, $exception);
            }

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

    private function captureManagedClock(SportEvent $event): ?EloSqlClockToken
    {
        $connection = $event->getConnection();
        if (! app(EloAdmissionMode::class)->managed($event->sport, $connection)) {
            return null;
        }
        app(EloWriterProtocol::class)->requireCapability($event->sport);
        if ($connection !== DB::connection()) {
            throw new EloAdmissionRefused('sql_clock_mixed_source_connection', [], []);
        }

        return app(EloSqlClockPolicy::class)->capture($connection);
    }

    private function assertClock(?EloSqlClockToken $clock, SportEvent $event): void
    {
        if ($clock !== null) {
            if ($event->getConnection() !== DB::connection()) {
                throw new EloAdmissionRefused('sql_clock_mixed_source_connection', [], []);
            }
            app(EloSqlClockPolicy::class)->assertUnchanged($clock, $event->getConnection());
        }
    }

    /** Stable actor/source evidence qualifies identity; a fresh request time never renews a snapshot. */
    private function managedProvenance(SportEvent $event, EventInputSnapshotData $snapshot, ?EloSqlClockToken $clock): ?array
    {
        $evidence = data_get($snapshot->metadata, 'elo_input_admission');
        $managed = app(EloAdmissionMode::class)->managed($event->sport, $event->getConnection());
        if (! $managed && $evidence === null && $snapshot->sqlClockToken === null && $clock === null) {
            return null;
        }
        if ($clock === null || $snapshot->sqlClockToken === null || ! $clock->checksRead() || ! $snapshot->sqlClockToken->checksRead() || ! is_array($evidence)
            || ($evidence['sport'] ?? null) !== $event->sport
            || (int) ($evidence['game_id'] ?? 0) !== (int) data_get($snapshot->inputs, 'event.game_id')
            || empty($evidence['heads']) || ! is_string($evidence['configuration_hash'] ?? null)
            || ! is_string($evidence['dependency_plan_hash'] ?? null)
            || ! EloSqlClockPolicy::matchesPortablePolicy(data_get($snapshot->metadata, 'elo_sql_clock_policy'))) {
            throw new EloAdmissionRefused('managed_snapshot_clock_or_provenance_missing', [], []);
        }
        $this->assertClock($clock, $event);
        app(EloSqlClockPolicy::class)->assertUnchanged($snapshot->sqlClockToken, $event->getConnection());
        if ($snapshot->cutoffAt === null
            || ($evidence['cutoff_at_utc'] ?? null) !== $snapshot->cutoffAt->utc()->format('Y-m-d\TH:i:s.u\Z')) {
            throw new EloAdmissionRefused('managed_snapshot_cutoff_provenance_mismatch', [], []);
        }
        foreach (['home', 'away'] as $side) {
            $teamId = (int) data_get($snapshot->inputs, $side.'.team_id');
            $head = $evidence['heads'][$teamId] ?? null;
            if ($teamId < 1 || ! is_array($head)
                || app(EloFixedScale::class)->rating(data_get($snapshot->inputs, $side.'.elo')) !== ($head['rating'] ?? null)) {
                throw new EloAdmissionRefused('managed_snapshot_value_provenance_mismatch', [], []);
            }
        }
        unset($evidence['admitted_at_utc']);

        return ['version' => 'managed-snapshot-provenance-v1', 'elo_input_admission' => $evidence,
            'sql_clock_policy' => EloSqlClockPolicy::policy()];
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

            if ($snapshot->latestSourceAvailableAt?->greaterThan($snapshot->capturedAt)) {
                throw new PredictionLifecycleException('Pregame source availability cannot exceed snapshot capture time.');
            }

            if ($event->starts_at === null || $snapshot->cutoffAt->greaterThan($event->starts_at)
                || $snapshot->capturedAt->isFuture()) {
                throw new PredictionLifecycleException('Pregame snapshot cutoff must not exceed kickoff and capture cannot be in the future.');
            }
        }
    }

    private function persistSnapshot(
        SportEvent $event,
        string $phase,
        EventInputSnapshotData $snapshot,
        ?EloSqlClockToken $clock = null,
    ): EventInputSnapshot {
        $provenance = $this->managedProvenance($event, $snapshot, $clock);
        $contentHash = $this->hasher->hash([
            'schema_version' => $snapshot->schemaVersion,
            'phase' => $phase,
            'inputs' => $snapshot->inputs,
            'source_timestamps' => $snapshot->sourceTimestamps,
            'cutoff_at' => $snapshot->cutoffAt,
            'latest_source_available_at' => $snapshot->latestSourceAvailableAt,
            ...($provenance === null ? [] : ['managed_input_provenance' => $provenance]),
        ]);

        $persist = function () use ($event, $phase, $snapshot, $contentHash, $clock, $provenance): EventInputSnapshot {
            $this->assertClock($clock, $event);
            if ($snapshot->sqlClockToken !== null) {
                app(EloSqlClockPolicy::class)->assertUnchanged($snapshot->sqlClockToken, $event->getConnection());
            }
            $stored = EventInputSnapshot::query()->firstOrCreate(
                [
                    'sport_event_id' => $event->getKey(),
                    'phase' => $phase,
                    'schema_version' => $snapshot->schemaVersion,
                    'content_hash' => $contentHash,
                ],
                fn (): array => [
                    'sport' => $event->sport,
                    'captured_at' => $snapshot->capturedAt,
                    'cutoff_at' => $snapshot->cutoffAt,
                    'latest_source_available_at' => $snapshot->latestSourceAvailableAt,
                    'source_timestamps' => $snapshot->sourceTimestamps,
                    'inputs' => $event->sport === 'cfb'
                        && ApplicationSetting::getValue(CfbSnapshotInputStorage::WRITES_SETTING, false) === true
                        ? app(CfbSnapshotInputStorage::class)->prepare($snapshot->inputs)
                        : $snapshot->inputs,
                    'object_uri' => $snapshot->objectUri,
                    'pregame_safety_status' => $snapshot->pregameSafetyStatus,
                    'metadata' => $snapshot->metadata,
                ],
            );
            if ($provenance !== null) {
                $storedEvidence = data_get($stored->metadata, 'elo_input_admission');
                if (! is_array($storedEvidence) || ! EloSqlClockPolicy::matchesPortablePolicy(data_get($stored->metadata, 'elo_sql_clock_policy'))) {
                    throw new EloAdmissionRefused('managed_snapshot_existing_provenance_missing', [], []);
                }
                unset($storedEvidence['admitted_at_utc']);
                if ($this->hasher->hash($storedEvidence) !== $this->hasher->hash($provenance['elo_input_admission'])) {
                    throw new EloAdmissionRefused('managed_snapshot_existing_provenance_mismatch', [], []);
                }
            }
            $this->assertClock($clock, $event);

            return $stored;
        };

        return $clock === null ? $persist() : $event->getConnection()->transaction($persist);
    }

    private function persistPrediction(
        SportEvent $event,
        CalculationRun $run,
        CalculationRunAttempt $attempt,
        CalculationReleaseData $release,
        string $phase,
        PredictionOutput $output,
        string $outputHash,
        ?EloSqlClockToken $clock = null,
    ): CanonicalPrediction {
        return DB::transaction(function () use ($event, $run, $attempt, $release, $phase, $output, $outputHash, $clock): CanonicalPrediction {
            $this->assertClock($clock, $event);
            $event = SportEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $run = CalculationRun::query()->lockForUpdate()->findOrFail($run->getKey());

            $attempt = $this->recovery->assertOwner($run, $attempt);
            $this->recovery->assertPregameOpen($event, $phase);

            $latest = CanonicalPrediction::query()
                ->where('sport_event_id', $event->getKey())
                ->where('phase', $phase)
                ->orderByDesc('revision')
                ->lockForUpdate()
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

            $attempt->update([
                'status' => 'succeeded', 'completed_at' => now(), 'output_hash' => $outputHash,
            ]);
            $run->update([
                'status' => 'succeeded',
                'completed_at' => now(),
                'output_hash' => $outputHash,
                'diagnostics' => $output->diagnostics,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            $this->assertClock($clock, $event);

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
}
