<?php

use App\Http\Resources\NBA\PredictionResource;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\User;
use App\Services\Predictions\PredictionResourcePreparer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

test('bulk preparation fetches ai analyses once and serialization performs no queries', function () {
    config()->set('subscriptions.enforce_tiers', false);

    $user = User::factory()->create();
    $predictions = new Collection;

    foreach ([
        ['home' => 'CHI', 'away' => 'DET', 'summary' => 'Chicago has the stronger model edge.'],
        ['home' => 'DAL', 'away' => 'PHX', 'summary' => 'Dallas grades as the value side.'],
    ] as $index => $fixture) {
        $home = Team::factory()->create(['abbreviation' => $fixture['home']]);
        $away = Team::factory()->create(['abbreviation' => $fixture['away']]);
        $game = Game::factory()->create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'STATUS_SCHEDULED',
            'game_date' => '2026-08-'.str_pad((string) ($index + 20), 2, '0', STR_PAD_LEFT),
        ]);
        $prediction = Prediction::query()->create([
            'game_id' => $game->id,
            'home_elo' => 1600 + $index,
            'away_elo' => 1500 + $index,
            'predicted_spread' => 4.5 + $index,
            'predicted_total' => 224.5 + $index,
            'win_probability' => 0.62,
            'confidence_score' => 74,
        ]);

        SportsAiPredictionAnalysis::query()->create([
            'sport' => 'nba',
            'game_id' => $game->id,
            'prediction_id' => $prediction->id,
            'game_date' => $game->game_date,
            'as_of_date' => $game->game_date,
            'market' => 'game',
            'provider' => 'openai',
            'model' => 'test-model',
            'input_hash' => str_repeat((string) ($index + 1), 64),
            'raw_payload' => ['fixture' => $index],
            'recommendation' => 'spread',
            'ai_confidence' => 70,
            'analysis_confidence' => 68,
            'bet_classification' => 'lean',
            'summary' => $fixture['summary'],
        ]);

        $predictions->push($prediction);
    }

    $predictions = Prediction::query()
        ->with(['game.homeTeam', 'game.awayTeam'])
        ->whereKey($predictions->modelKeys())
        ->orderBy('id')
        ->get();

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(PredictionResourcePreparer::class)->prepare($predictions, 'nba', $user);

    $preparationQueries = DB::getQueryLog();
    DB::disableQueryLog();

    $analysisQueries = collect($preparationQueries)->filter(
        fn (array $query): bool => preg_match(
            '/\bfrom\s+["`]?sports_ai_prediction_analyses["`]?\b/i',
            $query['query'],
        ) === 1
    );

    expect($analysisQueries)->toHaveCount(1);

    $request = Request::create('/api/v1/nba/predictions');
    $request->setUserResolver(fn () => $user);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $payload = PredictionResource::collection($predictions)->resolve($request);

    $serializationQueries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($serializationQueries)->toBe([])
        ->and($payload)->toHaveCount(2)
        ->and($payload[0]['predicted_spread'])->toBe(4.5)
        ->and($payload[0]['ai_analysis']['summary'])->toBe('Chicago has the stronger model edge.')
        ->and($payload[1]['ai_analysis']['summary'])->toBe('Dallas grades as the value side.')
        ->and($payload[0]['narrative'])->toBeArray();
});

test('game endpoints preserve their nested prepared prediction contract', function () {
    config()->set('subscriptions.enforce_tiers', false);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_SCHEDULED',
    ]);
    Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1600,
        'away_elo' => 1500,
        'predicted_spread' => 6.5,
        'predicted_total' => 226.5,
        'win_probability' => 0.66,
        'confidence_score' => 78,
    ]);

    $this->getJson("/api/v1/nba/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('data.prediction.predicted_spread', 6.5)
        ->assertJsonPath('data.prediction.home_win_probability', 0.66)
        ->assertJsonMissingPath('data.prediction.game');
});

test('prediction detail endpoints resolve prepared fields before returning the response', function () {
    config()->set('subscriptions.enforce_tiers', false);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_SCHEDULED',
    ]);
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1580,
        'away_elo' => 1510,
        'predicted_spread' => 3.5,
        'predicted_total' => 221.5,
        'win_probability' => 0.59,
        'confidence_score' => 69,
    ]);

    $this->getJson("/api/v1/nba/predictions/{$prediction->id}")
        ->assertOk()
        ->assertJsonPath('data.predicted_spread', 3.5)
        ->assertJsonPath('data.home_win_probability', 0.59)
        ->assertJsonPath('data.game.id', $game->id);
});
