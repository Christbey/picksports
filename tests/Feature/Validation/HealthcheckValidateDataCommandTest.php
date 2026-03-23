<?php

use App\AI\Agents\ValidationReviewSummaryAgent;
use App\Models\Healthcheck;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\User;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Notifications\ValidationRegressionAlert;
use Illuminate\Support\Facades\Notification;

test('healthcheck validate data persists validation run and completeness findings', function () {
    $home = Team::factory()->create([
        'abbreviation' => 'LAL',
        'name' => 'Lakers',
    ]);
    $away = Team::factory()->create([
        'abbreviation' => 'BOS',
        'name' => 'Celtics',
    ]);

    Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->copy()->addDay(),
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $finalGame = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'status' => 'STATUS_FINAL',
        'game_date' => now()->copy()->subDay(),
        'home_score' => 112,
        'away_score' => 108,
    ]);

    Prediction::query()->create([
        'game_id' => $finalGame->id,
        'predicted_spread' => 3.5,
        'predicted_total' => 228.5,
        'win_probability' => 0.63,
        'confidence_score' => 74.2,
        'graded_at' => null,
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'nba'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->command_name)->toBe('healthcheck:validate-data')
        ->and($run->scope)->toBe('sport:nba')
        ->and($run->status)->toBe('failing')
        ->and($run->summary)->toBeArray()
        ->and($run->summary['total_findings'])->toBeGreaterThan(0);

    $findingTypes = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->pluck('check_type')
        ->all();

    expect($findingTypes)->toContain(
        'validation_prediction_completeness',
        'validation_odds_completeness',
        'validation_finalized_data_completeness'
    );

    expect(ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_prediction_completeness')
        ->value('recommended_action'))
        ->toBe('nba:generate-predictions');

    expect(ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_odds_completeness')
        ->value('recommended_action'))
        ->toBe('nba:sync-odds');

    expect(ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_finalized_data_completeness')
        ->value('recommended_action'))
        ->toBe('espn:sync-nba-game-details');

    $healthcheck = Healthcheck::query()
        ->where('check_type', 'validation_prediction_completeness')
        ->latest('id')
        ->first();

    expect($healthcheck)->not->toBeNull()
        ->and(data_get($healthcheck->metadata, 'validation_run_id'))->toBe($run->id);
});

test('healthcheck validate data persists ai validation summary when enabled', function () {
    config()->set('ai.features.validation_review_summary.enabled', true);
    config()->set('ai.features.validation_review_summary.provider', 'openai');
    config()->set('ai.features.validation_review_summary.model', 'gpt-4o-mini');
    config()->set('services.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.key', 'test-openai-key');

    ValidationReviewSummaryAgent::fake([
        [
            'headline' => 'NBA validation needs attention',
            'intro' => 'Missing predictions and stale odds are the biggest issues in this validation run.',
            'highlights' => [
                'Prediction completeness is failing for the active board.',
                'Odds coverage is incomplete for scheduled games.',
            ],
            'recommended_actions' => [
                'nba:generate-predictions',
                'nba:sync-odds',
            ],
        ],
    ])->preventStrayPrompts();

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->copy()->addDay(),
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'nba'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->ai_summary)->toBeArray()
        ->and($run->ai_summary['headline'])->toBe('NBA validation needs attention')
        ->and($run->ai_summary['recommended_actions'])->toContain('nba:generate-predictions', 'nba:sync-odds')
        ->and($run->ai_provider)->toBe('openai')
        ->and($run->ai_model)->toBe('gpt-4o-mini')
        ->and($run->ai_generated_at)->not->toBeNull();
});

test('healthcheck validate data notifies admins when a validation run regresses', function () {
    Notification::fake();

    config()->set('validation.regression_alerts.enabled', true);
    config()->set('validation.regression_alerts.failing_delta_threshold', 1);
    config()->set('ai.features.validation_review_summary.enabled', false);

    $admin = User::factory()->admin()->create();

    ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data',
        'scope' => 'sport:nba',
        'status' => 'passing',
        'summary' => [
            'total_findings' => 5,
            'passing' => 5,
            'warning' => 0,
            'failing' => 0,
        ],
        'ai_summary' => [
            'headline' => 'Previous run',
            'intro' => 'Previous run was healthy.',
            'highlights' => [],
            'recommended_actions' => [],
            'generated_by' => 'template-validation-summary-v1',
        ],
        'started_at' => now()->subHour(),
        'completed_at' => now()->subHour(),
    ]);

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->copy()->addDay(),
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'nba'])
        ->assertExitCode(1);

    Notification::assertSentTo($admin, ValidationRegressionAlert::class, function (ValidationRegressionAlert $notification) {
        return $notification->run->scope === 'sport:nba'
            && $notification->delta['failing'] >= 1;
    });
});
