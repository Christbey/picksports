<?php

use App\Http\Resources\NFL\PredictionResource;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
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

function makeNflPredictionFixture(): Prediction
{
    $home = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Bears',
        'abbreviation' => 'CHI',
    ]);
    $away = Team::factory()->create([
        'location' => 'Detroit',
        'name' => 'Lions',
        'abbreviation' => 'DET',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_SCHEDULED',
    ]);

    return Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1510,
        'away_elo' => 1496,
        'predicted_spread' => 2.5,
        'predicted_total' => 44.5,
        'win_probability' => 0.61,
        'confidence_score' => 61.0,
    ])->load('game.homeTeam', 'game.awayTeam');
}

function makeAuthorizedNflRequest(User $user): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return $request;
}

test('nfl prediction resource uses template narrative when no stored narrative matches', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
    ]);

    $prediction = makeNflPredictionFixture();
    $data = PredictionResource::make($prediction)->toArray(makeAuthorizedNflRequest($user));

    expect($data['narrative'])->toBeArray()
        ->and($data['narrative']['generated_by'])->toBe('template-generic-v1')
        ->and($data['narrative']['summary'])->toContain('NFL lean')
        ->and($data['narrative']['betting_plan'])->toHaveKeys(['bet_pick', 'reasoning']);
});

test('nfl prediction resource uses stored narrative when generic hash matches current prediction inputs', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
    ]);

    $prediction = makeNflPredictionFixture();
    $hash = app(PredictionNarrativeService::class)->inputHashForSport($prediction, $prediction->game, 'nfl');
    $prediction->forceFill([
        'narrative_input_hash' => $hash,
        'narrative_json' => [
            'summary' => 'Chicago is favored by the model.',
            'key_points' => [
                'Chicago has the stronger Elo snapshot.',
                'Projected spread leans home.',
                'Confidence is moderate.',
            ],
            'risk_note' => 'Risk note: watch for injury changes.',
            'generated_by' => 'openai:gpt-4o-mini',
            'betting_plan' => [
                'bet_pick' => 'Bet Bears moneyline.',
                'reasoning' => 'Stored narrative still matches the current model inputs.',
            ],
        ],
    ])->save();

    $data = PredictionResource::make($prediction)->toArray(makeAuthorizedNflRequest($user));

    expect($data['narrative'])->toBeArray()
        ->and($data['narrative']['generated_by'])->toBe('openai:gpt-4o-mini')
        ->and($data['narrative']['summary'])->toBe('Chicago is favored by the model.');
});

test('nfl prediction resource exposes depth chart context in api and template narrative', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'view-prediction-spread',
        'view-prediction-win-probability',
        'view-prediction-confidence-score',
    ]);

    $prediction = makeNflPredictionFixture();
    $prediction->forceFill([
        'model_metadata' => [
            'depth_chart_injuries' => [
                'applied' => true,
                'home_out_weighted' => 3.0,
                'away_out_weighted' => 1.0,
                'home_questionable_weighted' => 0.0,
                'away_questionable_weighted' => 0.5,
                'spread_adjustment' => -1.0,
                'total_adjustment' => -0.3,
                'win_probability_adjustment' => -0.03,
            ],
        ],
    ])->save();

    $data = PredictionResource::make($prediction->fresh(['game.homeTeam', 'game.awayTeam']))->toArray(makeAuthorizedNflRequest($user));

    expect($data['depth_chart_context'])->toBeArray()
        ->and($data['depth_chart_context']['type'])->toBe('injury_weighting')
        ->and($data['depth_chart_context']['home_out_weighted'])->toBe(3.0)
        ->and(collect($data['narrative']['key_points'])->contains(fn ($point) => str_contains($point, 'Depth-chart weighting')))
        ->toBeTrue();
});
