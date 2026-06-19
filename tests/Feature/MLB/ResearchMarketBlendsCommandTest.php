<?php

use App\Console\Commands\MLB\ResearchMarketBlendsCommand;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses()->group('mlb', 'commands', 'recommendations');

it('calculates market blend probabilities and market sides consistently', function () {
    $command = new ResearchMarketBlendsCommand;
    $blend = new ReflectionMethod($command, 'blendHomeProbability');
    $marketPickSide = new ReflectionMethod($command, 'marketPickSide');

    $homeBlend = $blend->invoke($command, [
        'home_probability' => 0.60,
        'market_home_probability' => 0.52,
    ], 0.25);
    $awayBlend = (0.25 * (1 - 0.60)) + (0.75 * (1 - 0.52));

    expect(round($homeBlend, 4))->toBe(0.54)
        ->and(round($homeBlend + $awayBlend, 4))->toBe(1.0)
        ->and($blend->invoke($command, [
            'home_probability' => 1.20,
            'market_home_probability' => 1.10,
        ], 0.25))->toBe(1.0)
        ->and($blend->invoke($command, [
            'home_probability' => -0.20,
            'market_home_probability' => -0.10,
        ], 0.25))->toBe(0.0)
        ->and($marketPickSide->invoke($command, ['home' => 0.53, 'away' => 0.47], ['home' => -120, 'away' => 110]))->toBe('home')
        ->and($marketPickSide->invoke($command, ['home' => 0.46, 'away' => 0.54], ['home' => 120, 'away' => -130]))->toBe('away')
        ->and($marketPickSide->invoke($command, ['home' => 0.50, 'away' => 0.50], ['home' => -105, 'away' => -105]))->toBeNull();
});

it('reports mlb market-aware shadow research without promoting or mutating predictions', function () {
    Carbon::setTestNow('2026-06-19 09:00:00');

    $first = researchMarketBlendPrediction(
        date: '2026-06-16',
        homeScore: 6,
        awayScore: 3,
        homeProbability: 0.60,
        confidence: 60,
        homePrice: -130,
        awayPrice: 120,
        predictedTotal: 9.2,
        marketTotal: 8.5,
        oddsUpdatedAt: '2026-06-16 12:00:00',
    );
    $second = researchMarketBlendPrediction(
        date: '2026-06-17',
        homeScore: 2,
        awayScore: 5,
        homeProbability: 0.56,
        confidence: 56,
        homePrice: 125,
        awayPrice: -135,
        predictedTotal: 7.2,
        marketTotal: 7.5,
        oddsUpdatedAt: '2026-06-17 12:00:00',
    );
    $timestampUnsafe = researchMarketBlendPrediction(
        date: '2026-06-18',
        homeScore: 4,
        awayScore: 7,
        homeProbability: 0.44,
        confidence: 56,
        homePrice: 115,
        awayPrice: -125,
        predictedTotal: 10.4,
        marketTotal: 9.0,
        oddsUpdatedAt: null,
    );
    $blockedCandidate = researchMarketBlendPrediction(
        date: '2026-06-19',
        homeScore: 6,
        awayScore: 3,
        homeProbability: 0.62,
        confidence: 60,
        homePrice: -105,
        awayPrice: -105,
        predictedTotal: 8.4,
        marketTotal: 8.0,
        oddsUpdatedAt: '2026-06-19 12:00:00',
        status: 'STATUS_SCHEDULED',
    );

    Artisan::call('mlb:research-market-blends', [
        '--season' => 2026,
        '--feature-version' => 'core-v3',
        '--limit' => 2500,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['report_type'])->toBe('mlb_market_aware_shadow_research')
        ->and($report['shadow_model_version'])->toBe('mlb_market_aware_shadow_v1')
        ->and($report['summary']['rows'])->toBe(4)
        ->and($report['summary']['market_rows'])->toBe(4)
        ->and($report['summary']['strict_market_rows'])->toBe(3)
        ->and($report['summary']['analysis_rows'])->toBe(4)
        ->and($report['summary']['analysis_mode'])->toBe('all_market_rows_flagged')
        ->and($report['summary']['analysis_pregame_safe'])->toBeFalse()
        ->and($report['summary']['public_recommendations_enabled'])->toBeFalse()
        ->and($report['summary']['public_promoted_rows'])->toBe(0)
        ->and($report['summary']['strict_pregame_safe'])->toBeFalse()
        ->and(collect($report['market_aware_blend_grid'])->pluck(0)->all())->toBe(['1.00', '0.75', '0.50', '0.25', '0.10', '0.00'])
        ->and(collect($report['strict_pregame_market_blend_grid'])->first()[2])->toBe('3')
        ->and($report['blend_performance_by_month'])->not->toBeEmpty()
        ->and($report['model_market_disagreement_deep_dive'])->not->toBeEmpty()
        ->and(collect($report['research_candidate_rule_comparison'])->pluck(0))->toContain('25% model / 75% market')
        ->and(collect($report['public_recommendation_buckets'])->firstWhere(0, 'no_play')[1])->toBe(4)
        ->and(collect($report['candidate_recommendation_buckets'])->firstWhere(0, 'bet')[1])->toBe(1)
        ->and(collect($report['promotion_block_reasons'])->firstWhere(0, 'recommendation_calibration_unvalidated')[1])->toBe(1)
        ->and($report['candidate_samples'][0]['prediction_id'])->toBe($blockedCandidate->id)
        ->and($report['candidate_samples'][0]['public_recommendation_type'])->toBe('no_play')
        ->and($report['candidate_samples'][0]['candidate_recommendation_type'])->toBe('bet')
        ->and($report['candidate_samples'][0]['promotion_blocked'])->toBeTrue()
        ->and($report['candidate_samples'][0]['block_reasons'])->toContain('recommendation_calibration_unvalidated')
        ->and(collect($report['total_bias_correction_grid'])->pluck(0))->toContain('Current model', 'Model -0.50', 'Model -1.00', 'Model -1.50', 'Market total')
        ->and(collect($report['market_blend_exclusions'])->firstWhere(0, 'missing_odds_timestamp')[1])->toBe(1)
        ->and($report['warnings'])->toContain('Strict warning: odds timestamps are incomplete, so market-aware blend rows cannot be treated as proven pregame-safe.')
        ->and($report['warnings'])->toContain('Strict pregame market sample is too small (3 row(s)); use this only as a smoke check, not validation.');

    expect(Prediction::query()->find($first->id)->model_metadata)->not->toHaveKey('market_aware_shadow_model')
        ->and(Prediction::query()->find($second->id)->model_metadata)->not->toHaveKey('market_aware_shadow_model')
        ->and(Prediction::query()->find($timestampUnsafe->id)->model_metadata)->not->toHaveKey('market_aware_shadow_model')
        ->and(Prediction::query()->find($blockedCandidate->id)->model_metadata)->not->toHaveKey('market_aware_shadow_model');

    Carbon::setTestNow();
});

it('can restrict mlb market blend research to strict pregame market rows', function () {
    Carbon::setTestNow('2026-06-19 09:00:00');

    researchMarketBlendPrediction(
        date: '2026-06-16',
        homeScore: 6,
        awayScore: 3,
        homeProbability: 0.60,
        confidence: 60,
        homePrice: -130,
        awayPrice: 120,
        predictedTotal: 9.2,
        marketTotal: 8.5,
        oddsUpdatedAt: '2026-06-16 12:00:00',
    );
    researchMarketBlendPrediction(
        date: '2026-06-18',
        homeScore: 4,
        awayScore: 7,
        homeProbability: 0.44,
        confidence: 56,
        homePrice: 115,
        awayPrice: -125,
        predictedTotal: 10.4,
        marketTotal: 9.0,
        oddsUpdatedAt: null,
    );

    Artisan::call('mlb:research-market-blends', [
        '--season' => 2026,
        '--feature-version' => 'core-v3',
        '--strict-pregame' => true,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true);

    expect($report['summary']['market_rows'])->toBe(2)
        ->and($report['summary']['strict_market_rows'])->toBe(1)
        ->and($report['summary']['analysis_rows'])->toBe(1)
        ->and($report['summary']['analysis_mode'])->toBe('strict_pregame_market_rows')
        ->and($report['summary']['analysis_pregame_safe'])->toBeTrue()
        ->and(collect($report['market_aware_blend_grid'])->first()[2])->toBe('1')
        ->and(collect($report['strict_pregame_market_blend_grid'])->first()[2])->toBe('1')
        ->and(collect($report['market_blend_exclusions'])->firstWhere(0, 'missing_odds_timestamp')[1])->toBe(1)
        ->and($report['warnings'])->toContain('Strict pregame market sample is too small (1 row(s)); use this only as a smoke check, not validation.');

    Carbon::setTestNow();
});

function researchMarketBlendPrediction(
    string $date,
    int $homeScore,
    int $awayScore,
    float $homeProbability,
    float $confidence,
    int $homePrice,
    int $awayPrice,
    float $predictedTotal,
    float $marketTotal,
    ?string $oddsUpdatedAt,
    string $status = 'STATUS_FINAL',
): Prediction {
    $homeTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Mets',
        'abbreviation' => 'NY'.substr(str_replace('-', '', $date), -1),
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Atlanta',
        'name' => 'Braves',
        'abbreviation' => 'AT'.substr(str_replace('-', '', $date), -1),
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => $status,
        'game_date' => $date,
        'game_time' => '19:10:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => $homeScore,
        'away_score' => $awayScore,
        'odds_data' => [
            'bookmakers' => [[
                'markets' => [
                    [
                        'key' => 'h2h',
                        'outcomes' => [
                            ['name' => 'New York Mets', 'price' => $homePrice],
                            ['name' => 'Atlanta Braves', 'price' => $awayPrice],
                        ],
                    ],
                    [
                        'key' => 'totals',
                        'outcomes' => [
                            ['name' => 'Over', 'point' => $marketTotal],
                            ['name' => 'Under', 'point' => $marketTotal],
                        ],
                    ],
                ],
            ]],
        ],
        'odds_updated_at' => $oddsUpdatedAt,
    ]);

    $actualSpread = $homeScore - $awayScore;
    $actualTotal = $homeScore + $awayScore;

    return Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'home_team_elo' => 1510,
        'away_team_elo' => 1490,
        'home_pitcher_elo' => 1510,
        'away_pitcher_elo' => 1490,
        'home_combined_elo' => 1510,
        'away_combined_elo' => 1490,
        'predicted_spread' => $homeProbability >= 0.5 ? 1.4 : -1.4,
        'predicted_total' => $predictedTotal,
        'win_probability' => $homeProbability,
        'confidence_score' => $confidence,
        'vegas_spread' => $homePrice < $awayPrice ? -1.5 : 1.5,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'model_metadata' => [
            'market_context' => ['market_total' => $marketTotal],
            'pitcher_inputs' => [
                'home_source' => 'probable_starter',
                'away_source' => 'probable_starter',
            ],
            'park_context' => ['total_adjustment' => 0.2],
            'actual_weather' => ['total_adjustment' => -0.1],
        ],
        'actual_spread' => $actualSpread,
        'actual_total' => $actualTotal,
        'spread_error' => abs($actualSpread - ($homeProbability >= 0.5 ? 1.4 : -1.4)),
        'total_error' => abs($actualTotal - $predictedTotal),
        'winner_correct' => ($homeProbability >= 0.5) === ($homeScore > $awayScore),
        'graded_at' => Carbon::parse($date.' 23:30:00'),
        'created_at' => Carbon::parse($date.' 10:00:00'),
    ]);
}
