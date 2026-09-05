<?php

namespace Database\Factories;

use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use App\Support\SportCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventInputSnapshot> */
class EventInputSnapshotFactory extends Factory
{
    protected $model = EventInputSnapshot::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $sport = fake()->randomElement(SportCatalog::ALL);
        $inputs = ['home_rating' => 1510.0, 'away_rating' => 1490.0];

        return [
            'sport_event_id' => SportEvent::factory()->state(['sport' => $sport]),
            'sport' => $sport,
            'phase' => 'pregame',
            'schema_version' => 'core-v1',
            'captured_at' => now(),
            'cutoff_at' => now(),
            'latest_source_available_at' => now()->subMinute(),
            'source_timestamps' => ['ratings' => now()->subMinute()->toIso8601String()],
            'inputs' => $inputs,
            'content_hash' => hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR)),
            'pregame_safety_status' => 'verified',
        ];
    }
}
