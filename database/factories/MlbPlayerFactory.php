<?php

namespace Database\Factories;

use App\Models\MLB\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class MlbPlayerFactory extends Factory
{
    protected $model = Player::class;

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
            'jersey_number' => $this->faker->numberBetween(0, 99),
            'position' => $this->faker->randomElement(['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF', 'DH']),
            'batting_hand' => $this->faker->randomElement(['R', 'L', 'S']),
            'throwing_hand' => $this->faker->randomElement(['R', 'L']),
            'height' => $this->faker->numerify('#-##'),
            'weight' => $this->faker->numberBetween(160, 260),
            'hometown' => $this->faker->city().', '.$this->faker->stateAbbr(),
            'headshot_url' => $this->faker->imageUrl(),
            'elo_rating' => config('mlb.elo.default_rating'),
        ];
    }

    public function pitcher(): static
    {
        return $this->state(fn () => [
            'position' => 'P',
            'throwing_hand' => $this->faker->randomElement(['R', 'L']),
        ]);
    }
}
