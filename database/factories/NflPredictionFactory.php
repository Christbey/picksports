<?php

namespace Database\Factories;

use App\Models\NFL\Prediction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prediction>
 */
class NflPredictionFactory extends Factory
{
    protected $model = Prediction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}
