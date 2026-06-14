<?php

use App\Actions\Validation\Checks\LivePredictionFreshnessCheck;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\MLB\Team as MlbTeam;
use Illuminate\Support\Carbon;

it('fails when an mlb live game has stale live prediction fields', function () {
    Carbon::setTestNow('2026-06-14 15:30:00');

    $homeTeam = MlbTeam::factory()->create(['abbreviation' => 'STL']);
    $awayTeam = MlbTeam::factory()->create(['abbreviation' => 'CHC']);
    $game = MlbGame::factory()->create([
        'season' => 2026,
        'game_date' => '2026-06-14',
        'game_time' => '15:05:00',
        'status' => config('mlb.statuses.in_progress'),
        'inning' => 6,
        'inning_half' => 'top',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'CHC @ STL',
    ]);

    MlbPrediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'home_team_elo' => 1510,
        'away_team_elo' => 1490,
        'home_pitcher_elo' => 1525,
        'away_pitcher_elo' => 1480,
        'home_combined_elo' => 1520,
        'away_combined_elo' => 1485,
        'predicted_spread' => 1.2,
        'predicted_total' => 8.4,
        'win_probability' => 0.58,
        'confidence_score' => 61,
        'live_predicted_spread' => 1.8,
        'live_win_probability' => 0.78,
        'live_predicted_total' => 8.1,
        'live_outs_remaining' => 18,
        'live_updated_at' => now()->subMinutes(12),
        'model_version' => 'test',
        'feature_version' => 'test',
        'blend_version' => 'test',
    ]);

    $result = app(LivePredictionFreshnessCheck::class)->run('mlb', config('validation.sports.mlb'));

    expect($result)->not->toBeNull()
        ->and($result['check_type'])->toBe('validation_live_prediction_freshness')
        ->and($result['status'])->toBe('failing')
        ->and($result['recommended_action'])->toBe('espn:sync-mlb-games-scoreboard')
        ->and(data_get($result, 'metadata.live_games'))->toBe(1)
        ->and(data_get($result, 'metadata.stale_live_models'))->toBe(1)
        ->and(data_get($result, 'metadata.sample_games.0.reasons'))->toContain('stale_live_model');

    Carbon::setTestNow();
});
