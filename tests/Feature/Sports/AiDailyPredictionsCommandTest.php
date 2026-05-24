<?php

use App\AI\Agents\SportsDailyPredictionAnalysisAgent;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SportsAiPredictionAnalysis;
use Illuminate\Support\Facades\Artisan;

uses()->group('sports', 'ai');

it('persists structured daily ai analysis for a slate prediction', function () {
    config()->set('services.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.key', 'test-openai-key');
    config()->set('ai.features.daily_prediction_analysis.model', 'gpt-4o-mini');
    config()->set('nba.season.default', 2026);

    SportsDailyPredictionAnalysisAgent::fake([
        [
            'recommendation' => 'moneyline',
            'bet_classification' => 'lean',
            'ai_confidence' => 63,
            'analysis_confidence' => 59,
            'summary' => 'Lean Lakers moneyline, but keep it price sensitive.',
            'key_factors' => [
                'Calculated model leans home side.',
                'Moneyline market is available.',
                'Confidence is moderate rather than elite.',
            ],
            'risk_flags' => ['moderate_confidence'],
            'reason_codes' => ['model_home_edge', 'moneyline_available'],
            'market_notes' => [
                'moneyline' => 'Playable only at a fair number.',
                'spread' => 'No spread recommendation.',
                'total' => 'No total recommendation.',
                'props' => null,
            ],
        ],
    ])->preventStrayPrompts();

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
        'season' => 2026,
        'season_type' => 2,
        'game_date' => '2026-05-23',
        'game_time' => '19:00:00',
        'status' => config('nba.statuses.scheduled'),
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'short_name' => 'BOS @ LAL',
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'Los Angeles Lakers', 'price' => -120],
                        ['name' => 'Boston Celtics', 'price' => 100],
                    ],
                ]],
            ]],
        ],
        'odds_updated_at' => now(),
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1510,
        'away_elo' => 1490,
        'predicted_spread' => 2.4,
        'predicted_total' => 224.5,
        'win_probability' => 0.58,
        'confidence_score' => 61,
        'vegas_spread' => -1.5,
        'model_version' => 'test',
        'feature_version' => 'test',
        'blend_version' => 'test',
        'model_metadata' => [
            'analysis_layer' => [
                'reason_codes' => ['strong_model_signal'],
            ],
        ],
    ]);

    $exit = Artisan::call('sports:ai-daily-predictions', [
        '--sport' => ['nba'],
        '--date' => '2026-05-23',
        '--season' => 2026,
        '--limit' => 5,
    ]);

    expect($exit)->toBe(0);

    $analysis = SportsAiPredictionAnalysis::query()->first();

    expect($analysis)->not->toBeNull()
        ->and($analysis->sport)->toBe('nba')
        ->and($analysis->game_id)->toBe($game->id)
        ->and($analysis->recommendation)->toBe('moneyline')
        ->and($analysis->bet_classification)->toBe('lean')
        ->and($analysis->raw_payload['game']['matchup'])->toBe('BOS @ LAL')
        ->and($analysis->raw_payload['calculated_model']['pick_team'])->toBe('Los Angeles Lakers')
        ->and($analysis->calculated_edge['spread_edge'])->toBe(0.9)
        ->and($analysis->reason_codes)->toContain('model_home_edge');
});
