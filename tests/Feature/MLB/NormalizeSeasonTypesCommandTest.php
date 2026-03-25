<?php

use App\Models\MLB\Game;
use App\Models\MLB\Team;
use Illuminate\Support\Facades\Artisan;

uses()->group('mlb');

it('normalizes mislabeled pre-opener regular season rows to spring training', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    Game::factory()->create([
        'season' => 2026,
        'week' => 12,
        'season_type' => (string) config('mlb.season.types.regular'),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-25',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $mislabeledGame = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => (string) config('mlb.season.types.regular'),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-24',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    Artisan::call('mlb:normalize-season-types', ['--season' => 2026]);

    expect((string) $mislabeledGame->fresh()->season_type)
        ->toBe((string) config('mlb.season.types.spring_training'));
});
