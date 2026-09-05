<?php

namespace Database\Factories;

use App\Models\SportEvent;
use App\Models\SportEventResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SportEventResult> */
class SportEventResultFactory extends Factory
{
    protected $model = SportEventResult::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $scores = [fake()->numberBetween(60, 110), fake()->numberBetween(60, 110)];

        return [
            'sport_event_id' => SportEvent::factory(),
            'revision' => 1,
            'status' => 'official',
            'home_score' => $scores[0],
            'away_score' => $scores[1],
            'source' => 'factory',
            'source_reference' => fake()->uuid(),
            'result_hash' => hash('sha256', implode(':', [...$scores, fake()->uuid()])),
            'observed_at' => now(),
            'finalized_at' => now(),
        ];
    }
}
