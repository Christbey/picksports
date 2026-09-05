<?php

namespace Database\Factories;

use App\Enums\PredictionSport;
use App\Models\User;
use App\Models\UserBet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserBet>
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
        $betType = fake()->randomElement(['spread', 'moneyline', 'total_over', 'total_under']);
        $selectionSide = match ($betType) {
            'spread', 'moneyline' => fake()->randomElement(['home', 'away']),
            'total_over' => 'over',
            'total_under' => 'under',
        };

        return [
            'user_id' => User::factory(),
            'prediction_id' => fake()->numberBetween(1, 100),
            'prediction_sport' => $sport = fake()->randomElement(PredictionSport::cases()),
            'prediction_type' => $sport->predictionModelClass(),
            'bet_amount' => fake()->randomFloat(2, 10, 500),
            'odds' => fake()->randomElement(['-110', '-120', '-150', '+150', '+200', '-105', '+120']),
            'bet_type' => $betType,
            'selection_side' => $selectionSide,
            'selection_label' => match ($betType) {
                'moneyline' => fake()->randomElement(['LAL ML', 'BOS ML']),
                'spread' => fake()->randomElement(['BOS +4.5', 'LAL -4.5']),
                'total_over' => fake()->randomElement(['Over 229.5', 'Over 221.0']),
                'total_under' => fake()->randomElement(['Under 229.5', 'Under 221.0']),
            },
            'line' => fake()->optional()->randomFloat(1, -9.5, 240.5),
            'result' => 'pending',
            'profit_loss' => null,
            'notes' => fake()->optional()->sentence(),
            'placed_at' => now(),
            'settled_at' => null,
        ];
    }
}
