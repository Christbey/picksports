<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;

uses()->group('mlb');

it('grades only the selected historical backfill scope', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1510]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1490]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'season_type' => '2',
        'wins' => 20,
        'losses' => 15,
        'recent_form_rating' => 0.2,
        'injury_adjusted_team_rating' => 1511.0,
        'calculation_date' => '2025-05-01',
    ]);
    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'season_type' => '2',
        'wins' => 18,
        'losses' => 17,
        'recent_form_rating' => 0.1,
        'injury_adjusted_team_rating' => 1489.0,
        'calculation_date' => '2025-05-01',
    ]);

    $firstGame = Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'game_date' => '2025-05-02',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
    ]);

    $secondGame = Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'game_date' => '2025-05-03',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 4,
        'away_score' => 2,
    ]);

    Prediction::query()->create([
        'game_id' => $secondGame->id,
        'season' => 2025,
        'season_type' => '2',
        'home_team_elo' => 1510,
        'away_team_elo' => 1490,
        'home_pitcher_elo' => 1500,
        'away_pitcher_elo' => 1500,
        'home_combined_elo' => 1505,
        'away_combined_elo' => 1495,
        'predicted_spread' => 0.8,
        'predicted_total' => 8.4,
        'win_probability' => 0.55,
        'confidence_score' => 55,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'graded_at' => null,
    ]);

    $this->artisan('mlb:backfill-historical-predictions', [
        '--from-date' => '2025-05-02',
        '--to-date' => '2025-05-02',
    ])->assertSuccessful();

    expect(Prediction::query()->where('game_id', $firstGame->id)->value('graded_at'))->not->toBeNull()
        ->and(Prediction::query()->where('game_id', $secondGame->id)->value('graded_at'))->toBeNull();
});
