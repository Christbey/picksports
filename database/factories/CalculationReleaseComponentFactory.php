<?php

namespace Database\Factories;

use App\Models\CalculationRelease;
use App\Models\CalculationReleaseComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalculationReleaseComponent> */
class CalculationReleaseComponentFactory extends Factory
{
    protected $model = CalculationReleaseComponent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'calculation_release_id' => CalculationRelease::factory(),
            'component_type' => 'rules',
            'role' => 'baseline',
            'market_type' => null,
            'weight' => 1,
            'configuration' => [],
        ];
    }
}
