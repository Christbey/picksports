<?php

namespace Database\Factories;

use App\Models\CanonicalPrediction;
use App\Models\SportEvent;
use App\Support\SportCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CanonicalPrediction> */
class CanonicalPredictionFactory extends Factory
{
    protected $model = CanonicalPrediction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $sport = fake()->randomElement(SportCatalog::ALL);

        return [
            'sport_event_id' => SportEvent::factory()->state(['sport' => $sport]),
            'sport' => $sport,
            'detail_source' => CanonicalPrediction::DETAIL_SOURCE_LEGACY_SPORT_PREDICTION,
            'detail_sport' => $sport,
            'detail_id' => fake()->unique()->numberBetween(1, 2_000_000_000),
            'status' => 'active',
            'model_version' => 'factory-v1',
            'generated_at' => now(),
        ];
    }
}
