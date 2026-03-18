<?php

use App\Models\CBB\Game;

uses()->group('cbb');

it('passes when the tournament structure is complete', function () {
    foreach ([
        'East',
        'West',
        'South',
        'Midwest',
    ] as $region) {
        foreach ([[1, 16], [8, 9], [5, 12], [4, 13], [6, 11], [3, 14], [7, 10], [2, 15]] as [$homeSeed, $awaySeed]) {
            Game::factory()->create([
                'season' => 2026,
                'season_type' => (int) config('cbb.season.types.postseason'),
                'is_ncaa_tournament' => true,
                'tournament_round' => 'round_of_64',
                'tournament_region' => $region,
                'home_seed' => $homeSeed,
                'away_seed' => $awaySeed,
                'status' => config('cbb.statuses.scheduled'),
            ]);
        }
    }

    foreach ([
        ['Midwest', 16],
        ['Midwest', 11],
        ['South', 16],
        ['West', 11],
    ] as [$region, $targetSeed]) {
        Game::factory()->create([
            'season' => 2026,
            'season_type' => (int) config('cbb.season.types.postseason'),
            'is_ncaa_tournament' => true,
            'tournament_round' => 'first_four',
            'tournament_region' => $region,
            'home_seed' => $targetSeed,
            'away_seed' => $targetSeed,
            'play_in_target_seed' => $targetSeed,
            'status' => config('cbb.statuses.scheduled'),
        ]);
    }

    $this->artisan('cbb:audit-tournament-structure --season=2026')
        ->expectsOutput('Tournament structure audit passed for 2026.')
        ->assertSuccessful();
});

it('fails when a round of 64 pairing is missing', function () {
    Game::factory()->create([
        'season' => 2026,
        'season_type' => (int) config('cbb.season.types.postseason'),
        'is_ncaa_tournament' => true,
        'tournament_round' => 'first_four',
        'tournament_region' => 'Midwest',
        'home_seed' => 16,
        'away_seed' => 16,
        'play_in_target_seed' => 16,
        'status' => config('cbb.statuses.scheduled'),
    ]);

    $this->artisan('cbb:audit-tournament-structure --season=2026')
        ->expectsOutputToContain('Tournament structure audit failed for 2026:')
        ->assertFailed();
});
