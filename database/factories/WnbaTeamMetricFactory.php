<?php

namespace Database\Factories;

use App\Models\WNBA\TeamMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMetric>
 */
class WnbaTeamMetricFactory extends Factory
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
