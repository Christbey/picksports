<?php

namespace Database\Factories;

use App\Models\CBB\RosterSnapshot;
use App\Models\CBB\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class CbbRosterSnapshotFactory extends Factory
{
    protected $model = RosterSnapshot::class;

    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'season' => 2027, 'source' => 'espn_season_roster', 'observed_at' => now(), 'content_hash' => hash('sha256', fake()->uuid()), 'provider_metadata' => ['season' => ['year' => 2027]]];
    }
}
