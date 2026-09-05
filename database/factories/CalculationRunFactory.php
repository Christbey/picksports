<?php

namespace Database\Factories;

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalculationRun> */
class CalculationRunFactory extends Factory
{
    protected $model = CalculationRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sport_event_id' => SportEvent::factory(),
            'event_input_snapshot_id' => EventInputSnapshot::factory(),
            'calculation_release_id' => CalculationRelease::factory()->approved(),
            'phase' => 'pregame',
            'trigger' => 'test',
            'idempotency_key' => hash('sha256', fake()->uuid()),
            'status' => 'pending',
        ];
    }
}
