<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NFL\Player>
 */
class NflPlayerFactory extends Factory
{
    protected $model = \App\Models\NFL\Player::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'espn_id' => $this->faker->unique()->numerify('#######'),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'full_name' => fn (array $attributes) => $attributes['first_name'].' '.$attributes['last_name'],
            'jersey_number' => $this->faker->numberBetween(1, 99),
            'position' => $this->faker->randomElement(['QB', 'RB', 'WR', 'TE', 'OL', 'DL', 'LB', 'DB', 'K', 'P']),
            'height' => $this->faker->numberBetween(65, 85),
            'weight' => $this->faker->numberBetween(170, 350),
            'age' => $this->faker->numberBetween(21, 40),
            'experience' => $this->faker->numberBetween(0, 15),
            'college' => $this->faker->randomElement(['Alabama', 'Ohio State', 'Clemson', 'Georgia', 'LSU']),
            'status' => $this->faker->randomElement(['Active', 'Injured', 'Reserve']),
            'headshot_url' => $this->faker->imageUrl(),
        ];
    }
}
