<?php

use App\Models\Healthcheck;
use App\Models\User;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
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
        ->with('nba:generate-predictions')
        ->andReturn(0);

    $this->actingAs($admin)
        ->post(route('admin.healthchecks.sync'), [
            'sport' => 'nba',
            'check_type' => 'validation_prediction_completeness',
        ])
        ->assertRedirect();
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
