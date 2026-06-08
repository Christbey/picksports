<?php

use App\Actions\Validation\SportValidator;
use App\AI\Agents\ValidationReviewSummaryAgent;
use App\Models\CommandHeartbeat;
use App\Models\Healthcheck;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\User;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Notifications\ValidationRegressionAlert;
use Illuminate\Support\Facades\DB;
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
        'validation_upcoming_game_readiness',
        'validation_odds_completeness',
        'validation_injury_freshness',
        'validation_player_prop_freshness',
        'validation_futures_odds_freshness',
        'validation_pipeline_order',
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

test('healthcheck validate data flags current day final games missing stats', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'status' => 'STATUS_FINAL',
        'game_date' => now()->copy()->subHours(3),
        'home_score' => 118,
        'away_score' => 110,
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'nba'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    $finding = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_current_day_game_data_freshness')
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe('failing')
        ->and($finding->recommended_action)->toBe('espn:sync-nba-game-details')
        ->and(data_get($finding->facts, 'games_today'))->toBe(1)
        ->and(data_get($finding->facts, 'final_games_missing_team_stats'))->toBe(1)
        ->and(data_get($finding->facts, 'final_games_missing_both_team_stats'))->toBe(1)
        ->and(data_get($finding->facts, 'final_games_missing_player_stats'))->toBe(1)
        ->and(data_get($finding->facts, 'final_games_missing_plays'))->toBe(1)
        ->and(data_get($finding->facts, 'sample_game_ids'))->toContain($game->id)
        ->and(data_get($finding->facts, 'sample_games.0.game_id'))->toBe($game->id)
        ->and(data_get($finding->facts, 'sample_games.0.reasons'))->toContain(
            'missing_team_stats',
            'missing_both_team_stats',
            'missing_player_stats',
            'missing_plays',
        );
});

test('healthcheck validate data flags upcoming game page readiness gaps', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'season_type' => 2,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->copy()->addDay(),
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'nba'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    $finding = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_upcoming_game_readiness')
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe('failing')
        ->and($finding->recommended_action)->toBe('sports:operations-sentinel --sport=nba')
        ->and(data_get($finding->facts, 'upcoming_games'))->toBe(1)
        ->and(data_get($finding->facts, 'games_missing_predictions'))->toBe(1)
        ->and(data_get($finding->facts, 'games_missing_odds'))->toBe(1)
        ->and(data_get($finding->facts, 'games_missing_team_metrics'))->toBe(1)
        ->and(data_get($finding->facts, 'sample_game_ids'))->toContain($game->id)
        ->and(data_get($finding->facts, 'sample_games.0.reasons'))->toContain('missing_prediction', 'missing_odds', 'missing_team_metrics');
});

test('healthcheck validate data passes upcoming game page readiness when pregame data exists', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'season_type' => 2,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->copy()->addDay(),
        'odds_data' => [
            'bookmakers' => [
                ['markets' => [['key' => 'h2h'], ['key' => 'spreads'], ['key' => 'totals']]],
            ],
        ],
        'odds_updated_at' => now(),
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 2.5,
        'predicted_total' => 224.5,
        'win_probability' => 0.56,
        'confidence_score' => 61.2,
    ]);

    foreach ([$home, $away] as $team) {
        DB::table('nba_team_metrics')->insert([
            'team_id' => $team->id,
            'season' => (int) now()->year,
            'offensive_efficiency' => 115.2,
            'defensive_efficiency' => 111.8,
            'net_rating' => 3.4,
            'tempo' => 99.1,
            'calculation_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->artisan('healthcheck:validate-data', ['--sport' => 'nba'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    $finding = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_upcoming_game_readiness')
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe('passing')
        ->and(data_get($finding->facts, 'upcoming_games'))->toBe(1)
        ->and(data_get($finding->facts, 'sample_game_ids'))->toBe([]);
});

test('healthcheck validate data warns when fresh player props have no recommendation-ready output', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->copy()->addDay(),
        'odds_api_event_id' => 'odds-event-1',
        'odds_updated_at' => now(),
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [
                    ['key' => 'h2h'],
                    ['key' => 'spreads'],
                    ['key' => 'totals'],
                ],
            ]],
        ],
    ]);

    DB::table('nba_player_props')->insert([
        'game_id' => $game->id,
        'player_name' => 'Unscored Prop',
        'market' => 'player_threes',
        'bookmaker' => 'draftkings',
        'line' => 1.5,
        'over_price' => -110,
        'under_price' => -110,
        'fetched_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'nba'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    $finding = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_player_prop_freshness')
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe('warning')
        ->and($finding->recommended_action)->toBe('sports:analyze-player-props --sport=nba')
        ->and(data_get($finding->facts, 'games_with_unscored_player_props'))->toBe(1)
        ->and(data_get($finding->facts, 'sample_game_ids'))->toBe([])
        ->and(data_get($finding->facts, 'sample_unscored_game_ids'))->toContain($game->id);
});

test('healthcheck validate data flags missing weather for outdoor sports', function () {
    $home = MlbTeam::factory()->create();
    $away = MlbTeam::factory()->create();

    $game = MlbGame::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->copy()->addDay(),
        'venue_name' => 'Kauffman Stadium',
        'venue_city' => 'Kansas City',
        'venue_state' => 'MO',
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'mlb'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    $finding = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_weather_completeness')
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe('failing')
        ->and($finding->recommended_action)->toBe('mlb:sync-game-weather --days-back=0 --days-forward=7 --force')
        ->and(data_get($finding->facts, 'upcoming_games'))->toBe(1)
        ->and(data_get($finding->facts, 'games_missing_weather'))->toBe(1)
        ->and(data_get($finding->facts, 'sample_game_ids'))->toContain($game->id);
});

test('healthcheck validate data flags past mlb games stuck as scheduled', function () {
    $home = MlbTeam::factory()->create();
    $away = MlbTeam::factory()->create();

    $game = MlbGame::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->copy()->subDay()->toDateString(),
        'game_time' => '19:10:00',
        'short_name' => 'NYM @ SF',
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'mlb'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    $finding = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_past_scheduled_game_status')
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe('failing')
        ->and($finding->recommended_action)->toContain('espn:sync-mlb-games-scoreboard --from-date=')
        ->and(data_get($finding->facts, 'stale_games'))->toBe(1)
        ->and(data_get($finding->facts, 'sample_game_ids'))->toContain($game->id)
        ->and(data_get($finding->facts, 'sample_games.0.matchup'))->toBe('NYM @ SF');
});

test('healthcheck validate data does not flag same day future games as past scheduled', function () {
    $this->travelTo('2026-06-07 09:00:00');

    $home = MlbTeam::factory()->create();
    $away = MlbTeam::factory()->create();

    $game = MlbGame::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-07',
        'game_time' => '19:10:00',
        'short_name' => 'NYM @ SF',
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'mlb'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    $finding = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_past_scheduled_game_status')
        ->first();

    expect($finding)->not->toBeNull()
        ->and(data_get($finding->facts, 'sample_game_ids'))->not->toContain($game->id);
});

test('healthcheck validate data summary is scoped to the current validation run', function () {
    Healthcheck::query()->create([
        'sport' => 'nba',
        'check_type' => 'validation_past_scheduled_game_status',
        'status' => 'failing',
        'message' => 'Old stale game failure from a previous run.',
        'metadata' => [],
        'checked_at' => now()->subMinute(),
    ]);

    $validator = Mockery::mock(SportValidator::class);
    $validator->shouldReceive('validate')
        ->once()
        ->with('nba')
        ->andReturn([[
            'check_type' => 'validation_stub_check',
            'status' => 'passing',
            'message' => 'Current run is clean.',
            'metadata' => [],
        ]]);

    $this->app->instance(SportValidator::class, $validator);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'nba'])
        ->expectsOutput('Validation Summary:')
        ->expectsOutputToContain('passing:')
        ->doesntExpectOutputToContain('failing:')
        ->assertExitCode(0);
});

test('healthcheck validate data flags ungraded final mlb predictions', function () {
    $home = MlbTeam::factory()->create();
    $away = MlbTeam::factory()->create();

    $game = MlbGame::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => (int) now()->year,
        'status' => 'STATUS_FINAL',
        'game_date' => now()->copy()->subDay()->toDateString(),
        'home_score' => 4,
        'away_score' => 2,
    ]);

    App\Models\MLB\Prediction::query()->create([
        'game_id' => $game->id,
        'season' => (int) now()->year,
        'season_type' => (string) config('mlb.season.types.regular'),
        'home_team_elo' => 1510,
        'away_team_elo' => 1490,
        'home_pitcher_elo' => 1520,
        'away_pitcher_elo' => 1480,
        'home_combined_elo' => 1515,
        'away_combined_elo' => 1485,
        'predicted_spread' => 1.5,
        'predicted_total' => 8.5,
        'win_probability' => 0.61,
        'confidence_score' => 64,
        'model_version' => 'test',
        'feature_version' => 'test',
        'blend_version' => 'test',
        'graded_at' => null,
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'mlb'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    $finding = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_finalized_data_completeness')
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe('failing')
        ->and(data_get($finding->facts, 'games_missing_grading'))->toBe(1)
        ->and(data_get($finding->facts, 'sample_game_ids'))->toContain($game->id);
});

test('healthcheck validate data flags stale futures odds', function () {
    DB::table('sports_futures_odds')->insert([
        'row_key' => 'nba-title-lakers',
        'sport' => 'nba',
        'season' => (int) now()->year,
        'odds_api_sport_key' => 'basketball_nba_championship_winner',
        'bookmaker' => 'draftkings',
        'market_key' => 'championship_winner',
        'outcome_name' => 'Los Angeles Lakers',
        'price' => 1200,
        'fetched_at' => now()->subHours(24),
        'created_at' => now()->subHours(24),
        'updated_at' => now()->subHours(24),
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'nba'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    $finding = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_futures_odds_freshness')
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe('failing')
        ->and($finding->recommended_action)->toBe('sports:sync-futures-odds --sport=nba --season='.(int) now()->year)
        ->and(data_get($finding->facts, 'stale'))->toBeTrue();
});

test('healthcheck validate data flags pipeline order violations', function () {
    CommandHeartbeat::query()->create([
        'sport' => 'nba',
        'command' => 'espn:sync-nba-game-details',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now(),
    ]);

    CommandHeartbeat::query()->create([
        'sport' => 'nba',
        'command' => 'nba:generate-predictions',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now()->subHour(),
    ]);

    $this->artisan('healthcheck:validate-data', ['--sport' => 'nba'])
        ->assertExitCode(1);

    $run = ValidationRun::query()->latest('id')->first();

    $finding = ValidationFinding::query()
        ->where('validation_run_id', $run->id)
        ->where('check_type', 'validation_pipeline_order')
        ->first();

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe('failing')
        ->and($finding->recommended_action)->toBe('nba:generate-predictions')
        ->and(data_get($finding->facts, 'violations.0.label'))->toBe('details before predictions');
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
            'latest_data_fresh_at' => 'Not fully fresh in the latest run completed May 30, 2026 8:00 AM',
            'data_schedule_today' => [
                'Scoreboards refresh every 5 minutes.',
                'Validation runs before the admin report.',
            ],
            'tweak_recommendations' => [
                'Run odds sync closer to digest generation.',
            ],
            'operational_status' => 'degraded',
            'trust_score' => 72,
            'blocked_outputs' => [
                'Official bet classifications that require fresh odds.',
            ],
            'safe_adjustments' => [
                'nba:generate-predictions',
                'nba:sync-odds',
            ],
            'data_quality_notes' => [
                'NBA odds coverage is incomplete.',
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
        ->and($run->ai_summary['latest_data_fresh_at'])->toContain('Not fully fresh')
        ->and($run->ai_summary['data_schedule_today'])->toContain('Validation runs before the admin report.')
        ->and($run->ai_summary['tweak_recommendations'])->toContain('Run odds sync closer to digest generation.')
        ->and($run->ai_summary['operational_status'])->toBe('degraded')
        ->and($run->ai_summary['trust_score'])->toBe(72)
        ->and($run->ai_summary['blocked_outputs'])->toContain('Official bet classifications that require fresh odds.')
        ->and($run->ai_summary['safe_adjustments'])->toContain('nba:sync-odds')
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
