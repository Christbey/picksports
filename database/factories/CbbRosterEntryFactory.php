<?php

namespace Database\Factories;

use App\Models\CBB\Player;
use App\Models\CBB\RosterEntry;
use App\Models\CBB\RosterSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

class CbbRosterEntryFactory extends Factory
{
    protected $model = RosterEntry::class;

    public function definition(): array
    {
        return ['roster_snapshot_id' => RosterSnapshot::factory(), 'player_id' => Player::factory()->state(['espn_id' => fake()->unique()->numerify('########'), 'full_name' => fake()->name()]), 'attributes' => ['position' => 'G', 'jersey_number' => '12', 'year' => 'Junior']];
    }
}
