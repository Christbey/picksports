<?php

namespace Database\Factories;

use App\Models\CanonicalPrediction;
use App\Models\PredictionMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PredictionMarket> */
class PredictionMarketFactory extends Factory
{
    protected $model = PredictionMarket::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'prediction_id' => CanonicalPrediction::factory(),
            'market_type' => 'moneyline',
            'selection' => fake()->randomElement(['home', 'away']),
            'probability' => fake()->randomFloat(6, 0.01, 0.99),
            'confidence_score' => fake()->randomFloat(4, 0, 100),
            'is_primary' => true,
        ];
    }
}
