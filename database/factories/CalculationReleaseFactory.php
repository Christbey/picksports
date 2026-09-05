<?php

namespace Database\Factories;

use App\Models\CalculationRelease;
use App\Support\SportCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalculationRelease> */
class CalculationReleaseFactory extends Factory
{
    protected $model = CalculationRelease::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $configuration = ['weights' => ['baseline' => 1.0]];

        return [
            'sport' => fake()->randomElement(SportCatalog::ALL),
            'phase' => 'pregame',
            'calculator_name' => 'baseline-rules',
            'release_type' => 'rules',
            'semantic_version' => fake()->unique()->numerify('1.0.###'),
            'code_revision' => str_repeat('a', 40),
            'configuration_hash' => hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)),
            'input_schema_version' => 'core-v1',
            'configuration' => $configuration,
            'status' => 'draft',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => 'approved',
            'effective_at' => now()->subMinute(),
            'approved_at' => now()->subMinute(),
            'approved_by' => 'test-suite',
            'approval_reason' => 'Verified fixture release.',
        ]);
    }
}
