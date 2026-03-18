<?php

use App\Models\CBB\Game;
use App\Models\CBB\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a placeholder round of 64 game for a missing first four destination slot', function () {
    $michigan = Team::factory()->create([
        'school' => 'Michigan',
        'mascot' => 'Wolverines',
        'abbreviation' => 'MICH',
    ]);

    $howard = Team::factory()->create([
        'school' => 'Howard',
        'mascot' => 'Bison',
        'abbreviation' => 'HOW',
    ]);

    $umbc = Team::factory()->create([
        'school' => 'UMBC',
        'mascot' => 'Retrievers',
        'abbreviation' => 'UMBC',
    ]);

    Game::factory()->create([
        'espn_event_id' => 'first-four-midwest-16',
        'season' => 2026,
        'week' => 1,
        'season_type' => (int) config('cbb.season.types.postseason'),
        'game_date' => '2026-03-18',
        'game_time' => '23:00:00',
        'is_ncaa_tournament' => true,
        'tournament_round' => 'first_four',
        'tournament_region' => 'Midwest',
        'home_seed' => 16,
        'away_seed' => 16,
        'play_in_target_seed' => 16,
        'home_team_id' => $howard->id,
        'away_team_id' => $umbc->id,
        'name' => 'Howard Bison at UMBC Retrievers',
        'short_name' => 'HOW @ UMBC',
        'status' => config('cbb.statuses.scheduled'),
    ]);

    Game::factory()->create([
        'espn_event_id' => 'midwest-8-9',
        'season' => 2026,
        'week' => 1,
        'season_type' => (int) config('cbb.season.types.postseason'),
        'game_date' => '2026-03-18',
        'game_time' => '23:00:00',
        'is_ncaa_tournament' => true,
        'tournament_round' => 'round_of_64',
        'tournament_region' => 'Midwest',
        'home_seed' => 8,
        'away_seed' => 9,
        'home_team_display_name' => 'Georgia Bulldogs',
        'away_team_display_name' => 'Saint Louis Billikens',
        'home_team_abbreviation' => 'UGA',
        'away_team_abbreviation' => 'SLU',
        'name' => 'Saint Louis Billikens at Georgia Bulldogs',
        'short_name' => 'SLU @ UGA',
        'status' => config('cbb.statuses.scheduled'),
    ]);

    Game::factory()->create([
        'espn_event_id' => 'midwest-3-14',
        'season' => 2026,
        'week' => 1,
        'season_type' => (int) config('cbb.season.types.postseason'),
        'game_date' => '2026-03-19',
        'game_time' => '23:00:00',
        'is_ncaa_tournament' => true,
        'tournament_round' => 'round_of_64',
        'tournament_region' => 'Midwest',
        'home_seed' => 3,
        'away_seed' => 14,
        'home_team_display_name' => 'Virginia Cavaliers',
        'away_team_display_name' => 'Wright State Raiders',
        'home_team_abbreviation' => 'UVA',
        'away_team_abbreviation' => 'WRST',
        'name' => 'Wright State Raiders at Virginia Cavaliers',
        'short_name' => 'WRST @ UVA',
        'status' => config('cbb.statuses.scheduled'),
    ]);

    $this->artisan('cbb:repair-tournament-structure', ['--season' => 2026])
        ->assertSuccessful();

    $game = Game::query()->where('espn_event_id', 'placeholder:2026:midwest:1-16')->first();

    expect($game)->not->toBeNull()
        ->and($game->home_team_id)->toBe($michigan->id)
        ->and($game->away_team_id)->toBeNull()
        ->and($game->away_team_display_name)->toBe('Winner of UMBC Retrievers / Howard Bison')
        ->and($game->tournament_region)->toBe('Midwest')
        ->and($game->tournament_round)->toBe('round_of_64')
        ->and($game->home_seed)->toBe(1)
        ->and($game->away_seed)->toBe(16);
});
