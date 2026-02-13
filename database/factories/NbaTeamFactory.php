<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NBA\Team>
 */
class NbaTeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = \App\Models\NBA\Team::class;

    public function definition(): array
    {
        $city = $this->faker->city();

        return [
            'espn_id' => $this->faker->unique()->numerify('##'),
            'abbreviation' => $this->faker->unique()->lexify('???'),
            'school' => $city,
            'mascot' => $this->faker->randomElement(['Lakers', 'Celtics', 'Warriors', 'Heat', 'Bulls']),
            'conference' => $this->faker->randomElement(['Eastern', 'Western']),
            'division' => $this->faker->randomElement(['Atlantic', 'Central', 'Southeast', 'Northwest', 'Pacific', 'Southwest']),
            'color' => $this->faker->hexColor(),
            'logo_url' => $this->faker->imageUrl(),
        ];
    }
}
