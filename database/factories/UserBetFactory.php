<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserBet>
 */
class UserBetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'prediction_id' => fake()->numberBetween(1, 100),
            'prediction_type' => fake()->randomElement([
                'App\Models\NBA\Prediction',
                'App\Models\NFL\Prediction',
                'App\Models\CBB\Prediction',
                'App\Models\WCBB\Prediction',
                'App\Models\MLB\Prediction',
                'App\Models\CFB\Prediction',
                'App\Models\WNBA\Prediction',
            ]),
            'bet_amount' => fake()->randomFloat(2, 10, 500),
            'odds' => fake()->randomElement(['-110', '-120', '-150', '+150', '+200', '-105', '+120']),
            'bet_type' => fake()->randomElement(['spread', 'moneyline', 'total_over', 'total_under']),
            'result' => 'pending',
            'profit_loss' => null,
            'notes' => fake()->optional()->sentence(),
            'placed_at' => now(),
            'settled_at' => null,
        ];
    }
}
