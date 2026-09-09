<?php

use App\Http\Resources\NFL\PredictionResource;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\SportsGameContextReport;
use App\Models\User;
use App\Services\Predictions\SportsAiPredictionPayloadBuilder;
use App\Services\Predictions\SportsAiPublishingDecisionPolicy;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses()->group('nfl', 'ai');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('view-prediction-betting-value', 'web');
});

function nflAiSafetyFixture(): Prediction
{
    $home = Team::factory()->create([
        'location' => 'Los Angeles',
        'name' => 'Rams',
        'abbreviation' => 'LAR',
    ]);
    $away = Team::factory()->create([
        'location' => 'San Francisco',
        'name' => '49ers',
        'abbreviation' => 'SF',
    ]);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => 2,
        'week' => 1,
        'game_date' => '2026-09-13',
        'game_time' => '15:25:00',
        'status' => 'STATUS_SCHEDULED',
        'short_name' => 'SF @ LAR',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);

    return Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 3.0,
        'predicted_total' => 40.0,
        'win_probability' => 0.58,
        'confidence_score' => 55.1,
        'model_metadata' => [
            'analysis_layer' => [
                'bet_classification' => 'lean',
                'calculated_edge' => [
                    'market_spread' => 3.5,
                    'spread_points' => -0.5,
                    'market_total' => 44.0,
                    'total_points' => -4.0,
                ],
                'reason_codes' => ['total_market_edge', 'market_total_edge_under'],
                'risk_flags' => ['early_season_uncertainty'],
                'pro_signal_layer' => [
                    'tier' => 'lean',
                    'reason_codes' => ['weather_total_suppression'],
                    'risk_flags' => [],
                    'recommended_markets' => [[
                        'market' => 'total',
                        'score' => 68,
                        'tier' => 'lean',
                    ]],
                    'market_context' => [
                        'spread_price' => -110,
                    ],
                ],
            ],
        ],
    ])->load('game.homeTeam', 'game.awayTeam');
}

test('nfl payload publishes a normalized deterministic market contract', function () {
    $prediction = nflAiSafetyFixture();
    $payload = app(SportsAiPredictionPayloadBuilder::class)->build('nfl', $prediction);

    expect($payload['schema_version'])->toBe('sports_ai_prediction_payload_v3')
        ->and($payload['calculated_edge']['sign_convention'])->toBe('positive_home_margin')
        ->and($payload['calculated_edge']['vegas_spread'])->toBe(-3.5)
        ->and($payload['calculated_edge']['spread_edge'])->toBe(-0.5)
        ->and($payload['calculated_edge']['total_edge'])->toBe(-4.0)
        ->and($payload['decision_contract']['classification'])->toBe('lean')
        ->and($payload['decision_contract']['recommendation'])->toBe('total')
        ->and($payload['decision_contract']['selection']['direction'])->toBe('under')
        ->and($payload['decision_contract']['selection']['line'])->toBe(44.0);
});

test('bounded sourced context can invalidate but not create an nfl wager', function () {
    $prediction = nflAiSafetyFixture();
    SportsGameContextReport::query()->create([
        'sport' => 'nfl',
        'game_id' => $prediction->game_id,
        'status' => 'ready',
        'prompt_version' => 'test',
        'input_hash' => str_repeat('b', 64),
        'confidence' => 85,
        'summary' => 'Confirmed participation and weather context raises the scoring projection.',
        'team_context' => [
            'home' => ['starter_participation' => 'full'],
            'away' => ['starter_participation' => 'full'],
        ],
        'situational_context' => ['weather_effect' => 'boosts_scoring'],
        'facts' => [
            [
                'category' => 'starter_participation',
                'team_side' => 'both',
                'certainty' => 'confirmed',
                'source_urls' => ['https://example.com/participation'],
            ],
            [
                'category' => 'weather',
                'team_side' => 'game',
                'certainty' => 'confirmed',
                'source_urls' => ['https://example.com/weather'],
            ],
        ],
        'sources' => [
            ['url' => 'https://example.com/participation'],
            ['url' => 'https://example.com/weather'],
        ],
        'researched_at' => now(),
        'expires_at' => now()->addHours(4),
    ]);

    $payload = app(SportsAiPredictionPayloadBuilder::class)->build('nfl', $prediction);

    expect($payload['decision_contract']['context_adjustment_applied'])->toBeTrue()
        ->and($payload['decision_contract']['base_edge']['total'])->toBe(-4.0)
        ->and($payload['decision_contract']['context_adjusted_edge']['total'])->toBe(-1.5)
        ->and($payload['decision_contract']['eligible_markets'])->toBe([])
        ->and($payload['decision_contract']['classification'])->toBe('watch')
        ->and($payload['decision_contract']['recommendation'])->toBe('pass');
});

test('zero point context adjustments preserve the authoritative model edge precision', function () {
    $prediction = nflAiSafetyFixture();
    SportsGameContextReport::query()->create([
        'sport' => 'nfl',
        'game_id' => $prediction->game_id,
        'status' => 'ready',
        'prompt_version' => 'test',
        'input_hash' => str_repeat('c', 64),
        'confidence' => 85,
        'summary' => 'Sourced context has no numeric impact.',
        'team_context' => [],
        'situational_context' => [],
        'facts' => [[
            'category' => 'schedule_note',
            'team_side' => 'game',
            'certainty' => 'confirmed',
            'source_urls' => ['https://example.com/schedule'],
        ]],
        'sources' => [['url' => 'https://example.com/schedule']],
        'researched_at' => now(),
        'expires_at' => now()->addHours(4),
    ]);

    $payload = app(SportsAiPredictionPayloadBuilder::class)->build('nfl', $prediction);

    expect($payload['decision_contract']['context_adjustment_applied'])->toBeFalse()
        ->and($payload['decision_contract']['context_adjusted_edge']['spread'])->toBe(-0.5)
        ->and($payload['decision_contract']['context_adjusted_edge']['total'])->toBe(-4.0);
});

test('deterministic nfl contract overrides a stronger ai tier and wrong market', function () {
    $prediction = nflAiSafetyFixture();
    $payload = app(SportsAiPredictionPayloadBuilder::class)->build('nfl', $prediction);
    $decision = app(SportsAiPublishingDecisionPolicy::class)->decide('nfl', $payload, [
        'recommendation' => 'spread',
        'bet_classification' => 'bet',
        'summary' => 'Bet the Rams spread.',
        'risk_flags' => [],
        'reason_codes' => ['invented_reason'],
        'market_notes' => [
            'moneyline' => null,
            'spread' => 'Bet the spread.',
            'total' => null,
            'props' => null,
        ],
    ], null);

    expect($decision['bet_classification'])->toBe('lean')
        ->and($decision['recommendation'])->toBe('total')
        ->and($decision['summary'])->toContain('UNDER 44')
        ->and($decision['summary'])->not->toContain('Rams spread')
        ->and($decision['market_notes']['spread'])->toBeNull()
        ->and($decision['market_notes']['total'])->toContain('UNDER 44')
        ->and($decision['reason_codes'])->not->toContain('invented_reason')
        ->and($decision['risk_flags'])->toContain('ai_market_overridden_by_deterministic_contract')
        ->and($decision['risk_flags'])->toContain('ai_classification_capped_by_deterministic_contract');
});

test('disabled nfl moneyline cannot become an actionable recommendation', function () {
    config()->set('nfl.betting.moneyline.play_enabled', false);
    $prediction = nflAiSafetyFixture();
    $metadata = $prediction->model_metadata;
    data_set($metadata, 'analysis_layer.bet_classification', 'bet');
    data_set($metadata, 'analysis_layer.pro_signal_layer.tier', 'official_candidate');
    data_set($metadata, 'analysis_layer.pro_signal_layer.recommended_markets', [[
        'market' => 'winner',
        'score' => 82,
        'tier' => 'official_candidate',
    ]]);
    $prediction->forceFill(['model_metadata' => $metadata])->save();
    $prediction = $prediction->fresh(['game.homeTeam', 'game.awayTeam']);
    $payload = app(SportsAiPredictionPayloadBuilder::class)->build('nfl', $prediction);
    $decision = app(SportsAiPublishingDecisionPolicy::class)->decide('nfl', $payload, [
        'recommendation' => 'moneyline',
        'bet_classification' => 'bet',
        'summary' => 'Bet the moneyline.',
        'risk_flags' => [],
        'reason_codes' => ['moneyline_available'],
        'market_notes' => [],
    ], null);

    expect($payload['decision_contract']['moneyline_play_enabled'])->toBeFalse()
        ->and($payload['decision_contract']['eligible_markets'])->toBe([])
        ->and($payload['decision_contract']['recommendation'])->toBe('pass')
        ->and($decision['recommendation'])->toBe('pass')
        ->and($decision['bet_classification'])->toBe('watch')
        ->and($decision['risk_flags'])->toContain('no_eligible_deterministic_market');
});

test('nfl resources withhold ai analysis when its input hash is stale', function () {
    $prediction = nflAiSafetyFixture();
    $user = User::factory()->create();
    $user->givePermissionTo('view-prediction-betting-value');

    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'nfl',
        'game_id' => $prediction->game_id,
        'prediction_id' => $prediction->id,
        'game_date' => $prediction->game->game_date,
        'as_of_date' => '2026-09-08',
        'market' => 'game',
        'input_hash' => str_repeat('a', 64),
        'raw_payload' => ['stale' => true],
        'recommendation' => 'spread',
        'ai_confidence' => 80,
        'analysis_confidence' => 80,
        'bet_classification' => 'bet',
        'summary' => 'This analysis no longer matches the current inputs.',
    ]);

    $request = Request::create('/api/v1/nfl/predictions');
    $request->setUserResolver(fn () => $user);
    $data = resolvePreparedPredictionResource(PredictionResource::class, $prediction, 'nfl', $request);

    expect($data['ai_analysis'])->toBeNull();

    $freshPrediction = $prediction->fresh(['game.homeTeam', 'game.awayTeam']);
    $payloadBuilder = app(SportsAiPredictionPayloadBuilder::class);
    $currentPayload = $payloadBuilder->build('nfl', $freshPrediction);
    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'nfl',
        'game_id' => $freshPrediction->game_id,
        'prediction_id' => $freshPrediction->id,
        'game_date' => $freshPrediction->game->game_date,
        'as_of_date' => '2026-09-09',
        'market' => 'game',
        'input_hash' => $payloadBuilder->hash($currentPayload),
        'raw_payload' => $currentPayload,
        'recommendation' => 'total',
        'ai_confidence' => 68,
        'analysis_confidence' => 65,
        'bet_classification' => 'lean',
        'summary' => 'Current deterministic total lean.',
    ]);

    $freshData = resolvePreparedPredictionResource(
        PredictionResource::class,
        $freshPrediction->fresh(['game.homeTeam', 'game.awayTeam']),
        'nfl',
        $request,
    );

    expect($freshData['ai_analysis']['summary'])->toBe('Current deterministic total lean.');
});
