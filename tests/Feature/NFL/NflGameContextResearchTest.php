<?php

use App\AI\Agents\NflGameContextResearchAgent;
use App\Models\AiGeneration;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\SportsGameContextReport;
use App\Services\NFL\NflWebContextResearchService;
use App\Services\Predictions\SportsAiPredictionPayloadBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery as m;

uses()->group('nfl', 'ai');

it('enforces the OpenAI search budget and records measured usage with estimated cost', function () {
    config()->set('ai.providers.openai.key', 'test-openai-key');
    config()->set('ai.providers.openai.url', 'https://api.openai.com/v1');
    config()->set('ai.features.nfl_game_context_research.model', 'gpt-5.6-luna');
    config()->set('ai.features.nfl_game_context_research.max_searches', 5);
    config()->set('ai.features.nfl_game_context_research.reasoning_effort', 'none');
    config()->set('ai.features.nfl_game_context_research.require_provider_citations', true);

    $structured = [
        'status' => 'ready',
        'confidence' => 82,
        'summary' => 'Both coaching staffs disclosed their preseason quarterback plans.',
        'team_context' => [
            'home' => [
                'starter_participation' => 'limited',
                'qb_rotation_quality' => 'strong',
                'coaching_intent' => 'balanced',
                'injury_impact' => 'low',
                'notes' => ['The first-team offense is expected to play one series.'],
            ],
            'away' => [
                'starter_participation' => 'none',
                'qb_rotation_quality' => 'average',
                'coaching_intent' => 'conservative',
                'injury_impact' => 'low',
                'notes' => ['The starting quarterback will sit.'],
            ],
        ],
        'situational_context' => [
            'joint_practice_effect' => 'neutral',
            'weather_effect' => 'indoor',
            'schedule_notes' => [],
        ],
        'market_snapshot' => [
            'home_spread' => -2.5,
            'total' => 36.5,
            'home_moneyline' => -140,
            'away_moneyline' => 120,
            'observed_at' => '2026-08-13T11:00:00-05:00',
            'notes' => [],
        ],
        'facts' => [[
            'category' => 'starter_participation',
            'team_side' => 'both',
            'claim' => 'The home starters will play briefly and the away starting quarterback will sit.',
            'certainty' => 'confirmed',
            'source_urls' => ['https://example.com/official-update'],
        ]],
        'sources' => [[
            'url' => 'https://example.com/official-update',
            'title' => 'Coach announces preseason plans',
            'publisher' => 'Example Team',
            'published_at' => '2026-08-13T09:00:00-05:00',
            'source_type' => 'official',
        ]],
        'risk_flags' => ['preseason_rotation_volatility'],
    ];

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_test_nfl_context',
            'model' => 'gpt-5.6-luna',
            'output' => [
                [
                    'type' => 'web_search_call',
                    'action' => [
                        'type' => 'search',
                        'query' => 'preseason participation plans',
                        'sources' => [[
                            'url' => 'https://example.com/official-update',
                        ]],
                    ],
                ],
                [
                    'type' => 'web_search_call',
                    'action' => [
                        'type' => 'search',
                        'query' => 'quarterback rotation',
                        'sources' => [],
                    ],
                ],
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode($structured, JSON_THROW_ON_ERROR),
                        'annotations' => [[
                            'type' => 'url_citation',
                            'url' => 'https://example.com/official-update',
                        ]],
                    ]],
                ],
            ],
            'usage' => [
                'input_tokens' => 1000,
                'input_tokens_details' => ['cached_tokens' => 100],
                'output_tokens' => 500,
                'output_tokens_details' => ['reasoning_tokens' => 100],
            ],
        ], 200),
    ]);

    $home = Team::factory()->create(['abbreviation' => 'MIN']);
    $away = Team::factory()->create(['abbreviation' => 'TEN']);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '1',
        'week' => 2,
        'game_date' => '2026-08-13',
        'game_time' => '19:00:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);

    $result = app(NflWebContextResearchService::class)->research($game);

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $data['model'] === 'gpt-5.6-luna'
            && $data['max_tool_calls'] === 5
            && $data['max_output_tokens'] === 6000
            && $data['reasoning']['effort'] === 'none'
            && $data['store'] === false
            && $data['text']['format']['type'] === 'json_schema';
    });

    $generation = AiGeneration::query()->where('purpose', 'nfl_game_context_research')->firstOrFail();

    expect($result['report']->status)->toBe('ready')
        ->and($result['report']->sources[0]['provider_citation'])->toBeTrue()
        ->and($generation->status)->toBe('completed')
        ->and($generation->input_tokens)->toBe(1000)
        ->and($generation->output_tokens)->toBe(500)
        ->and($generation->cached_input_tokens)->toBe(100)
        ->and($generation->cost_usd)->toBe('0.020782')
        ->and(data_get($generation->metadata, 'web_search_calls'))->toBe(2)
        ->and(data_get($generation->metadata, 'search_cap'))->toBe(5)
        ->and(data_get($generation->metadata, 'provider_response_id'))->toBe('resp_test_nfl_context');
});

it('researches sourced nfl context and applies bounded adjustments to the ai packet', function () {
    config()->set('services.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.key', 'test-openai-key');
    config()->set('ai.features.nfl_game_context_research.enabled', true);
    config()->set('ai.features.nfl_game_context_research.require_provider_citations', false);
    config()->set('nfl.season.default', 2026);

    NflGameContextResearchAgent::fake([[
        'status' => 'ready',
        'confidence' => 86,
        'summary' => 'Cincinnati plans to play its starters while Detroit will rest its primary quarterback.',
        'team_context' => [
            'home' => [
                'starter_participation' => 'extended',
                'qb_rotation_quality' => 'strong',
                'coaching_intent' => 'aggressive',
                'injury_impact' => 'low',
                'notes' => ['The starting offense is expected to play.'],
            ],
            'away' => [
                'starter_participation' => 'none',
                'qb_rotation_quality' => 'average',
                'coaching_intent' => 'conservative',
                'injury_impact' => 'low',
                'notes' => ['The regular-season starting quarterback will sit.'],
            ],
        ],
        'situational_context' => [
            'joint_practice_effect' => 'neutral',
            'weather_effect' => 'neutral',
            'schedule_notes' => [],
        ],
        'market_snapshot' => [
            'home_spread' => -7.0,
            'total' => 37.5,
            'home_moneyline' => -295,
            'away_moneyline' => 230,
            'observed_at' => '2026-08-13T10:00:00-05:00',
            'notes' => ['Web line is corroborating context only.'],
        ],
        'facts' => [[
            'category' => 'starter participation',
            'team_side' => 'both',
            'claim' => 'Cincinnati starters are expected to play; Detroit will sit its primary quarterback.',
            'certainty' => 'confirmed',
            'source_urls' => ['https://example.com/team-report'],
        ]],
        'sources' => [[
            'url' => 'https://example.com/team-report',
            'title' => 'Preseason participation plans',
            'publisher' => 'Example Team Site',
            'published_at' => '2026-08-12T12:00:00-05:00',
            'source_type' => 'official',
        ]],
        'risk_flags' => ['preseason_rotation_volatility'],
    ]])->preventStrayPrompts();

    $home = Team::factory()->create(['location' => 'Cincinnati', 'name' => 'Bengals', 'abbreviation' => 'CIN']);
    $away = Team::factory()->create(['location' => 'Detroit', 'name' => 'Lions', 'abbreviation' => 'DET']);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '1',
        'week' => 1,
        'game_date' => '2026-08-13',
        'game_time' => '18:00:00',
        'status' => 'STATUS_SCHEDULED',
        'short_name' => 'DET @ CIN',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);
    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => -4.5,
        'predicted_total' => 46.5,
        'win_probability' => 0.35,
        'confidence_score' => 64.7,
    ]);

    $exit = Artisan::call('nfl:research-game-context', [
        '--date' => '2026-08-13',
        '--season' => 2026,
    ]);

    expect($exit)->toBe(0)
        ->and(SportsGameContextReport::query()->count())->toBe(1);

    $report = SportsGameContextReport::query()->firstOrFail();
    $payload = app(SportsAiPredictionPayloadBuilder::class)->build('nfl', $prediction->load('game.homeTeam', 'game.awayTeam'));

    expect($report->sources[0]['url'])->toBe('https://example.com/team-report')
        ->and($report->facts[0]['source_urls'])->toBe(['https://example.com/team-report'])
        ->and($payload['schema_version'])->toBe('sports_ai_prediction_payload_v2')
        ->and($payload['external_game_context']['available'])->toBeTrue()
        ->and($payload['external_game_context']['deterministic_adjustment']['home_margin_points'])->toBe(2.5)
        ->and($payload['external_game_context']['deterministic_adjustment']['components']['qb_rotation_quality'])->toBe(0.0)
        ->and($payload['external_game_context']['context_adjusted_model']['predicted_spread'])->toBe(-2.0)
        ->and($payload['external_game_context']['sources'][0]['url'])->toBe('https://example.com/team-report');
});

it('does not expose expired web context as current prediction evidence', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.0,
        'predicted_total' => 40.0,
        'win_probability' => 0.54,
    ]);

    SportsGameContextReport::query()->create([
        'sport' => 'nfl',
        'game_id' => $game->id,
        'status' => 'ready',
        'prompt_version' => 'test',
        'input_hash' => str_repeat('a', 64),
        'confidence' => 90,
        'summary' => 'Old context.',
        'facts' => [],
        'sources' => [],
        'researched_at' => now()->subHours(6),
        'expires_at' => now()->subHour(),
    ]);

    $payload = app(SportsAiPredictionPayloadBuilder::class)->build('nfl', $prediction->load('game.homeTeam', 'game.awayTeam'));

    expect($payload['external_game_context']['available'])->toBeFalse()
        ->and($payload['external_game_context']['reason'])->toBe('no_fresh_research')
        ->and($payload['external_game_context']['risk_flags'])->toContain('missing_external_game_context');
});

it('retries provider rate limits and stops before spending calls on the remaining slate', function () {
    config()->set('ai.features.nfl_game_context_research.enabled', true);
    config()->set('nfl.season.default', 2026);

    $home = Team::factory()->create(['abbreviation' => 'NE']);
    $away = Team::factory()->create(['abbreviation' => 'IND']);

    Game::factory()->create([
        'season' => 2026,
        'game_date' => '2026-08-13',
        'game_time' => '18:30:00',
        'status' => 'STATUS_SCHEDULED',
        'short_name' => 'IND @ NE',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);

    $research = m::mock(NflWebContextResearchService::class);
    $research->shouldReceive('research')
        ->twice()
        ->andThrow(new RuntimeException('Application rate limited by AI provider [openai].'));
    $this->app->instance(NflWebContextResearchService::class, $research);

    $this->artisan('nfl:research-game-context', [
        '--date' => '2026-08-13',
        '--season' => 2026,
        '--retry-rate-limit' => 1,
        '--retry-rate-limit-delay' => 1,
    ])
        ->expectsOutputToContain('rate limited while researching IND @ NE; retrying in 1 second(s) (1/1).')
        ->expectsOutputToContain('stopping remaining NFL context research for this run because the provider is rate limited.')
        ->assertExitCode(1);
});

it('does not retry an exhausted OpenAI credit balance', function () {
    config()->set('ai.features.nfl_game_context_research.enabled', true);
    config()->set('nfl.season.default', 2026);

    $home = Team::factory()->create(['abbreviation' => 'DAL']);
    $away = Team::factory()->create(['abbreviation' => 'LAR']);

    Game::factory()->create([
        'season' => 2026,
        'game_date' => '2026-08-13',
        'game_time' => '19:30:00',
        'status' => 'STATUS_SCHEDULED',
        'short_name' => 'LAR @ DAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);

    $research = m::mock(NflWebContextResearchService::class);
    $research->shouldReceive('research')
        ->once()
        ->andThrow(new RuntimeException('OpenAI Responses API failed with status 429 [credit_balance_exhausted]: You have no credits remaining.'));
    $this->app->instance(NflWebContextResearchService::class, $research);

    $this->artisan('nfl:research-game-context', [
        '--date' => '2026-08-13',
        '--season' => 2026,
        '--retry-rate-limit' => 3,
        '--retry-rate-limit-delay' => 1,
    ])
        ->doesntExpectOutputToContain('retrying')
        ->expectsOutputToContain('stopping remaining NFL context research for this run because the provider account has no available quota')
        ->assertExitCode(1);
});

it('does not research a stale scheduled game after its synced market kickoff', function () {
    config()->set('ai.features.nfl_game_context_research.enabled', true);
    config()->set('nfl.season.default', 2026);
    $this->travelTo('2026-08-13 20:15:00');

    $home = Team::factory()->create(['abbreviation' => 'SF']);
    $away = Team::factory()->create(['abbreviation' => 'TEN']);

    Game::factory()->create([
        'season' => 2026,
        'game_date' => '2026-08-14',
        'game_time' => '01:00:00',
        'status' => 'STATUS_SCHEDULED',
        'short_name' => 'TEN @ SF',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'odds_data' => ['commence_time' => '2026-08-14T01:00:00Z'],
    ]);

    $research = m::mock(NflWebContextResearchService::class);
    $research->shouldNotReceive('research');
    $this->app->instance(NflWebContextResearchService::class, $research);

    $this->artisan('nfl:research-game-context', [
        '--date' => '2026-08-13',
        '--season' => 2026,
    ])
        ->expectsOutputToContain('skipped TEN @ SF because its synced market kickoff has passed')
        ->assertExitCode(0);
});
