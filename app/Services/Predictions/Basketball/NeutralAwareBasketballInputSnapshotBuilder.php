<?php

namespace App\Services\Predictions\Basketball;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\SportEvent;
use App\Services\CBB\Predictions\CbbInputSnapshotBuilder;
use App\Services\NBA\Predictions\NbaInputSnapshotBuilder;
use App\Services\WCBB\Predictions\WcbbInputSnapshotBuilder;
use App\Services\WNBA\Predictions\WnbaInputSnapshotBuilder;

final class NeutralAwareBasketballInputSnapshotBuilder implements EventInputSnapshotBuilder
{
    private const DELEGATES = ['nba' => NbaInputSnapshotBuilder::class, 'cbb' => CbbInputSnapshotBuilder::class, 'wnba' => WnbaInputSnapshotBuilder::class, 'wcbb' => WcbbInputSnapshotBuilder::class];

    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        $delegate = self::DELEGATES[$event->sport] ?? null;
        if ($delegate === null || $release->semanticVersion !== '1.1.0' || $release->inputSchemaVersion !== $event->sport.'-pregame-neutral-v2'
            || data_get($release->configuration, 'elo.respect_neutral_site') !== true) {
            throw new PredictionLifecycleException('Neutral-aware snapshot requires its explicit compatible candidate policy.');
        }
        $base = app($delegate)->build($event, $release);
        $observed = $event->neutral_site;
        $context = [
            'observed_neutral_site' => $observed,
            'unknown_fallback' => 'legacy_home_context',
            'unknown_fallback_applied' => $observed === null,
            'risk_flags' => $observed === null ? ['neutral_site_unknown'] : [],
            'historical_availability_verified' => false,
            'observation_timestamp_policy' => 'current_capture_not_historical_availability',
        ];
        $inputs = $base->inputs;
        $inputs['event']['neutral_site'] = $observed;
        $inputs['event']['neutral_context'] = $context;

        return new EventInputSnapshotData(
            schemaVersion: $release->inputSchemaVersion, inputs: $inputs,
            capturedAt: $base->capturedAt, cutoffAt: $base->cutoffAt,
            latestSourceAvailableAt: $base->latestSourceAvailableAt, sourceTimestamps: $base->sourceTimestamps,
            pregameSafetyStatus: $base->pregameSafetyStatus, objectUri: $base->objectUri,
            metadata: [...$base->metadata, 'builder' => self::class, 'neutral_context' => $context],
            sqlClockToken: $base->sqlClockToken,
        );
    }
}
