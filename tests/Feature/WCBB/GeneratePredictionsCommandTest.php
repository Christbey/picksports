<?php

use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\Team;

uses()->group('wcbb', 'commands');

it('uses an eastern date window for wcbb prediction generation', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1560]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1490]);

    $includedGame = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => '2026-03-22',
        'game_time' => '03:30:00',
    ]);

    $excludedGame = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => '2026-03-22',
        'game_time' => '04:30:00',
    ]);

    $this->artisan('wcbb:generate-predictions', [
        '--season' => 2026,
        '--date' => '2026-03-21',
    ])->assertSuccessful();

    expect(Prediction::query()->where('game_id', $includedGame->id)->exists())->toBeTrue()
        ->and(Prediction::query()->where('game_id', $excludedGame->id)->exists())->toBeFalse();
});
