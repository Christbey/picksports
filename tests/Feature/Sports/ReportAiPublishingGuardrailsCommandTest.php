<?php

use App\Models\SportsAiPredictionAnalysis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses()->group('sports', 'ai');

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-30 09:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reports guardrail classification changes over the selected window', function () {
    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'nba',
        'game_id' => 1001,
        'prediction_id' => 2001,
        'game_date' => '2026-05-30',
        'as_of_date' => '2026-05-30',
        'market' => 'game',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_hash' => 'changed-row',
        'raw_payload' => ['game' => ['matchup' => 'BOS @ LAL']],
        'recommendation' => 'moneyline',
        'ai_confidence' => 80,
        'analysis_confidence' => 78,
        'bet_classification' => 'bet',
        'summary' => 'Official Lakers moneyline.',
        'key_factors' => ['Model edge is positive.'],
        'risk_flags' => [],
        'reason_codes' => ['model_home_edge'],
        'market_notes' => ['moneyline' => 'Playable.'],
        'calculated_edge' => ['spread_edge' => 0.9],
        'metadata' => [
            'shadow_agents' => [
                'publishing_guardrail' => [
                    'decision' => 'downgrade',
                    'publishable_classification' => 'watch',
                    'summary' => 'Downgrade until supporting markets refresh.',
                    'required_actions' => ['nba:sync-player-props'],
                ],
            ],
        ],
        'latency_ms' => 1500,
    ]);

    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'nba',
        'game_id' => 1002,
        'prediction_id' => 2002,
        'game_date' => '2026-05-30',
        'as_of_date' => '2026-05-30',
        'market' => 'game',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_hash' => 'matching-row',
        'raw_payload' => ['game' => ['matchup' => 'NYK @ MIA']],
        'recommendation' => 'spread',
        'ai_confidence' => 65,
        'analysis_confidence' => 63,
        'bet_classification' => 'lean',
        'summary' => 'Lean Heat spread.',
        'key_factors' => ['Model edge is modest.'],
        'risk_flags' => [],
        'reason_codes' => ['model_spread_edge'],
        'market_notes' => ['spread' => 'Lean only.'],
        'calculated_edge' => ['spread_edge' => 0.5],
        'metadata' => [
            'shadow_agents' => [
                'publishing_guardrail' => [
                    'decision' => 'keep',
                    'publishable_classification' => 'lean',
                    'summary' => 'Lean label is aligned.',
                    'required_actions' => [],
                ],
            ],
        ],
        'latency_ms' => 1200,
    ]);

    $this->artisan('sports:report-ai-publishing-guardrails --sport=nba --days=1')
        ->expectsOutputToContain('AI Publishing Guardrail Report (NBA)')
        ->expectsOutputToContain('Total analyzed: 2')
        ->expectsOutputToContain('Classification changes: 1 (50.0%)')
        ->expectsOutputToContain('BOS @ LAL')
        ->assertSuccessful();
});

it('can output guardrail report json', function () {
    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'mlb',
        'game_id' => 3001,
        'prediction_id' => 4001,
        'game_date' => '2026-05-30',
        'as_of_date' => '2026-05-30',
        'market' => 'game',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_hash' => 'json-row',
        'raw_payload' => ['game' => ['matchup' => 'MIN @ PIT']],
        'recommendation' => 'moneyline',
        'ai_confidence' => 61,
        'analysis_confidence' => 59,
        'bet_classification' => 'lean',
        'summary' => 'Lean Twins moneyline.',
        'key_factors' => ['Model edge is modest.'],
        'risk_flags' => [],
        'reason_codes' => ['model_moneyline_edge'],
        'market_notes' => ['moneyline' => 'Lean only.'],
        'calculated_edge' => ['spread_edge' => null],
        'metadata' => [
            'shadow_agents' => [
                'publishing_guardrail' => [
                    'decision' => 'keep',
                    'publishable_classification' => 'lean',
                ],
            ],
        ],
        'latency_ms' => 900,
    ]);

    $exit = Artisan::call('sports:report-ai-publishing-guardrails', [
        '--sport' => 'mlb',
        '--days' => 1,
        '--json' => true,
    ]);

    expect($exit)->toBe(0);

    $report = json_decode(Artisan::output(), true);

    expect($report['total'])->toBe(1)
        ->and($report['changed_count'])->toBe(0)
        ->and($report['decisions']['keep'])->toBe(1);
});
