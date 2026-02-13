<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NFL\Play>
 */
class NflPlayFactory extends Factory
{
    protected $model = \App\Models\NFL\Play::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'espn_play_id' => $this->faker->unique()->numerify('##########'),
            'sequence_number' => $this->faker->numberBetween(1, 200),
            'period' => $this->faker->numberBetween(1, 4),
            'clock' => $this->faker->time('i:s'),
            'play_type' => $this->faker->randomElement(['Rush', 'Pass', 'Punt', 'Kickoff', 'Field Goal', 'Penalty']),
            'play_text' => $this->faker->sentence(),
            'down' => $this->faker->numberBetween(1, 4),
            'distance' => $this->faker->numberBetween(1, 20),
            'yards_to_endzone' => $this->faker->numberBetween(1, 100),
            'yards_gained' => $this->faker->numberBetween(-10, 80),
            'is_scoring_play' => $this->faker->boolean(10),
            'is_turnover' => $this->faker->boolean(5),
            'is_penalty' => $this->faker->boolean(15),
            'home_score' => $this->faker->numberBetween(0, 50),
            'away_score' => $this->faker->numberBetween(0, 50),
        ];
    }
}
