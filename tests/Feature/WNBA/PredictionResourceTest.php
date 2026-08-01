<?php

use App\Http\Resources\WNBA\PredictionResource;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\User;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\Team;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('view-prediction-betting-value', 'web');
});

test('wnba prediction resource exposes betting value and stored ai analysis', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-prediction-betting-value');

    $home = Team::factory()->create(['location' => 'Las Vegas', 'name' => 'Aces']);
    $away = Team::factory()->create(['location' => 'New York', 'name' => 'Liberty']);

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-10',
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Las Vegas Aces', 'price' => -110, 'point' => -2.5],
                        ['name' => 'New York Liberty', 'price' => -110, 'point' => 2.5],
                    ],
                ]],
            ]],
        ],
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1580,
        'away_elo' => 1510,
        'home_off_eff' => 104.2,
        'home_def_eff' => 96.1,
        'away_off_eff' => 101.5,
        'away_def_eff' => 98.4,
        'predicted_spread' => 5.6,
        'predicted_total' => 164.5,
        'win_probability' => 0.72,
        'confidence_score' => 72,
        'model_metadata' => [
            'model' => 'wnba_elo_efficiency_context',
        ],
    ])->load('game.homeTeam', 'game.awayTeam');

    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'wnba',
        'game_id' => $game->id,
        'prediction_id' => $prediction->id,
        'game_date' => '2026-06-10',
        'as_of_date' => '2026-06-10',
        'market' => 'game',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_hash' => str_repeat('b', 64),
        'raw_payload' => ['game' => ['matchup' => 'NY @ LV']],
        'recommendation' => 'spread',
        'ai_confidence' => 63,
        'analysis_confidence' => 60,
        'bet_classification' => 'lean',
        'summary' => 'Lean Aces spread if the number stays short.',
        'key_factors' => ['Home efficiency edge', 'Market spread below model'],
        'risk_flags' => ['thin_market'],
        'reason_codes' => ['model_home_edge', 'wnba_market_context'],
        'calculated_edge' => ['spread_edge' => 3.1],
    ]);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $data = PredictionResource::make($prediction)->toArray($request);

    expect($data['betting_value'])->toBeArray()
        ->and($data['betting_value'][0]['type'])->toBe('spread')
        ->and($data['betting_value'][0]['bet_team'])->toBe('Las Vegas Aces')
        ->and($data['ai_analysis'])->toBeArray()
        ->and($data['ai_analysis']['recommendation'])->toBe('spread')
        ->and($data['ai_analysis']['summary'])->toContain('Aces')
        ->and($data['ai_analysis']['reason_codes'])->toContain('model_home_edge');
});
