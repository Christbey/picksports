<?php

use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\artisan;

uses()->group('sports');

it('treats expected championship low game volume as watch instead of blocked', function () {
    $this->travelTo('2026-06-06 12:00:00');

    $knicks = Team::factory()->create(['conference' => 'Eastern']);
    $spurs = Team::factory()->create(['conference' => 'Western']);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $knicks->id,
        'away_team_id' => $spurs->id,
        'game_date' => '2026-06-04',
        'status' => 'STATUS_FINAL',
        'home_score' => 111,
        'away_score' => 106,
    ]);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'home_team_id' => $spurs->id,
        'away_team_id' => $knicks->id,
        'game_date' => '2026-06-08',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $run = ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data',
        'scope' => 'sport:nba',
        'status' => 'warning',
        'summary' => ['passing' => 12, 'warning' => 1, 'failing' => 0],
        'ai_summary' => [
            'headline' => 'NBA validation is operationally clean.',
            'trust_score' => 96,
            'latest_data_fresh_at' => '2026-06-06T12:00:00-05:00',
            'blocked_outputs' => [],
            'safe_adjustments' => ['Treat low upcoming game volume as expected for the Finals.'],
            'generated_by' => 'template-validation-summary-v1',
        ],
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    ValidationFinding::query()->create([
        'validation_run_id' => $run->id,
        'sport' => 'nba',
        'check_type' => 'validation_game_coverage',
        'scope_type' => 'sport',
        'scope_id' => 'nba',
        'status' => 'warning',
        'severity' => 'warning',
        'message' => 'Upcoming game volume is low because the Finals are active.',
        'recommended_action' => null,
        'detected_at' => now(),
    ]);

    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'nba',
        'game_id' => 1,
        'prediction_id' => 1,
        'game_date' => '2026-06-08',
        'as_of_date' => '2026-06-06',
        'market' => 'moneyline',
        'provider' => 'openai',
        'model' => 'test-model',
        'input_hash' => 'nba-finals-watch',
        'raw_payload' => [],
        'recommendation' => 'watch',
        'ai_confidence' => 78,
        'analysis_confidence' => 82,
        'bet_classification' => 'watchlist',
        'summary' => 'AI guardrail allows watchlist output.',
        'metadata' => [
            'shadow_agents' => [
                'publishing_guardrail' => [
                    'decision' => 'allow',
                ],
            ],
        ],
    ]);

    artisan('operations:ai-review --sport=nba --season=2026 --date=2026-06-06')
        ->expectsOutput('NBA Operations AI Review')
        ->expectsOutput('Status: watch | Trust: 96 | Safe to publish: yes')
        ->expectsOutput('Stage: finals / championship')
        ->expectsOutput('AI analyses today: 1')
        ->expectsOutputToContain('Low upcoming game volume is expected for the championship stage')
        ->assertSuccessful();
});

it('blocks publishing when the latest sport validation run has failing findings', function () {
    $this->travelTo('2026-06-06 12:00:00');

    $run = ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data',
        'scope' => 'sport:nba',
        'status' => 'failing',
        'summary' => ['passing' => 10, 'warning' => 0, 'failing' => 1],
        'ai_summary' => [],
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    ValidationFinding::query()->create([
        'validation_run_id' => $run->id,
        'sport' => 'nba',
        'check_type' => 'validation_past_scheduled_game_status',
        'scope_type' => 'sport',
        'scope_id' => 'nba',
        'status' => 'failing',
        'severity' => 'critical',
        'message' => 'Past games are still scheduled.',
        'recommended_action' => 'Run the operations sentinel before publishing.',
        'detected_at' => now(),
    ]);

    $exit = Artisan::call('operations:ai-review', [
        '--sport' => 'nba',
        '--season' => 2026,
        '--date' => '2026-06-06',
        '--json' => true,
    ]);

    expect($exit)->toBe(0);

    $report = json_decode(Artisan::output(), true);

    expect($report['status'])->toBe('blocked')
        ->and($report['safe_to_publish'])->toBeFalse()
        ->and($report['recommended_actions'])->toContain('Run the operations sentinel before publishing.');
});
