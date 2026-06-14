<?php

namespace Database\Factories;

use App\Models\NBA\TeamMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMetric>
 */
class NbaTeamMetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_type' => '2',
        ];
    }
}
