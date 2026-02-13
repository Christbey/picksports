<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NBA\Player>
 */
class NbaPlayerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = \App\Models\NBA\Player::class;

    public function definition(): array
    {
        return [
            'espn_id' => $this->faker->unique()->numerify('#######'),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'full_name' => fn (array $attributes) => $attributes['first_name'].' '.$attributes['last_name'],
            'jersey_number' => $this->faker->numberBetween(0, 99),
            'position' => $this->faker->randomElement(['G', 'F', 'C', 'G-F', 'F-C']),
            'height' => $this->faker->numerify('#-##'),
            'weight' => $this->faker->numberBetween(160, 280),
            'year' => $this->faker->randomElement(['Rookie', 'Sophomore', 'Junior', 'Senior']),
            'hometown' => $this->faker->city().', '.$this->faker->stateAbbr(),
            'headshot_url' => $this->faker->imageUrl(),
        ];
    }
}
