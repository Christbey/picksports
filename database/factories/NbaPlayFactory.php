<?php

namespace Database\Factories;

use App\Models\NBA\Play;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Play>
 */
class NbaPlayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Play::class;

    public function definition(): array
    {
        return [
            'espn_play_id' => $this->faker->unique()->numerify('##########'),
            'sequence_number' => $this->faker->numberBetween(1, 300),
            'period' => $this->faker->numberBetween(1, 4),
            'clock' => $this->faker->time('i:s'),
            'play_type' => $this->faker->randomElement(['Made Shot', 'Missed Shot', 'Free Throw', 'Rebound', 'Turnover', 'Foul']),
            'play_text' => $this->faker->sentence(),
            'score_value' => $this->faker->randomElement([0, 1, 2, 3]),
            'shooting_play' => $this->faker->boolean(40),
            'made_shot' => $this->faker->boolean(45),
            'assist' => $this->faker->boolean(30),
            'is_turnover' => $this->faker->boolean(10),
            'is_foul' => $this->faker->boolean(15),
            'home_score' => $this->faker->numberBetween(0, 120),
            'away_score' => $this->faker->numberBetween(0, 120),
        ];
    }
}
