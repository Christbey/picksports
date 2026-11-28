<?php

namespace App\Services\Predictions\Basketball;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\SportCalculator;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Services\CBB\Predictions\CbbCalculationReleaseDefinition;
use App\Services\CBB\Predictions\CbbFullModelReleaseDefinition;
use App\Services\NBA\Predictions\NbaCalculationReleaseDefinition;
use App\Services\NBA\Predictions\NbaFullModelReleaseDefinition;
use App\Services\WCBB\Predictions\WcbbCalculationReleaseDefinition;
use App\Services\WCBB\Predictions\WcbbFullModelReleaseDefinition;
use App\Services\WNBA\Predictions\WnbaCalculationReleaseDefinition;
use App\Services\WNBA\Predictions\WnbaFullModelReleaseDefinition;

/** Candidate layouts retain the tested legacy basketball math; only venue policy is new. */
final class BasketballPolicyCalculator implements SportCalculator
{
    private const LEGACY_SCHEMAS = ['nba' => NbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
        'cbb' => CbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION, 'wnba' => WnbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION, 'wcbb' => WcbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION];

    private const FULL_SCHEMAS = ['nba' => NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
        'cbb' => CbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION, 'wnba' => WnbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION, 'wcbb' => WcbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION];

    public function __construct(private readonly SportCalculator $legacy, private readonly string $sport) {}

    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
    {
        $legacySchema = self::LEGACY_SCHEMAS[$this->sport] ?? null;
        if ($legacySchema === null || $release->sport !== $this->sport || $release->phase !== 'pregame'
            || $snapshot->schemaVersion !== $release->inputSchemaVersion) {
            throw new PredictionLifecycleException('Basketball calculator policy received an incompatible release or snapshot.');
        }
        if (in_array($release->inputSchemaVersion, [$legacySchema, self::FULL_SCHEMAS[$this->sport]], true)) {
            return $this->legacy->calculate($snapshot, $release);
        }
        $candidateSchema = $this->sport.'-pregame-neutral-v2';
        $event = data_get($snapshot->inputs, 'event', []);
        $observed = is_array($event) ? ($event['neutral_site'] ?? null) : null;
        if ($release->inputSchemaVersion !== $candidateSchema || $release->semanticVersion !== '1.1.0'
            || data_get($release->configuration, 'elo.respect_neutral_site') !== true
            || ! is_array($event) || ! array_key_exists('neutral_site', $event)
            || ($observed !== null && ! is_bool($observed))) {
            throw new PredictionLifecycleException('Neutral-aware candidate requires fixed 1.1.0 policy and a boolean or null observation.');
        }
        $internal = new EventInputSnapshotData(
            schemaVersion: $legacySchema, inputs: $snapshot->inputs,
            capturedAt: $snapshot->capturedAt, cutoffAt: $snapshot->cutoffAt,
            latestSourceAvailableAt: $snapshot->latestSourceAvailableAt, sourceTimestamps: $snapshot->sourceTimestamps,
            pregameSafetyStatus: $snapshot->pregameSafetyStatus, objectUri: $snapshot->objectUri, metadata: $snapshot->metadata,
        );
        $output = $this->legacy->calculate($internal, $release);

        return new PredictionOutput($output->markets, $output->metadata, [...$output->diagnostics,
            'neutral_policy' => ['version' => 'observed-neutral-v2', 'snapshot_schema' => $candidateSchema,
                'internal_math_schema' => $legacySchema, 'observed_neutral_site' => $observed,
                'unknown_fallback_applied' => $observed === null, 'historical_availability_verified' => false]], $output->generatedAt);
    }
}
