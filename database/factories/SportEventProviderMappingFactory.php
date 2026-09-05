<?php

namespace Database\Factories;

use App\Models\SportEvent;
use App\Models\SportEventProviderMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SportEventProviderMapping>
 */
class SportEventProviderMappingFactory extends Factory
{
    protected $model = SportEventProviderMapping::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sport_event_id' => SportEvent::factory(),
            'provider' => 'espn',
            'provider_event_id' => $this->faker->unique()->numerify('#########'),
            'provider_uid' => $this->faker->unique()->numerify('s:20~l:28~e:#########'),
        ];
    }
}
