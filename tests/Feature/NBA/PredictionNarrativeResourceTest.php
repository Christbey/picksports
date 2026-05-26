<?php

use App\Http\Resources\NBA\PredictionResource;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\User;
use App\Services\Predictions\PredictionNarrativeService;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('view-prediction-spread', 'web');
    Permission::findOrCreate('view-prediction-win-probability', 'web');
    Permission::findOrCreate('view-prediction-confidence-score', 'web');
});

function makeNbaPredictionFixture(): Prediction
{
    $home = Team::factory()->create([
        'location' => 'Los Angeles',
        'name' => 'Lakers',
        'abbreviation' => 'LAL',
    ]);
    $away = Team::factory()->create([
        'location' => 'Boston',
        'name' => 'Celtics',
        'abbreviation' => 'BOS',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);

    return Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 3.5,
        'predicted_total' => 228.5,
        'win_probability' => 0.63,
        'confidence_score' => 74.2,
    ])->load('game.homeTeam', 'game.awayTeam');
}

function makeAuthorizedRequest(User $user): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return $request;
}

function makeNbaNarrativeOddsPayload(float $total): array
{
    return [
        'bookmakers' => [
            [
                'key' => 'draftkings',
                'markets' => [
                    [
                        'key' => 'totals',
                        'outcomes' => [
                            ['name' => 'Over', 'point' => $total, 'price' => -110],
                            ['name' => 'Under', 'point' => $total, 'price' => -110],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

test('uses template narrative when provider is template', function () {
    config()->set('nba.prediction.narrative.provider', 'template');

    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
    ]);

    $prediction = makeNbaPredictionFixture();
    $data = PredictionResource::make($prediction)->toArray(makeAuthorizedRequest($user));

    expect($data['narrative'])->toBeArray()
        ->and($data['narrative']['generated_by'])->toBe('template-v7')
        ->and($data['narrative']['summary'])->toContain("Tonight's lean")
        ->and($data['narrative']['betting_plan'])->toBeArray()
        ->and($data['narrative']['betting_plan'])->toHaveKeys(['bet_pick', 'reasoning']);
});

test('uses stored narrative when hash matches current prediction inputs', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
    ]);

    $prediction = makeNbaPredictionFixture();
    $hash = app(PredictionNarrativeService::class)->inputHashForNba($prediction, $prediction->game);
    $prediction->forceFill([
        'narrative_input_hash' => $hash,
        'narrative_json' => [
            'summary' => 'Los Angeles is favored by the model.',
            'key_points' => [
                'Home win probability leads the board.',
                'Spread indicates moderate home edge.',
                'Total projects a high-tempo scoring game.',
            ],
            'risk_note' => 'Variance remains meaningful; avoid over-sizing this position.',
            'generated_by' => 'openai:gpt-4o-mini',
            'betting_plan' => [
                'bet_pick' => 'Bet Los Angeles to cover.',
                'reasoning' => 'Model edge is strongest on the spread.',
            ],
        ],
    ])->save();

    $data = PredictionResource::make($prediction)->toArray(makeAuthorizedRequest($user));

    expect($data['narrative'])->toBeArray()
        ->and($data['narrative']['generated_by'])->toBe('openai:gpt-4o-mini')
        ->and($data['narrative']['summary'])->toBe('Los Angeles is favored by the model.')
        ->and($data['narrative']['key_points'])->toHaveCount(3)
        ->and($data['narrative']['betting_plan'])->toBeArray()
        ->and($data['narrative']['betting_plan'])->toHaveKeys(['bet_pick', 'reasoning']);
});

test('falls back to template when stored narrative hash is stale', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
    ]);

    $prediction = makeNbaPredictionFixture();
    $prediction->forceFill([
        'narrative_input_hash' => 'stale-hash-value',
        'narrative_json' => [
            'summary' => 'Old narrative',
            'key_points' => ['Old point'],
            'risk_note' => 'Old risk',
            'generated_by' => 'openai:gpt-4o-mini',
            'betting_plan' => [
                'bet_pick' => 'Old bet',
                'reasoning' => 'Old reason',
            ],
        ],
    ])->save();

    $data = PredictionResource::make($prediction)->toArray(makeAuthorizedRequest($user));

    expect($data['narrative'])->toBeArray()
        ->and($data['narrative']['generated_by'])->toBe('template-v7')
        ->and($data['narrative']['betting_plan'])->toBeArray();
});

test('prediction narrative remains available without granular prediction field permissions', function () {
    config()->set('nba.prediction.narrative.provider', 'template');

    $user = User::factory()->create();
    $prediction = makeNbaPredictionFixture();

    $data = PredictionResource::make($prediction)->toArray(makeAuthorizedRequest($user));

    expect($data['narrative'])->toBeArray()
        ->and($data['narrative']['generated_by'])->toBe('template-v7')
        ->and($data['narrative']['betting_plan'])->toHaveKeys(['bet_pick', 'reasoning'])
        ->and($data)->not->toHaveKey('confidence_score')
        ->and($data)->not->toHaveKey('win_probability');
});

test('template narrative keeps a strong total edge playable when series context conflicts', function () {
    config()->set('nba.prediction.narrative.provider', 'template');

    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
    ]);

    $spurs = Team::factory()->create([
        'location' => 'San Antonio',
        'name' => 'Spurs',
        'abbreviation' => 'SA',
    ]);
    $thunder = Team::factory()->create([
        'location' => 'Oklahoma City',
        'name' => 'Thunder',
        'abbreviation' => 'OKC',
    ]);

    $currentGame = Game::factory()->create([
        'season' => 2026,
        'game_date' => '2026-05-24',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $spurs->id,
        'away_team_id' => $thunder->id,
        'odds_data' => makeNbaNarrativeOddsPayload(219.5),
    ]);

    Game::factory()->create([
        'season' => 2026,
        'game_date' => '2026-05-22',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $spurs->id,
        'away_team_id' => $thunder->id,
        'home_score' => 108,
        'away_score' => 123,
        'period' => 4,
        'home_linescores' => [
            ['period' => 1, 'value' => 30],
            ['period' => 2, 'value' => 28],
            ['period' => 3, 'value' => 35],
            ['period' => 4, 'value' => 15],
        ],
        'away_linescores' => [
            ['period' => 1, 'value' => 27],
            ['period' => 2, 'value' => 24],
            ['period' => 3, 'value' => 35],
            ['period' => 4, 'value' => 37],
        ],
    ]);

    Game::factory()->create([
        'season' => 2026,
        'game_date' => '2026-05-20',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $thunder->id,
        'away_team_id' => $spurs->id,
        'home_score' => 122,
        'away_score' => 113,
        'period' => 4,
        'home_linescores' => [
            ['period' => 1, 'value' => 33],
            ['period' => 2, 'value' => 28],
            ['period' => 3, 'value' => 37],
            ['period' => 4, 'value' => 24],
        ],
        'away_linescores' => [
            ['period' => 1, 'value' => 29],
            ['period' => 2, 'value' => 23],
            ['period' => 3, 'value' => 34],
            ['period' => 4, 'value' => 27],
        ],
    ]);

    Game::factory()->create([
        'season' => 2026,
        'game_date' => '2026-05-18',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $thunder->id,
        'away_team_id' => $spurs->id,
        'home_score' => 115,
        'away_score' => 122,
        'period' => 6,
        'home_linescores' => [
            ['period' => 1, 'value' => 27],
            ['period' => 2, 'value' => 17],
            ['period' => 3, 'value' => 29],
            ['period' => 4, 'value' => 28],
            ['period' => 5, 'value' => 7],
            ['period' => 6, 'value' => 7],
        ],
        'away_linescores' => [
            ['period' => 1, 'value' => 27],
            ['period' => 2, 'value' => 24],
            ['period' => 3, 'value' => 29],
            ['period' => 4, 'value' => 21],
            ['period' => 5, 'value' => 7],
            ['period' => 6, 'value' => 14],
        ],
    ]);

    $historicalHome = Team::factory()->create([
        'location' => 'Denver',
        'name' => 'Nuggets',
        'abbreviation' => 'DEN',
    ]);
    $historicalAway = Team::factory()->create([
        'location' => 'Phoenix',
        'name' => 'Suns',
        'abbreviation' => 'PHX',
    ]);
    $historicalGame = Game::factory()->create([
        'season' => 2026,
        'game_date' => '2026-05-10',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $historicalHome->id,
        'away_team_id' => $historicalAway->id,
        'home_score' => 103,
        'away_score' => 82,
        'period' => 4,
        'odds_data' => makeNbaNarrativeOddsPayload(219.5),
    ]);
    Prediction::query()->create([
        'game_id' => $historicalGame->id,
        'predicted_spread' => 2.0,
        'predicted_total' => 213.0,
        'win_probability' => 0.61,
        'confidence_score' => 62.0,
        'actual_spread' => 21.0,
        'actual_total' => 185.0,
        'spread_error' => 19.0,
        'total_error' => 28.0,
        'winner_correct' => true,
        'graded_at' => now(),
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $currentGame->id,
        'predicted_spread' => 1.8,
        'predicted_total' => 213.3,
        'win_probability' => 0.62,
        'confidence_score' => 62.2,
        'rest_days_home' => 6,
        'rest_days_away' => 6,
        'model_metadata' => [
            'depth_chart_injuries' => [
                'home_out_weighted' => 0.0,
                'home_questionable_weighted' => 0.0,
                'away_out_weighted' => 0.5,
                'away_questionable_weighted' => 1.0,
                'spread_adjustment' => 0.0,
                'total_adjustment' => -0.4,
            ],
        ],
    ])->load('game.homeTeam', 'game.awayTeam');

    $data = PredictionResource::make($prediction)->toArray(makeAuthorizedRequest($user));
    $narrative = $data['narrative'];

    expect($narrative['generated_by'])->toBe('template-v7')
        ->and($narrative['summary'])->toContain('Best bet')
        ->and($narrative['context_layer']['series_total_trend']['direction'])->toBe('over')
        ->and($narrative['context_layer']['overtime_adjusted_total']['average'])->toBe(222.7)
        ->and($narrative['context_layer']['non_ot_series_average']['average'])->toBe(233.0)
        ->and($narrative['context_layer']['quarter_scoring_spikes']['count'])->toBeGreaterThanOrEqual(2)
        ->and($narrative['context_layer']['model_vs_series_conflict']['has_conflict'])->toBeTrue()
        ->and($narrative['context_layer']['historical_spot_reference']['available'])->toBeTrue()
        ->and($narrative['context_layer']['historical_spot_reference']['hit_rate'])->toBe(100.0)
        ->and($narrative['betting_plan']['classification'])->toBe('small_play_context_risk')
        ->and($narrative['betting_plan']['bet_pick'])->toBe('Bet the under.')
        ->and($narrative['betting_plan']['reasoning'])->toContain('Series total context');
});

test('universal nba context rules downgrade weak historical total spots', function () {
    config()->set('nba.prediction.narrative.provider', 'template');

    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
    ]);

    $home = Team::factory()->create(['location' => 'Cleveland', 'name' => 'Cavaliers']);
    $away = Team::factory()->create(['location' => 'New York', 'name' => 'Knicks']);
    $currentGame = Game::factory()->create([
        'season' => 2026,
        'game_date' => '2026-05-25',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'odds_data' => makeNbaNarrativeOddsPayload(217.5),
    ]);

    foreach ([231, 235, 216] as $index => $total) {
        Game::factory()->create([
            'season' => 2026,
            'game_date' => now()->subDays($index + 1),
            'status' => 'STATUS_FINAL',
            'home_team_id' => $index % 2 === 0 ? $home->id : $away->id,
            'away_team_id' => $index % 2 === 0 ? $away->id : $home->id,
            'home_score' => 110,
            'away_score' => $total - 110,
            'period' => 4,
        ]);
    }

    for ($i = 0; $i < 12; $i++) {
        $historicalHome = Team::factory()->create();
        $historicalAway = Team::factory()->create();
        $historicalGame = Game::factory()->create([
            'season' => 2026,
            'game_date' => now()->subDays($i + 20),
            'status' => 'STATUS_FINAL',
            'espn_event_id' => 'hist-'.$i,
            'home_team_id' => $historicalHome->id,
            'away_team_id' => $historicalAway->id,
            'home_score' => 100,
            'away_score' => $i < 4 ? 125 : 95,
            'period' => 4,
            'odds_data' => makeNbaNarrativeOddsPayload(217.5),
        ]);

        Prediction::query()->create([
            'game_id' => $historicalGame->id,
            'predicted_spread' => 1.0,
            'predicted_total' => 223.5,
            'win_probability' => 0.52,
            'confidence_score' => 52.0,
            'actual_total' => $i < 4 ? 225.0 : 195.0,
            'total_error' => 24.0,
            'winner_correct' => true,
            'graded_at' => now(),
        ]);
    }

    $prediction = Prediction::query()->create([
        'game_id' => $currentGame->id,
        'predicted_spread' => -0.3,
        'predicted_total' => 223.6,
        'win_probability' => 0.48,
        'confidence_score' => 52.1,
        'rest_days_home' => 2,
        'rest_days_away' => 2,
    ])->load('game.homeTeam', 'game.awayTeam');

    $data = PredictionResource::make($prediction)->toArray(makeAuthorizedRequest($user));
    $narrative = $data['narrative'];

    expect($narrative['context_layer']['historical_spot_reference']['sample_size'])->toBeGreaterThanOrEqual(12)
        ->and($narrative['context_layer']['risk_flags'])->toContain('historical_spot_low_hit_rate')
        ->and($narrative['context_layer']['reason_codes'])->toContain('historical_spot_downgrade')
        ->and($narrative['betting_plan']['classification'])->toBe('small_play_context_risk')
        ->and($narrative['betting_plan']['against_bet'])->toContain('Market movement has limited snapshot coverage.');
});

test('template betting plan does not recommend a spread bet without a market line', function () {
    config()->set('nba.prediction.narrative.provider', 'template');

    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
    ]);

    $prediction = makeNbaPredictionFixture();
    $prediction->forceFill([
        'vegas_spread' => null,
        'rest_days_home' => 6,
        'rest_days_away' => 13,
    ])->save();

    $data = PredictionResource::make($prediction->refresh()->load('game.homeTeam', 'game.awayTeam'))
        ->toArray(makeAuthorizedRequest($user));

    expect($data['narrative']['betting_plan']['bet_pick'])
        ->toBe('No spread bet until a current market line is available.')
        ->and($data['narrative']['betting_plan']['reasoning'])->toContain('model lean, not a bet recommendation')
        ->and($data['narrative']['betting_plan']['reasoning'])->toContain('rhythm risk')
        ->and($data['narrative']['betting_plan']['reasoning'])->not->toContain('Bet Los Angeles to cover');
});
