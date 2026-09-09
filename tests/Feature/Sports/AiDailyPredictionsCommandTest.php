<?php

use App\AI\Agents\DataFreshnessAgent;
use App\AI\Agents\MarketReadinessAgent;
use App\AI\Agents\ModelAuditAgent;
use App\AI\Agents\PublishingGuardrailAgent;
use App\AI\Agents\SportsDailyPredictionAnalysisAgent;
use App\Models\AiGeneration;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Services\AI\SportsAiContentService;
use App\Services\Predictions\SportsAiPredictionPayloadBuilder;
use Illuminate\Support\Facades\Artisan;
use Mockery as m;

uses()->group('sports', 'ai');

afterEach(function () {
    m::close();
});

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
    DataFreshnessAgent::fake([
        [
            'freshness_status' => 'watch',
            'trust_score' => 72,
            'latest_data_fresh_at' => '2026-05-23T17:45:00-05:00',
            'summary' => 'Core game data is usable, but props should be refreshed closer to lock.',
            'stale_inputs' => ['player_props'],
            'missing_inputs' => [],
            'blocked_outputs' => [],
            'recommended_actions' => ['nba:sync-player-props'],
        ],
    ])->preventStrayPrompts();
    MarketReadinessAgent::fake([
        [
            'market_status' => 'watch',
            'readiness_score' => 68,
            'summary' => 'Moneyline is available, but props need a refresh before publishing prop language.',
            'available_markets' => ['moneyline'],
            'missing_markets' => ['fresh_player_props'],
            'risk_flags' => ['player_props_stale'],
            'recommended_actions' => ['nba:sync-player-props'],
            'publishable_recommendation' => 'watchlist',
        ],
    ])->preventStrayPrompts();
    ModelAuditAgent::fake([
        [
            'model_status' => 'usable',
            'signal_score' => 66,
            'confidence_alignment' => 'aligned',
            'summary' => 'The model supports a lean, but the edge is not strong enough for an official bet.',
            'supporting_factors' => ['home win probability above 55%', 'spread edge is positive'],
            'model_risk_flags' => ['moderate_confidence'],
            'reason_codes' => ['model_home_edge', 'moderate_signal'],
            'recommended_classification' => 'lean',
        ],
    ])->preventStrayPrompts();
    PublishingGuardrailAgent::fake([
        [
            'decision' => 'downgrade',
            'publishable_classification' => 'watch',
            'confidence' => 76,
            'summary' => 'Keep the model signal visible, but do not publish as an official bet until props refresh.',
            'reasons' => ['player_props_stale', 'publication_guardrail_degraded'],
            'blocked_outputs' => ['official bet label'],
            'required_actions' => ['nba:sync-player-props'],
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

    $validationRun = ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data',
        'scope' => 'sport:nba',
        'status' => 'warning',
        'summary' => ['failing' => 0, 'warning' => 1, 'passing' => 4],
        'ai_summary' => [
            'latest_data_fresh_at' => '2026-05-23T17:45:00-05:00',
            'data_schedule_today' => ['NBA game details synced before prediction generation.'],
            'tweak_recommendations' => ['Refresh props closer to lock.'],
            'blocked_outputs' => [],
        ],
        'started_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(5),
    ]);

    ValidationFinding::query()->create([
        'validation_run_id' => $validationRun->id,
        'sport' => 'nba',
        'check_type' => 'player_prop_freshness',
        'scope_type' => 'sport',
        'scope_id' => null,
        'status' => 'warning',
        'severity' => 'medium',
        'message' => 'NBA player props are older than the configured freshness window.',
        'facts' => ['stale_markets' => 3],
        'recommended_action' => 'nba:sync-player-props',
        'detected_at' => now()->subMinutes(5),
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
    $generation = AiGeneration::query()->first();

    expect($analysis)->not->toBeNull()
        ->and($analysis->sport)->toBe('nba')
        ->and($analysis->game_id)->toBe($game->id)
        ->and($analysis->recommendation)->toBe('moneyline')
        ->and($analysis->bet_classification)->toBe('lean')
        ->and($analysis->raw_payload['game']['matchup'])->toBe('BOS @ LAL')
        ->and($analysis->raw_payload['calculated_model']['pick_team'])->toBe('Los Angeles Lakers')
        ->and($analysis->raw_payload['operational_context']['data_freshness']['latest_data_fresh_at'])->toBe('2026-05-23T17:45:00-05:00')
        ->and($analysis->raw_payload['operational_context']['check_statuses']['player_prop_freshness']['status'])->toBe('warning')
        ->and($analysis->raw_payload['operational_context']['required_actions'])->toContain('nba:sync-player-props')
        ->and($analysis->raw_payload['operational_context']['publication_guardrails']['status'])->toBe('degraded')
        ->and($analysis->metadata['publication_guardrail_status'])->toBe('degraded')
        ->and($analysis->metadata['required_actions'])->toContain('nba:sync-player-props')
        ->and($analysis->metadata['shadow_agents']['data_freshness']['freshness_status'])->toBe('watch')
        ->and($analysis->metadata['shadow_agents']['data_freshness']['trust_score'])->toBe(72)
        ->and($analysis->metadata['shadow_agents']['market_readiness']['market_status'])->toBe('watch')
        ->and($analysis->metadata['shadow_agents']['market_readiness']['publishable_recommendation'])->toBe('watchlist')
        ->and($analysis->metadata['shadow_agents']['model_audit']['model_status'])->toBe('usable')
        ->and($analysis->metadata['shadow_agents']['model_audit']['recommended_classification'])->toBe('lean')
        ->and($analysis->metadata['shadow_agents']['publishing_guardrail']['decision'])->toBe('downgrade')
        ->and($analysis->metadata['shadow_agents']['publishing_guardrail']['publishable_classification'])->toBe('watch')
        ->and($analysis->metadata['shadow_agents']['publishing_guardrail']['required_actions'])->toContain('nba:sync-player-props')
        ->and($analysis->metadata['publishing_enforcement']['enabled'])->toBeFalse()
        ->and($analysis->metadata['publishing_enforcement']['applied'])->toBeFalse()
        ->and($analysis->calculated_edge['spread_edge'])->toBe(0.9)
        ->and($analysis->reason_codes)->toContain('model_home_edge')
        ->and($generation)->not->toBeNull()
        ->and($generation->purpose)->toBe('daily_prediction_analysis')
        ->and($generation->status)->toBe('completed')
        ->and($generation->prompt_version)->toBe('daily-prediction-analysis-v2')
        ->and($generation->output_hash)->not->toBeNull();
});

it('can enforce publishing guardrail downgrades when enabled', function () {
    config()->set('services.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.key', 'test-openai-key');
    config()->set('ai.features.publishing_guardrail_review.enforced', true);
    config()->set('nba.season.default', 2026);

    SportsDailyPredictionAnalysisAgent::fake([
        [
            'recommendation' => 'moneyline',
            'bet_classification' => 'bet',
            'ai_confidence' => 80,
            'analysis_confidence' => 78,
            'summary' => 'Official Lakers moneyline if market holds.',
            'key_factors' => [
                'Calculated model leans home side.',
                'Moneyline market is available.',
                'Confidence is strong.',
            ],
            'risk_flags' => [],
            'reason_codes' => ['model_home_edge', 'moneyline_available'],
            'market_notes' => [
                'moneyline' => 'Playable at current number.',
                'spread' => 'No spread recommendation.',
                'total' => 'No total recommendation.',
                'props' => null,
            ],
        ],
    ])->preventStrayPrompts();
    DataFreshnessAgent::fake([[
        'freshness_status' => 'watch',
        'trust_score' => 72,
        'latest_data_fresh_at' => '2026-05-23T17:45:00-05:00',
        'summary' => 'Core game data is usable, but props should be refreshed closer to lock.',
        'stale_inputs' => ['player_props'],
        'missing_inputs' => [],
        'blocked_outputs' => [],
        'recommended_actions' => ['nba:sync-player-props'],
    ]])->preventStrayPrompts();
    MarketReadinessAgent::fake([[
        'market_status' => 'watch',
        'readiness_score' => 68,
        'summary' => 'Moneyline is available, but supporting markets need a refresh.',
        'available_markets' => ['moneyline'],
        'missing_markets' => ['fresh_player_props'],
        'risk_flags' => ['player_props_stale'],
        'recommended_actions' => ['nba:sync-player-props'],
        'publishable_recommendation' => 'watchlist',
    ]])->preventStrayPrompts();
    ModelAuditAgent::fake([[
        'model_status' => 'usable',
        'signal_score' => 66,
        'confidence_alignment' => 'overstated',
        'summary' => 'The model supports a lean, not an official bet.',
        'supporting_factors' => ['home win probability above 55%'],
        'model_risk_flags' => ['official_label_overstated'],
        'reason_codes' => ['model_home_edge', 'moderate_signal'],
        'recommended_classification' => 'lean',
    ]])->preventStrayPrompts();
    PublishingGuardrailAgent::fake([[
        'decision' => 'downgrade',
        'publishable_classification' => 'watch',
        'confidence' => 76,
        'summary' => 'Downgrade until supporting markets refresh.',
        'reasons' => ['player_props_stale', 'official_label_overstated'],
        'blocked_outputs' => ['official bet label'],
        'required_actions' => ['nba:sync-player-props'],
    ]])->preventStrayPrompts();

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
        ->and($analysis->recommendation)->toBe('moneyline')
        ->and($analysis->bet_classification)->toBe('watch')
        ->and($analysis->metadata['publishing_enforcement']['enabled'])->toBeTrue()
        ->and($analysis->metadata['publishing_enforcement']['applied'])->toBeTrue()
        ->and($analysis->metadata['publishing_enforcement']['original_bet_classification'])->toBe('bet')
        ->and($analysis->metadata['publishing_enforcement']['effective_bet_classification'])->toBe('watch');
});

it('retries daily prediction analysis when the provider rate limits', function () {
    config()->set('services.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.key', 'test-openai-key');
    config()->set('nba.season.default', 2026);

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
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 2.4,
        'predicted_total' => 224.5,
        'win_probability' => 0.58,
        'confidence_score' => 61,
    ]);

    $aiContentService = m::mock(SportsAiContentService::class);
    $aiContentService->shouldReceive('providerAvailabilityMessage')
        ->with('openai')
        ->andReturnNull();
    $aiContentService->shouldReceive('generateDailyPredictionAnalysis')
        ->twice()
        ->andReturnNull();
    $aiContentService->shouldReceive('lastDailyPredictionAnalysisFailure')
        ->twice()
        ->andReturn('Application rate limited by AI provider [openai].');

    $this->app->instance(SportsAiContentService::class, $aiContentService);

    $this->artisan('sports:ai-daily-predictions', [
        '--sport' => ['nba'],
        '--date' => '2026-05-23',
        '--season' => 2026,
        '--limit' => 5,
        '--retry-rate-limit' => 1,
        '--retry-rate-limit-delay' => 1,
    ])
        ->expectsOutputToContain('rate limited while analyzing BOS @ LAL; retrying in 1 second(s) (1/1).')
        ->expectsOutputToContain('stopping remaining AI daily prediction analysis for this run because the provider is rate limited.')
        ->assertExitCode(1);
});

it('fails visibly when daily prediction analysis is disabled', function () {
    config()->set('ai.features.daily_prediction_analysis.enabled', false);

    $this->artisan('sports:ai-daily-predictions', ['--sport' => ['nfl']])
        ->expectsOutputToContain('Daily prediction AI analysis is disabled')
        ->assertExitCode(1);
});

it('fails before scanning predictions when the configured provider is unavailable', function () {
    config()->set('ai.features.daily_prediction_analysis.enabled', true);

    $aiContentService = m::mock(SportsAiContentService::class);
    $aiContentService->shouldReceive('providerAvailabilityMessage')
        ->once()
        ->with('openai')
        ->andReturn('AI provider [openai] rate-limit cooldown is active.');
    $aiContentService->shouldNotReceive('generateDailyPredictionAnalysis');
    $this->app->instance(SportsAiContentService::class, $aiContentService);

    $this->artisan('sports:ai-daily-predictions', ['--sport' => ['nfl']])
        ->expectsOutputToContain('rate-limit cooldown is active')
        ->assertExitCode(1);
});

it('uses the batch limit for changed analyses instead of unchanged skipped predictions', function () {
    config()->set('ai.features.daily_prediction_analysis.enabled', true);
    config()->set('services.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.key', 'test-openai-key');
    config()->set('nfl.season.default', 2026);
    $this->travelTo('2026-09-08 12:00:00');

    $home = App\Models\NFL\Team::factory()->create(['abbreviation' => 'SEA']);
    $away = App\Models\NFL\Team::factory()->create(['abbreviation' => 'NE']);

    $makePrediction = function (string $matchup, string $time) use ($home, $away): App\Models\NFL\Prediction {
        $game = App\Models\NFL\Game::factory()->create([
            'season' => 2026,
            'game_date' => '2026-09-08',
            'game_time' => $time,
            'status' => 'STATUS_SCHEDULED',
            'short_name' => $matchup,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);

        return App\Models\NFL\Prediction::factory()->create([
            'game_id' => $game->id,
            'predicted_spread' => 3.0,
            'predicted_total' => 43.0,
            'win_probability' => 0.6,
            'confidence_score' => 62,
        ])->load('game.homeTeam', 'game.awayTeam');
    };

    $unchanged = $makePrediction('NE @ SEA', '18:00:00');
    $pending = $makePrediction('NE @ SEA late', '20:00:00');
    $unchanged = App\Models\NFL\Prediction::query()
        ->with(['game.homeTeam', 'game.awayTeam'])
        ->findOrFail($unchanged->id);
    $payloadBuilder = app(SportsAiPredictionPayloadBuilder::class);
    $unchangedPayload = $payloadBuilder->build('nfl', $unchanged);

    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'nfl',
        'game_id' => $unchanged->game_id,
        'prediction_id' => $unchanged->id,
        'game_date' => '2026-09-08',
        'as_of_date' => '2026-09-08',
        'market' => 'game',
        'input_hash' => $payloadBuilder->hash($unchangedPayload),
        'raw_payload' => $unchangedPayload,
        'recommendation' => 'pass',
        'ai_confidence' => 50,
        'analysis_confidence' => 50,
        'bet_classification' => 'no_bet',
        'summary' => 'Existing unchanged analysis.',
    ]);

    $aiContentService = m::mock(SportsAiContentService::class);
    $aiContentService->shouldReceive('providerAvailabilityMessage')->once()->andReturnNull();
    $aiContentService->shouldReceive('generateDailyPredictionAnalysis')->once()->andReturn([
        'recommendation' => 'moneyline',
        'bet_classification' => 'lean',
        'ai_confidence' => 60,
        'analysis_confidence' => 60,
        'summary' => 'New analysis.',
        'key_factors' => [],
        'risk_flags' => [],
        'reason_codes' => [],
        'market_notes' => ['moneyline' => null, 'spread' => null, 'total' => null, 'props' => null],
        'generated_by' => 'openai:gpt-4o-mini',
    ]);
    $aiContentService->shouldReceive('lastDailyPredictionAnalysisFailure')->once()->andReturnNull();
    $aiContentService->shouldReceive('generateDataFreshnessAssessment')->once()->andReturnNull();
    $aiContentService->shouldReceive('generateMarketReadinessAssessment')->once()->andReturnNull();
    $aiContentService->shouldReceive('generateModelAuditAssessment')->once()->andReturnNull();
    $aiContentService->shouldReceive('generatePublishingGuardrailAssessment')->once()->andReturnNull();
    $this->app->instance(SportsAiContentService::class, $aiContentService);

    $this->artisan('sports:ai-daily-predictions', [
        '--sport' => ['nfl'],
        '--date' => '2026-09-08',
        '--season' => 2026,
        '--limit' => 1,
    ])->assertSuccessful();

    expect(SportsAiPredictionAnalysis::query()->count())->toBe(2)
        ->and(SportsAiPredictionAnalysis::query()->where('prediction_id', $pending->id)->exists())->toBeTrue();
});

it('keeps the prediction input hash stable as only generated time and odds age change', function () {
    config()->set('nfl.season.default', 2026);
    $this->travelTo('2026-09-08 12:00:00');

    $home = App\Models\NFL\Team::factory()->create(['abbreviation' => 'SEA']);
    $away = App\Models\NFL\Team::factory()->create(['abbreviation' => 'NE']);
    $game = App\Models\NFL\Game::factory()->create([
        'season' => 2026,
        'game_date' => '2026-09-08',
        'game_time' => '18:00:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'odds_updated_at' => '2026-09-08 10:00:00',
    ]);
    $prediction = App\Models\NFL\Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 3.0,
        'win_probability' => 0.6,
    ])->load('game.homeTeam', 'game.awayTeam');

    $payloadBuilder = app(SportsAiPredictionPayloadBuilder::class);
    $initialHash = $payloadBuilder->hash($payloadBuilder->build('nfl', $prediction));

    $this->travelTo('2026-09-08 12:10:00');

    expect($payloadBuilder->hash($payloadBuilder->build('nfl', $prediction)))->toBe($initialHash);
});
