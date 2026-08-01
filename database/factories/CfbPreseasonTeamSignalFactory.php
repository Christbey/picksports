<?php

namespace Database\Factories;

use App\Models\CFB\PreseasonTeamSignal;
use App\Models\CFB\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PreseasonTeamSignal>
 */
class CfbPreseasonTeamSignalFactory extends Factory
{
    protected $model = PreseasonTeamSignal::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'season' => $this->faker->numberBetween(2021, 2030),
            'returning_percent_ppa' => $this->faker->randomFloat(3, 0.25, 0.95),
            'returning_percent_passing_ppa' => $this->faker->randomFloat(3, 0.20, 0.95),
            'returning_percent_rushing_ppa' => $this->faker->randomFloat(3, 0.20, 0.95),
            'returning_percent_receiving_ppa' => $this->faker->randomFloat(3, 0.20, 0.95),
            'returning_usage' => $this->faker->randomFloat(3, 0.30, 0.95),
            'returning_passing_usage' => $this->faker->randomFloat(3, 0.30, 0.95),
            'returning_rushing_usage' => $this->faker->randomFloat(3, 0.30, 0.95),
            'returning_receiving_usage' => $this->faker->randomFloat(3, 0.30, 0.95),
            'incoming_transfer_count' => $this->faker->numberBetween(0, 15),
            'outgoing_transfer_count' => $this->faker->numberBetween(0, 15),
            'incoming_transfer_value' => $this->faker->randomFloat(3, 0, 50),
            'outgoing_transfer_value' => $this->faker->randomFloat(3, 0, 50),
            'transfer_net_value' => $this->faker->randomFloat(3, -25, 25),
            'talent_composite' => $this->faker->randomFloat(3, 100, 1000),
            'qb_continuity_classification' => $this->faker->randomElement(PreseasonTeamSignal::quarterbackClassifications()),
            'new_head_coach' => $this->faker->boolean(15),
            'new_offensive_coordinator' => $this->faker->boolean(25),
            'new_defensive_coordinator' => $this->faker->boolean(25),
            'coordinator_continuity_score' => $this->faker->randomFloat(3, 0, 1),
            'data_quality_status' => 'partial',
        ];
    }
}
