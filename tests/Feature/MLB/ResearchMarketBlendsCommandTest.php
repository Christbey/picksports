<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses()->group('mlb', 'commands', 'recommendations');

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
        ->and($report['summary']['rows'])->toBe(3)
        ->and($report['summary']['market_rows'])->toBe(3)
        ->and($report['summary']['public_recommendations_enabled'])->toBeFalse()
        ->and($report['summary']['public_promoted_rows'])->toBe(0)
        ->and($report['summary']['strict_pregame_safe'])->toBeFalse()
        ->and(collect($report['market_aware_blend_grid'])->pluck(0)->all())->toBe(['1.00', '0.75', '0.50', '0.25', '0.10', '0.00'])
        ->and($report['blend_performance_by_month'])->not->toBeEmpty()
        ->and($report['model_market_disagreement_deep_dive'])->not->toBeEmpty()
        ->and(collect($report['research_candidate_rule_comparison'])->pluck(0))->toContain('25% model / 75% market')
        ->and(collect($report['total_bias_correction_grid'])->pluck(0))->toContain('Current model', 'Model -0.50', 'Model -1.00', 'Model -1.50', 'Market total')
        ->and(collect($report['market_blend_exclusions'])->firstWhere(0, 'missing_odds_timestamp')[1])->toBe(1)
        ->and($report['warnings'])->toContain('Strict warning: odds timestamps are incomplete, so market-aware blend rows cannot be treated as proven pregame-safe.');

    expect(Prediction::query()->find($first->id)->model_metadata)->not->toHaveKey('market_aware_shadow_model')
        ->and(Prediction::query()->find($second->id)->model_metadata)->not->toHaveKey('market_aware_shadow_model')
        ->and(Prediction::query()->find($timestampUnsafe->id)->model_metadata)->not->toHaveKey('market_aware_shadow_model');

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
        'status' => 'STATUS_FINAL',
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
