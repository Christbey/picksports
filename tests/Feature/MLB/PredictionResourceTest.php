<?php

use App\Http\Resources\MLB\PredictionResource;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('view-prediction-spread', 'web');
    Permission::findOrCreate('view-prediction-win-probability', 'web');
    Permission::findOrCreate('view-prediction-confidence-score', 'web');
    Permission::findOrCreate('view-prediction-home-elo', 'web');
    Permission::findOrCreate('view-prediction-away-elo', 'web');
    Permission::findOrCreate('view-prediction-betting-value', 'web');
});

test('mlb prediction resource exposes depth chart starter fallback context', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
        'view-prediction-home-elo',
        'view-prediction-away-elo',
    ]);

    $home = Team::factory()->create(['location' => 'San Francisco', 'name' => 'Giants']);
    $away = Team::factory()->create(['location' => 'New York', 'name' => 'Yankees']);

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_SCHEDULED',
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'home_team_elo' => 1490,
        'away_team_elo' => 1510,
        'home_pitcher_elo' => 1502.9,
        'away_pitcher_elo' => 1533.0,
        'home_combined_elo' => 1495.1,
        'away_combined_elo' => 1520.2,
        'predicted_spread' => -0.2,
        'predicted_total' => 8.3,
        'win_probability' => 0.49,
        'confidence_score' => 50.7,
        'model_metadata' => [
            'depth_chart_context' => [
                'home_pitcher_source' => 'depth_chart_starter',
                'away_pitcher_source' => 'probable_starter',
                'home_depth_chart_fallback_used' => true,
                'away_depth_chart_fallback_used' => false,
                'probable_pitcher_injury_applied' => false,
            ],
        ],
    ])->load('game.homeTeam', 'game.awayTeam');

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $data = PredictionResource::make($prediction)->toArray($request);

    expect($data['depth_chart_context'])->toBeArray()
        ->and($data['depth_chart_context']['type'])->toBe('starter_fallback')
        ->and($data['depth_chart_context']['home_depth_chart_fallback_used'])->toBeTrue()
        ->and($data['depth_chart_context']['away_pitcher_source'])->toBe('probable_starter')
        ->and(collect($data['narrative']['key_points'])->contains(fn ($point) => str_contains($point, 'Depth-chart starter context')))
        ->toBeTrue();
});

test('mlb prediction resource exposes stored daily ai analysis with betting value permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-prediction-betting-value');

    $home = Team::factory()->create(['location' => 'San Diego', 'name' => 'Padres']);
    $away = Team::factory()->create(['location' => 'Athletics', 'name' => 'Athletics']);

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-05-23',
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'home_team_elo' => 1510,
        'away_team_elo' => 1490,
        'home_pitcher_elo' => 1520,
        'away_pitcher_elo' => 1480,
        'home_combined_elo' => 1515,
        'away_combined_elo' => 1485,
        'predicted_spread' => 1.2,
        'predicted_total' => 7.9,
        'win_probability' => 0.56,
        'confidence_score' => 56,
    ])->load('game.homeTeam', 'game.awayTeam');

    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'mlb',
        'game_id' => $game->id,
        'prediction_id' => $prediction->id,
        'game_date' => '2026-05-23',
        'as_of_date' => '2026-05-23',
        'market' => 'game',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_hash' => str_repeat('a', 64),
        'raw_payload' => ['game' => ['matchup' => 'ATH @ SD']],
        'recommendation' => 'moneyline',
        'ai_confidence' => 61,
        'analysis_confidence' => 58,
        'bet_classification' => 'lean',
        'summary' => 'Lean Padres moneyline, but keep it price sensitive.',
        'key_factors' => ['Model leans San Diego', 'Moneyline is the primary MLB market', 'Pitcher context is available'],
        'risk_flags' => ['thin_edge'],
        'reason_codes' => ['model_home_edge', 'moneyline_context'],
        'market_notes' => ['moneyline' => 'Playable at fair price'],
        'calculated_edge' => ['spread_edge' => 1.2],
    ]);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $data = PredictionResource::make($prediction)->toArray($request);

    expect($data['ai_analysis'])->toBeArray()
        ->and($data['ai_analysis']['recommendation'])->toBe('moneyline')
        ->and($data['ai_analysis']['bet_classification'])->toBe('lean')
        ->and($data['ai_analysis']['summary'])->toContain('Padres')
        ->and($data['ai_analysis']['reason_codes'])->toContain('model_home_edge');
});
