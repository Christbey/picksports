<?php

use App\Models\Healthcheck;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\User;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

test('admin healthchecks page includes latest validation summary in validation view', function () {
    $this->withoutVite();

    $admin = User::factory()->admin()->create();

    ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data',
        'scope' => 'sport:nba',
        'status' => 'warning',
        'summary' => [
            'total_findings' => 5,
            'passing' => 2,
            'warning' => 2,
            'failing' => 1,
        ],
        'ai_summary' => [
            'headline' => 'NBA validation needs attention',
            'intro' => 'Missing predictions and stale odds need follow-up.',
            'highlights' => [
                'Prediction completeness is below target.',
            ],
            'recommended_actions' => [
                'nba:generate-predictions',
                'nba:sync-odds',
            ],
            'generated_by' => 'template-validation-summary-v1',
        ],
        'ai_provider' => 'template-validation-summary-v1',
        'ai_model' => 'template-validation-summary-v1',
        'ai_generated_at' => now(),
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'nba',
        'game_id' => 1001,
        'prediction_id' => 2001,
        'game_date' => now()->toDateString(),
        'as_of_date' => now()->toDateString(),
        'market' => 'game',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_hash' => 'healthcheck-shadow-hash',
        'raw_payload' => [
            'game' => ['matchup' => 'BOS @ LAL'],
        ],
        'recommendation' => 'moneyline',
        'ai_confidence' => 63,
        'analysis_confidence' => 59,
        'bet_classification' => 'lean',
        'summary' => 'Lean Lakers moneyline.',
        'key_factors' => ['Model edge is positive.'],
        'risk_flags' => ['moderate_confidence'],
        'reason_codes' => ['model_home_edge'],
        'market_notes' => ['moneyline' => 'Playable only at a fair number.'],
        'calculated_edge' => ['spread_edge' => 0.9],
        'metadata' => [
            'shadow_agents' => [
                'data_freshness' => ['freshness_status' => 'watch'],
                'market_readiness' => ['market_status' => 'watch'],
                'model_audit' => ['model_status' => 'usable'],
                'publishing_guardrail' => [
                    'decision' => 'downgrade',
                    'publishable_classification' => 'watch',
                    'summary' => 'Props need refresh before official labeling.',
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
        'game_date' => now()->toDateString(),
        'as_of_date' => now()->toDateString(),
        'market' => 'game',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_hash' => 'healthcheck-shadow-keep-hash',
        'raw_payload' => [
            'game' => ['matchup' => 'NYK @ MIA'],
        ],
        'recommendation' => 'spread',
        'ai_confidence' => 65,
        'analysis_confidence' => 63,
        'bet_classification' => 'lean',
        'summary' => 'Lean Heat spread.',
        'key_factors' => ['Model edge is modest.'],
        'risk_flags' => ['moderate_confidence'],
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

    $this->actingAs($admin)
        ->get(route('admin.healthchecks', ['view' => 'validation', 'sport' => 'nba']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Healthchecks')
            ->where('filters.view', 'validation')
            ->where('filters.sport', 'nba')
            ->where('latest_validation_run.scope', 'sport:nba')
            ->where('latest_validation_run.ai_summary.headline', 'NBA validation needs attention')
            ->where('latest_validation_run.ai_summary.recommended_actions.0', 'nba:generate-predictions')
            ->where('ai_publishing.total', 2)
            ->where('ai_publishing.enforcement.mode', 'shadow')
            ->where('ai_publishing.enforcement.enabled', false)
            ->where('ai_publishing.decisions.downgrade', 1)
            ->where('ai_publishing.needs_attention.0.matchup', 'BOS @ LAL')
            ->where('ai_publishing.needs_attention.0.required_actions.0', 'nba:sync-player-props')
            ->where('ai_publishing_trend.total', 2)
            ->where('ai_publishing_trend.changed_count', 1)
            ->where('ai_publishing_trend.changed_rate', 0.5)
            ->where('ai_publishing_trend.changed_rows.0.matchup', 'BOS @ LAL')
        );
});

test('admin healthchecks sync can run recommended validation action', function () {
    $admin = User::factory()->admin()->create();

    Healthcheck::query()->create([
        'sport' => 'nba',
        'check_type' => 'validation_prediction_completeness',
        'status' => 'failing',
        'message' => '1/1 active games are missing prediction rows.',
        'metadata' => [
            'recommended_action' => 'nba:generate-predictions',
        ],
        'checked_at' => now(),
    ]);

    Artisan::spy();
    Artisan::shouldReceive('call')
        ->once()
        ->with('nba:generate-predictions', [])
        ->andReturn(0);

    $this->actingAs($admin)
        ->post(route('admin.healthchecks.sync'), [
            'sport' => 'nba',
            'check_type' => 'validation_prediction_completeness',
        ])
        ->assertRedirect();
});

test('admin healthchecks sync can run registry-backed live scoreboard action', function () {
    $admin = User::factory()->admin()->create();

    Carbon::setTestNow(Carbon::create(2026, 3, 29, 9, 0, 0));

    Artisan::spy();
    Artisan::shouldReceive('call')
        ->once()
        ->with('espn:sync-nba-games-scoreboard', ['date' => '20260329'])
        ->andReturn(0);

    $this->actingAs($admin)
        ->post(route('admin.healthchecks.sync'), [
            'sport' => 'nba',
            'check_type' => 'heartbeat_live_scoreboard',
        ])
        ->assertRedirect();

    Carbon::setTestNow();
});

test('admin healthchecks page exposes validation run history and selected run findings', function () {
    $this->withoutVite();

    $admin = User::factory()->admin()->create();

    $olderRun = ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data',
        'scope' => 'sport:nba',
        'status' => 'failing',
        'summary' => [
            'total_findings' => 3,
            'passing' => 0,
            'warning' => 1,
            'failing' => 2,
        ],
        'ai_summary' => [
            'headline' => 'Older run',
            'intro' => 'Older intro',
            'highlights' => ['Older highlight'],
            'recommended_actions' => ['nba:sync-odds'],
        ],
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHours(2),
    ]);

    $latestRun = ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data',
        'scope' => 'sport:nba',
        'status' => 'warning',
        'summary' => [
            'total_findings' => 2,
            'passing' => 0,
            'warning' => 2,
            'failing' => 0,
        ],
        'ai_summary' => [
            'headline' => 'Latest run',
            'intro' => 'Latest intro',
            'highlights' => ['Latest highlight'],
            'recommended_actions' => ['nba:generate-predictions'],
        ],
        'started_at' => now()->subHour(),
        'completed_at' => now()->subHour(),
    ]);

    ValidationFinding::query()->create([
        'validation_run_id' => $olderRun->id,
        'sport' => 'nba',
        'check_type' => 'validation_prediction_completeness',
        'status' => 'failing',
        'severity' => 'failing',
        'message' => 'Older run missing predictions.',
        'facts' => ['sample_game_ids' => [11, 22]],
        'recommended_action' => 'nba:generate-predictions',
        'detected_at' => now()->subHours(2),
    ]);

    ValidationFinding::query()->create([
        'validation_run_id' => $latestRun->id,
        'sport' => 'nba',
        'check_type' => 'validation_odds_completeness',
        'status' => 'warning',
        'severity' => 'warning',
        'message' => 'Latest run has stale odds.',
        'facts' => ['games_with_stale_odds' => 3],
        'recommended_action' => 'nba:sync-odds',
        'detected_at' => now()->subHour(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.healthchecks', [
            'view' => 'validation',
            'sport' => 'nba',
            'validation_run' => $olderRun->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Healthchecks')
            ->where('selected_validation_run.id', $olderRun->id)
            ->where('selected_validation_run.findings.0.message', 'Older run missing predictions.')
            ->where('recent_validation_runs.0.id', $latestRun->id)
            ->where('recent_validation_runs.1.id', $olderRun->id)
            ->where('validation_trend.direction', 'regressing')
            ->where('validation_trend.points.0.id', $olderRun->id)
            ->where('validation_trend.points.1.id', $latestRun->id)
        );
});
