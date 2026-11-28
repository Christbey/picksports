<?php

namespace App\Services\Predictions\Basketball;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\SportEvent;
use App\Services\CBB\Predictions\CbbCalculationReleaseDefinition;
use App\Services\CBB\Predictions\CbbFullModelReleaseDefinition;
use App\Services\NBA\Predictions\NbaCalculationReleaseDefinition;
use App\Services\NBA\Predictions\NbaFullModelReleaseDefinition;
use App\Services\WCBB\Predictions\WcbbCalculationReleaseDefinition;
use App\Services\WCBB\Predictions\WcbbFullModelReleaseDefinition;
use App\Services\WNBA\Predictions\WnbaCalculationReleaseDefinition;
use App\Services\WNBA\Predictions\WnbaFullModelReleaseDefinition;

/** Compiled policy dispatch only: persisted metadata never names an executable class. */
final class BasketballPolicyInputSnapshotBuilder implements EventInputSnapshotBuilder
{
    private const LEGACY_SCHEMAS = ['nba' => NbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
        'cbb' => CbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION, 'wnba' => WnbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION, 'wcbb' => WcbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION];

    private const FULL_SCHEMAS = ['nba' => NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
        'cbb' => CbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION, 'wnba' => WnbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION, 'wcbb' => WcbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION];

    public function __construct(private readonly EventInputSnapshotBuilder $legacy, private readonly string $sport) {}

    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        if ($event->sport !== $this->sport || $release->sport !== $this->sport || ! isset(self::LEGACY_SCHEMAS[$this->sport])) {
            throw new PredictionLifecycleException('Basketball policy adapter received an incompatible sport.');
        }
        if (in_array($release->inputSchemaVersion, [self::LEGACY_SCHEMAS[$this->sport], self::FULL_SCHEMAS[$this->sport]], true)) {
            return $this->legacy->build($event, $release);
        }
        if ($release->inputSchemaVersion === $this->sport.'-pregame-neutral-v2') {
            return app(NeutralAwareBasketballInputSnapshotBuilder::class)->build($event, $release);
        }

        throw new PredictionLifecycleException('Unsupported basketball input schema.');
    }
}
