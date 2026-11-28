<?php

use App\Http\Resources\NBA\PredictionResource;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\User;
use App\Services\Predictions\PredictionResourcePreparer;
use App\Services\Predictions\SportsAiPredictionPayloadBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

it('keeps expired partial stored research history but publishes only HTTPS sources without credentials or controls', function () {
    Http::preventStrayRequests();
    config(['subscriptions.enforce_tiers' => false]);
    $hash = hash('sha256', 'synthetic-current-input');
    $this->mock(SportsAiPredictionPayloadBuilder::class, function ($mock) use ($hash) {
        $mock->shouldReceive('build')->andReturn(['fixture' => 'synthetic-current-input']);
        $mock->shouldReceive('hash')->andReturn($hash);
    });
    $user = User::factory()->create();
    $game = Game::factory()->create(['status' => 'STATUS_SCHEDULED', 'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $prediction = Prediction::query()->create(['game_id' => $game->id, 'home_elo' => 1600, 'away_elo' => 1500, 'predicted_spread' => 3.5, 'predicted_total' => 220, 'win_probability' => 0.6, 'confidence_score' => 70]);
    $valid = 'https://official.example.test/report?edition=1#context';
    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'nba', 'game_id' => $game->id, 'prediction_id' => $prediction->id,
        'game_date' => $game->game_date, 'as_of_date' => $game->game_date, 'market' => 'game',
        'provider' => 'synthetic', 'model' => 'synthetic', 'input_hash' => $hash,
        'recommendation' => 'spread', 'ai_confidence' => 70, 'analysis_confidence' => 68,
        'bet_classification' => 'lean', 'summary' => 'Stored review',
        'raw_payload' => ['external_game_context' => [
            'available' => true, 'summary' => 'Historical partial evidence',
            'researched_at' => '2020-01-01T00:00:00Z', 'expires_at' => '2020-01-02T00:00:00Z',
            'facts' => [['claim' => 'A stored fact without optional publisher metadata']],
            'sources' => array_map(fn ($url) => ['url' => $url, 'publisher' => 'Stored source'], [
                'ftp://untrusted.example.test/report', 'https://user:password@example.test/private',
                'https://@example.test/empty-user', "https://example.test/re\nport", $valid,
            ]),
        ]],
    ]);
    app(PredictionResourcePreparer::class)->prepareOne($prediction, 'nba', $user);
    $request = Request::create('/synthetic-resource-contract');
    $request->setUserResolver(fn () => $user);
    $payload = (new PredictionResource($prediction))->resolve($request);
    $context = $payload['ai_analysis']['external_game_context'];
    expect(array_column($context['sources'], 'url'))->toBe([$valid]);
    expect($context['summary'])->toBe('Historical partial evidence');
    expect($context['researched_at'])->toBe('2020-01-01T00:00:00Z');
    expect($context['expires_at'])->toBe('2020-01-02T00:00:00Z');
    expect($context['facts'])->toHaveCount(1);
    Http::assertNothingSent();
});
