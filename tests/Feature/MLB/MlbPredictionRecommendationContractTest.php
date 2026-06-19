<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\User;
use App\Services\MLB\MlbBettingSignalService;
use App\Services\MLB\MlbPredictionRecommendationService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses()->group('mlb', 'api-v2', 'recommendations');

it('keeps raw moneyline odds math testable and separate from no-vig edge', function () {
    $service = app(MlbPredictionRecommendationService::class);

    expect($service->rawImpliedProbabilityForAmericanOdds(-105))->toBeFloat()
        ->toBeGreaterThan(0.5121)
        ->toBeLessThan(0.5123)
        ->and($service->rawImpliedProbabilityForAmericanOdds(120))
        ->toBeGreaterThan(0.4544)
        ->toBeLessThan(0.4546);

    $prediction = mlbRecommendationContractPrediction([
        'win_probability' => 0.43,
        'confidence_score' => 58,
        'predicted_spread' => -1.6,
    ], [
        'St. Louis Cardinals' => -105,
        'Kansas City Royals' => -105,
    ]);

    $recommendation = $service->forPrediction($prediction);

    expect($recommendation['recommendation_type'])->toBe('bet')
        ->and($recommendation['is_bet'])->toBeTrue()
        ->and($recommendation['model_probability'])->toBe(0.57)
        ->and($recommendation['raw_implied_probability'])->toBe(0.5122)
        ->and($recommendation['raw_edge'])->toBe(0.0578)
        ->and($recommendation['no_vig_implied_probability'])->toBe(0.5)
        ->and($recommendation['no_vig_edge'])->toBe(0.07);
});

it('exposes the same mlb slate bet as a canonical v2 prediction recommendation', function () {
    Carbon::setTestNow('2026-06-18 09:00:00');
    mlbRecommendationContractActingAsBypassUser();

    config([
        'mlb.season.default' => 2026,
        'mlb.signals.bet_filter.moneyline_enabled' => true,
        'mlb.signals.bet_filter.run_line_enabled' => false,
        'mlb.signals.bet_filter.total_enabled' => false,
    ]);

    $prediction = mlbRecommendationContractPrediction([
        'win_probability' => 0.43,
        'confidence_score' => 58,
        'predicted_spread' => -1.6,
    ], [
        'St. Louis Cardinals' => -105,
        'Kansas City Royals' => -105,
    ]);

    $signals = app(MlbBettingSignalService::class)->signals(2026, Carbon::parse('2026-06-18'));

    expect($signals['recommended_bets'])->toHaveCount(1)
        ->and($signals['recommended_bets'][0]['game_id'])->toBe($prediction->game_id)
        ->and($signals['recommended_bets'][0]['classification'])->toBe('bet');

    $this->getJson('/api/v2/sports/mlb/predictions?season=2026&from_date=2026-06-18&to_date=2026-06-18')
        ->assertOk()
        ->assertJsonPath('data.0.id', $prediction->id)
        ->assertJsonPath('data.0.recommendation.recommendation_type', 'bet')
        ->assertJsonPath('data.0.recommendation.market_type', 'moneyline')
        ->assertJsonPath('data.0.recommendation.recommendation_strength', 'moderate')
        ->assertJsonPath('data.0.recommendation.is_bet', true)
        ->assertJsonPath('data.0.recommendation.prediction_phase', 'pregame')
        ->assertJsonPath('data.0.recommendation.raw_edge', 0.0578)
        ->assertJsonPath('data.0.recommendation.no_vig_edge', 0.07);

    Carbon::setTestNow();
});

it('does not count mlb leans, no-plays, or live monitors as official bets', function () {
    Carbon::setTestNow('2026-06-18 09:00:00');

    $service = app(MlbPredictionRecommendationService::class);
    $lean = mlbRecommendationContractPrediction([
        'win_probability' => 0.57,
        'confidence_score' => 58,
        'predicted_spread' => 1.6,
        'model_metadata' => ['pitcher_inputs' => []],
    ], [
        'St. Louis Cardinals' => -105,
        'Kansas City Royals' => -105,
    ]);
    $noPlay = mlbRecommendationContractPrediction([
        'win_probability' => 0.57,
        'confidence_score' => 58,
        'predicted_spread' => 1.6,
    ], [
        'St. Louis Cardinals' => -105,
        'Kansas City Royals' => -200,
    ]);
    $live = mlbRecommendationContractPrediction([
        'win_probability' => 0.43,
        'confidence_score' => 58,
        'predicted_spread' => -1.6,
        'live_win_probability' => 0.76,
        'live_predicted_spread' => 2.1,
        'live_predicted_total' => 8.9,
        'live_outs_remaining' => 18,
        'live_updated_at' => now(),
    ], [
        'St. Louis Cardinals' => -105,
        'Kansas City Royals' => -105,
    ], [
        'status' => config('mlb.statuses.in_progress'),
        'inning' => 4,
        'inning_half' => 'top',
    ]);

    expect($service->forPrediction($lean)['recommendation_type'])->toBe('lean')
        ->and($service->forPrediction($lean)['is_bet'])->toBeFalse()
        ->and($service->forPrediction($noPlay)['recommendation_type'])->toBe('no_play')
        ->and($service->forPrediction($noPlay)['is_bet'])->toBeFalse()
        ->and($service->forPrediction($live)['recommendation_type'])->toBe('monitor')
        ->and($service->forPrediction($live)['is_bet'])->toBeFalse()
        ->and($service->forPrediction($live)['pregame_recommendation']['recommendation_type'])->toBe('bet')
        ->and($service->forPrediction($live)['pregame_recommendation']['is_bet'])->toBeTrue();

    Carbon::setTestNow();
});

function mlbRecommendationContractActingAsBypassUser(): User
{
    $user = User::factory()->create();

    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);

    Sanctum::actingAs($user);

    return $user;
}

/**
 * @param  array<string,mixed>  $predictionOverrides
 * @param  array<string,int>  $pricesByTeam
 * @param  array<string,mixed>  $gameOverrides
 */
function mlbRecommendationContractPrediction(array $predictionOverrides, array $pricesByTeam, array $gameOverrides = []): Prediction
{
    $awayTeam = Team::factory()->create([
        'location' => 'St. Louis',
        'name' => 'Cardinals',
    ]);
    $homeTeam = Team::factory()->create([
        'location' => 'Kansas City',
        'name' => 'Royals',
    ]);

    $game = Game::factory()->create(array_replace([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'game_date' => '2026-06-18',
        'game_time' => '18:10:00',
        'status' => config('mlb.statuses.scheduled'),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'STL @ KC',
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'h2h',
                    'outcomes' => collect($pricesByTeam)
                        ->map(fn (int $price, string $name): array => [
                            'name' => $name,
                            'price' => $price,
                        ])
                        ->values()
                        ->all(),
                ]],
            ]],
        ],
        'odds_updated_at' => now(),
    ], $gameOverrides));

    return Prediction::query()->create(array_replace([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'home_team_elo' => 1510,
        'away_team_elo' => 1490,
        'home_pitcher_elo' => 1510,
        'away_pitcher_elo' => 1490,
        'home_combined_elo' => 1510,
        'away_combined_elo' => 1490,
        'predicted_spread' => 1.6,
        'predicted_total' => 8.2,
        'win_probability' => 0.57,
        'confidence_score' => 58,
        'vegas_spread' => null,
        'model_version' => 'recommendation-contract-test',
        'feature_version' => 'recommendation-contract-test',
        'blend_version' => 'recommendation-contract-test',
        'model_metadata' => [
            'pitcher_inputs' => [
                'home_source' => 'probable_starter',
                'away_source' => 'probable_starter',
                'home_confidence' => 1.0,
                'away_confidence' => 1.0,
            ],
        ],
    ], $predictionOverrides));
}
