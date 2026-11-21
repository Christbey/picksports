<?php

use App\Http\Resources\NBA\PredictionResource;
use App\Models\NBA\Prediction;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\User;
use App\Services\Predictions\SportsAiPredictionPayloadBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    config(['subscriptions.enforce_tiers' => false, 'prediction_lifecycle.canonical_reads.nba' => false,
        'prediction_review.enabled' => false, 'prediction_review.sports.nba' => false]);
    Http::preventStrayRequests();
});

afterEach(fn () => $this->travelBack());

function nbaFreshnessSavedAnalysis(Prediction $prediction): SportsAiPredictionAnalysis
{
    $builder = app(SportsAiPredictionPayloadBuilder::class);
    $payload = $builder->build('nba', $prediction);

    return SportsAiPredictionAnalysis::create([
        'sport' => 'nba', 'game_id' => $prediction->game_id, 'prediction_id' => $prediction->id,
        'game_date' => $prediction->game->game_date, 'as_of_date' => now()->toDateString(), 'market' => 'game',
        'input_hash' => $builder->hash($payload), 'raw_payload' => $payload,
        'recommendation' => 'spread', 'bet_classification' => 'lean', 'summary' => 'Current stored NBA interpretation.',
        'ai_confidence' => 60, 'analysis_confidence' => 60,
    ]);
}

function nbaFreshnessResource(Prediction $prediction, User $user): array
{
    $request = Request::create('/api/v2/sports/nba/predictions');
    $request->setUserResolver(fn () => $user);

    return resolvePreparedPredictionResource(PredictionResource::class, $prediction, 'nba', $request);
}

it('shows a matching legacy NBA review and withholds it after the forecast changes', function () {
    [, $prediction] = PredictionReviewFixtures::game('nba');
    $prediction = $prediction->fresh(['game.homeTeam', 'game.awayTeam']);
    $user = User::factory()->create();
    nbaFreshnessSavedAnalysis($prediction);

    expect(nbaFreshnessResource($prediction, $user)['ai_analysis']['summary'])->toBe('Current stored NBA interpretation.');
    $prediction->update(['predicted_spread' => 8.5]);
    $data = nbaFreshnessResource($prediction->fresh(['game.homeTeam', 'game.awayTeam']), $user);

    expect($data['ai_analysis'])->toBeNull()->and($data['predicted_spread'])->toBe(8.5);
    $this->assertDatabaseCount('bet_decisions', 0);
    $this->assertDatabaseCount('sports_ai_prediction_analyses', 1);
    Http::assertNothingSent();
});

it('does not resurrect stale NBA analysis after shared review ownership is rolled back', function () {
    [, $prediction] = PredictionReviewFixtures::game('nba');
    $prediction = $prediction->fresh(['game.homeTeam', 'game.awayTeam']);
    $user = User::factory()->create();
    $analysis = nbaFreshnessSavedAnalysis($prediction);
    config(['prediction_review.enabled' => true, 'prediction_review.sports.nba' => true]);
    expect(nbaFreshnessResource($prediction, $user)['ai_analysis'])->toBeNull();
    $prediction->update(['win_probability' => 0.8]);

    // Disabling only NBA admission restores legacy ownership, not stale prose.
    config(['prediction_review.sports.nba' => false]);
    $prediction = $prediction->fresh(['game.homeTeam', 'game.awayTeam']);
    $data = nbaFreshnessResource($prediction, $user);
    expect($data['ai_analysis'])->toBeNull()->and($data['home_win_probability'])->toBe(0.8);

    $builder = app(SportsAiPredictionPayloadBuilder::class);
    $payload = $builder->build('nba', $prediction);
    $analysis->update(['input_hash' => $builder->hash($payload), 'raw_payload' => $payload]);
    expect(nbaFreshnessResource($prediction->fresh(['game.homeTeam', 'game.awayTeam']), $user)['ai_analysis']['summary'])
        ->toBe('Current stored NBA interpretation.');
    $this->assertDatabaseCount('sports_ai_prediction_analyses', 1);
    Http::assertNothingSent();
});

it('does not accept a legacy NBA review with missing input identity', function () {
    [, $prediction] = PredictionReviewFixtures::game('nba');
    $prediction = $prediction->fresh(['game.homeTeam', 'game.awayTeam']);
    $analysis = nbaFreshnessSavedAnalysis($prediction);
    $analysis->update(['input_hash' => '']);

    expect(nbaFreshnessResource($prediction, User::factory()->create())['ai_analysis'])->toBeNull();
});
