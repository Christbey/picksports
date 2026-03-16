<?php

use App\Models\CBB\Game;
use App\Support\CbbNcaaTournamentResolver;

uses()->group('cbb');

it('identifies ncaa tournament events from espn payloads', function () {
    $resolver = new CbbNcaaTournamentResolver;

    $resolved = $resolver->resolveFromEspnEvent([
        'season' => ['type' => 3],
        'header' => [
            'gameNote' => "NCAA Men's Basketball Championship - East Region - 1st Round",
        ],
        'competitions' => [
            [
                'tournamentId' => 22,
                'competitors' => [
                    ['homeAway' => 'home', 'rank' => 1],
                    ['homeAway' => 'away', 'rank' => 16],
                ],
            ],
        ],
        'links' => [
            ['text' => "Men's Tournament Challenge"],
        ],
    ]);

    expect($resolved['is_ncaa_tournament'])->toBeTrue()
        ->and($resolved['tournament_id'])->toBe(22)
        ->and($resolved['tournament_round'])->toBe('round_of_64')
        ->and($resolved['tournament_region'])->toBe('East')
        ->and($resolved['home_seed'])->toBe(1)
        ->and($resolved['away_seed'])->toBe(16)
        ->and($resolved['play_in_target_seed'])->toBeNull();
});

it('does not mark generic postseason games as ncaa tournament without signals', function () {
    $resolver = new CbbNcaaTournamentResolver;

    $resolved = $resolver->resolveFromEspnEvent([
        'season' => ['type' => 3],
        'name' => 'Team A vs Team B',
        'competitions' => [
            [],
        ],
    ]);

    expect($resolved['is_ncaa_tournament'])->toBeFalse()
        ->and($resolved['tournament_round'])->toBeNull()
        ->and($resolved['tournament_region'])->toBeNull();
});

it('can resolve tournament round and region from stored game text', function () {
    $resolver = new CbbNcaaTournamentResolver;

    $game = new Game([
        'season_type' => 3,
        'tournament_id' => 22,
        'tournament_note' => "NCAA Men's Basketball Championship - Midwest Region - Sweet 16",
    ]);

    $resolved = $resolver->resolveFromStoredGame($game);

    expect($resolved['is_ncaa_tournament'])->toBeTrue()
        ->and($resolved['tournament_round'])->toBe('sweet_16')
        ->and($resolved['tournament_region'])->toBe('Midwest');
});

it('captures first four seed routing metadata', function () {
    $resolver = new CbbNcaaTournamentResolver;

    $resolved = $resolver->resolveFromEspnEvent([
        'season' => ['type' => 3],
        'header' => [
            'gameNote' => "NCAA Men's Basketball Championship - East Region - First Four",
        ],
        'competitions' => [
            [
                'tournamentId' => 22,
                'competitors' => [
                    ['homeAway' => 'home', 'rank' => 16],
                    ['homeAway' => 'away', 'rank' => 16],
                ],
            ],
        ],
    ]);

    expect($resolved['tournament_round'])->toBe('first_four')
        ->and($resolved['home_seed'])->toBe(16)
        ->and($resolved['away_seed'])->toBe(16)
        ->and($resolved['play_in_target_seed'])->toBe(16);
});
