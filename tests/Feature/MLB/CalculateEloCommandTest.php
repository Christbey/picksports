<?php

use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use Illuminate\Support\Facades\Artisan;

uses()->group('mlb', 'elo');

it('ignores spring training finals when calculating opening-day mlb elo for a season', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-20',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 10,
        'away_score' => 2,
    ]);

    Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-25',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    Artisan::call('mlb:calculate-elo', ['--season' => 2026]);

    expect(EloRating::query()->count())->toBe(0)
        ->and($homeTeam->fresh()->elo_rating)->toBe(1500)
        ->and($awayTeam->fresh()->elo_rating)->toBe(1500);
});
