<?php

namespace Database\Factories;

use App\Models\AiGeneration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGeneration>
 */
class AiGenerationFactory extends Factory
{
    protected $model = AiGeneration::class;

    public function definition(): array
    {
        return [
            'purpose' => 'daily_prediction_analysis',
            'context_type' => 'prediction',
            'context_id' => $this->faker->uuid(),
            'prompt_version' => 'daily-prediction-v1',
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'status' => 'completed',
            'input_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'output_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'input_tokens' => 1000,
            'output_tokens' => 250,
            'cached_input_tokens' => 0,
            'cost_usd' => '0.012500',
            'latency_ms' => 850,
            'metadata' => [],
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ];
    }
}
