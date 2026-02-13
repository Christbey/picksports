<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class NflTeamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $teams = [
            [
                'espn_id' => '22',
                'abbreviation' => 'KC',
                'location' => 'Kansas City',
                'name' => 'Chiefs',
                'conference' => 'AFC',
                'division' => 'West',
                'color' => '#E31837',
                'logo_url' => 'https://a.espncdn.com/i/teamlogos/nfl/500/kc.png',
            ],
            [
                'espn_id' => '15',
                'abbreviation' => 'SF',
                'location' => 'San Francisco',
                'name' => '49ers',
                'conference' => 'NFC',
                'division' => 'West',
                'color' => '#AA0000',
                'logo_url' => 'https://a.espncdn.com/i/teamlogos/nfl/500/sf.png',
            ],
            [
                'espn_id' => '3',
                'abbreviation' => 'BUF',
                'location' => 'Buffalo',
                'name' => 'Bills',
                'conference' => 'AFC',
                'division' => 'East',
                'color' => '#00338D',
                'logo_url' => 'https://a.espncdn.com/i/teamlogos/nfl/500/buf.png',
            ],
        ];

        foreach ($teams as $team) {
            \App\Models\NFL\Team::create($team);
        }
    }
}
