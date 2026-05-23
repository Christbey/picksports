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
        ->and($data['narrative']['generated_by'])->toBe('template-v6')
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
        ->and($data['narrative']['generated_by'])->toBe('template-v6')
        ->and($data['narrative']['betting_plan'])->toBeArray();
});

test('prediction narrative remains available without granular prediction field permissions', function () {
    config()->set('nba.prediction.narrative.provider', 'template');

    $user = User::factory()->create();
    $prediction = makeNbaPredictionFixture();

    $data = PredictionResource::make($prediction)->toArray(makeAuthorizedRequest($user));

    expect($data['narrative'])->toBeArray()
        ->and($data['narrative']['generated_by'])->toBe('template-v6')
        ->and($data['narrative']['betting_plan'])->toHaveKeys(['bet_pick', 'reasoning'])
        ->and($data)->not->toHaveKey('confidence_score')
        ->and($data)->not->toHaveKey('win_probability');
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
