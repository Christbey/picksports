<?php

namespace Database\Factories;

use App\Models\SportEvent;
use App\Support\SportCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SportEvent>
 */
class SportEventFactory extends Factory
{
    protected $model = SportEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sport' => $this->faker->randomElement(SportCatalog::ALL),
            'season' => $this->faker->numberBetween(2020, 2027),
            'season_type' => 'regular',
            'week' => $this->faker->numberBetween(1, 40),
            'starts_at' => $this->faker->dateTimeBetween('-1 year', '+1 year'),
            'name' => $this->faker->words(4, true),
            'short_name' => $this->faker->words(2, true),
            'status' => 'STATUS_SCHEDULED',
            'neutral_site' => false,
        ];
    }
}
