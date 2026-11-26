<?php

namespace Tests\Support;

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use Carbon\CarbonImmutable;

/** Explicit valid lineage for reader fixtures; adversarial tests construct their own evidence. */
class CanonicalReadFixture
{
    public static function run(SportEvent $event, array $inputs): CalculationRun
    {
        $captured = CarbonImmutable::now()->min(CarbonImmutable::instance($event->starts_at)->subMinute());
        $snapshot = EventInputSnapshot::factory()->create([
            'sport' => $event->sport, 'sport_event_id' => $event->id, 'phase' => 'pregame',
            'captured_at' => $captured, 'cutoff_at' => $captured,
            'latest_source_available_at' => $captured->subMinute(),
            'source_timestamps' => ['ratings' => $captured->subMinute()->toIso8601String()],
            'inputs' => $inputs, 'pregame_safety_status' => 'verified',
        ]);
        $release = CalculationRelease::factory()->approved()->create(['sport' => $event->sport, 'phase' => 'pregame']);

        return CalculationRun::factory()->create([
            'sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id,
            'calculation_release_id' => $release->id, 'status' => 'succeeded', 'phase' => 'pregame',
            'output_hash' => hash('sha256', 'reader-fixture-'.$event->id),
        ]);
    }
}
