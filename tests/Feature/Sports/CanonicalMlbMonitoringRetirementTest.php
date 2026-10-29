<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\ShadowModelOutput;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\MLB\MlbModelMonitoringService;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-07-29 12:00:00');
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_reads.mlb' => true]);
});
afterEach(fn () => $this->travelBack());

function mlbMonitoringRetirementFixture(string $variant = 'valid'): array
{
    $run = app(ModelRunRecorder::class)->create(sport: 'mlb', runType: 'shadow_inference', modelVersion: 'test-v1',
        featureVersion: 'core-v1', blendVersion: 'shadow-v1', status: 'completed', completedAt: now());
    $artifact = ModelArtifact::query()->create(['id' => (string) Str::uuid(), 'training_run_id' => $run->id,
        'sport' => 'mlb', 'market_type' => 'win_probability', 'model_type' => 'xgboost_classifier',
        'model_version' => 'test-v1', 'feature_version' => 'core-v1', 'dataset_hash' => str_repeat('a', 64),
        'artifact_path' => 'missing-test-artifact', 'artifact_hash' => hash('sha256', (string) Str::uuid()), 'status' => 'challenger']);
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'starts_at' => now()->subHour(), 'season' => 2026]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'status' => 'STATUS_SCHEDULED',
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_date' => now()->toDateString()]);
    $input = EventInputSnapshot::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id,
        'captured_at' => now()->subHours(3), 'cutoff_at' => now()->subHours(3),
        'latest_source_available_at' => now()->subHours(4), 'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified',
        'inputs' => ['home' => ['elo' => 1500], 'unused_diagnostics' => str_repeat('large', 1000)]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'mlb', 'phase' => 'pregame']);
    $calculation = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id,
        'calculation_run_id' => $calculation->id, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'generated_at' => now()->subHours(2), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.65], ['spread', 'home', -2.0, null], ['total', 'combined', 8.0, null]] as [$type, $selection, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $selection, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()->subHours(2)]);
    if (in_array($variant, ['withdrawn', 'superseded'], true)) {
        $native->update(['publication_state' => $variant, $variant.'_at' => now()]);
    }
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 5, 'away_score' => 2]);
    $legacy = PredictionFeatureSnapshot::query()->create(['sport' => 'mlb', 'prediction_table' => 'mlb_predictions',
        'prediction_id' => 500, 'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(), 'model_run_id' => $run->id,
        'model_version' => 'legacy-v1', 'feature_version' => 'core-v1', 'blend_version' => 'legacy-v1', 'features' => [],
        'outputs' => ['baseline_win_probability' => 0.6, 'baseline_predicted_spread' => 1.0, 'baseline_predicted_total' => 9.0],
        'feature_hash' => str_repeat('c', 64), 'pregame_safe' => true, 'availability_status' => 'observed_pregame',
        'generated_at' => now()->subHours(2), 'features_available_at' => now()->subHours(3), 'game_start_at' => now()->subHour()]);
    $shadow = ShadowModelOutput::query()->create(['inference_run_id' => $run->id, 'model_artifact_id' => $artifact->id,
        'prediction_feature_snapshot_id' => $variant === 'legacy' ? $legacy->id : null,
        'canonical_prediction_id' => $variant === 'legacy' ? null : $native->id, 'sport' => 'mlb',
        'game_table' => 'mlb_games', 'game_id' => $game->id, 'prediction_table' => $variant === 'legacy' ? 'mlb_predictions' : 'predictions',
        'prediction_id' => $variant === 'legacy' ? 500 : $native->id, 'market_type' => 'win_probability',
        'baseline_output' => 0.65, 'challenger_output' => 0.75, 'output_delta' => 0.1, 'status' => 'shadow',
        'explanation' => ['challenger_outputs' => ['win_probability' => 0.75, 'predicted_spread' => 3.5, 'predicted_total' => 8.5]],
        'generated_at' => now()->subHours(2)]);

    return [$artifact, $native, $shadow, $legacy];
}

function mlbMonitoringRetirementReport(string $artifactId): array
{
    DB::enableQueryLog();
    try {
        $report = app(MlbModelMonitoringService::class)->dashboard($artifactId);

        return [$report, collect(DB::getQueryLog())->pluck('query')];
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

it('uses frozen canonical baselines for monitoring without legacy snapshots or full input JSON', function (string $state) {
    [$artifact, , , $legacy] = mlbMonitoringRetirementFixture($state);
    [$report, $queries] = mlbMonitoringRetirementReport($artifact->id);
    $week = $report['weekly_performance'][0];
    expect($week['baseline_brier'])->toBe(0.1225)
        ->and($week['baseline_margin_mae'])->toBe(1.0)->and($week['baseline_total_mae'])->toBe(1.0)
        ->and($week['challenger_margin_mae'])->toBe(0.5)->and($week['challenger_total_mae'])->toBe(1.5)
        ->and($report['observations'][0]['pregame_safe'])->toBeTrue()
        ->and($report['observations'][0]['feature_hash'])->not->toBe($legacy->feature_hash)
        ->and($queries->implode(' '))->not->toContain('prediction_feature_snapshots')->not->toContain('mlb_predictions');
    $inputQueries = $queries->filter(fn (string $query): bool => str_contains($query, 'from "event_input_snapshots"') && ! str_contains($query, 'from "predictions"'));
    expect($inputQueries)->toHaveCount(2);
    foreach ($inputQueries as $query) {
        expect($query)->not->toContain('select *')->not->toContain('"inputs"');
    }
})->with(['valid', 'superseded']);

it('never fills native monitoring gaps from conflicting legacy snapshot references', function (string $state) {
    [$artifact, , $shadow, $legacy] = mlbMonitoringRetirementFixture($state);
    $shadow->update(['prediction_feature_snapshot_id' => $legacy->id]);
    if ($state === 'wrong_game') {
        $otherGame = Game::factory()->create(['season' => 2026, 'status' => 'STATUS_FINAL',
            'home_score' => 5, 'away_score' => 2, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
        $shadow->update(['game_id' => $otherGame->id]);
    }
    [$report, $queries] = mlbMonitoringRetirementReport($artifact->id);
    expect($report['weekly_performance'][0]['baseline_margin_mae'])->toBeNull()
        ->and($report['weekly_performance'][0]['baseline_total_mae'])->toBeNull()
        ->and($report['observations'][0]['pregame_safe'])->toBeFalse()
        ->and($report['observations'][0]['feature_hash'])->toBeNull()
        ->and($queries->implode(' '))->not->toContain('prediction_feature_snapshots');
})->with(['withdrawn', 'unsafe', 'wrong_game']);

it('preserves historical legacy monitoring even when canonical reads are enabled', function () {
    [$artifact, , , $legacy] = mlbMonitoringRetirementFixture('legacy');
    [$report] = mlbMonitoringRetirementReport($artifact->id);
    expect($report['weekly_performance'][0]['baseline_brier'])->toBe(0.16)
        ->and($report['weekly_performance'][0]['baseline_margin_mae'])->toBe(2.0)
        ->and($report['weekly_performance'][0]['baseline_total_mae'])->toBe(2.0)
        ->and($report['observations'][0]['feature_hash'])->toBe($legacy->feature_hash);
});

it('keeps recorded multi-market outputs ahead of baseline snapshot fallbacks', function () {
    [$artifact, , $shadow] = mlbMonitoringRetirementFixture();
    $shadow->update(['explanation' => [...$shadow->explanation,
        'baseline_outputs' => ['win_probability' => 0.55, 'predicted_spread' => 1.5, 'predicted_total' => 10.0]]]);
    [$report] = mlbMonitoringRetirementReport($artifact->id);
    expect($report['weekly_performance'][0]['baseline_brier'])->toBe(0.2025)
        ->and($report['weekly_performance'][0]['baseline_margin_mae'])->toBe(1.5)
        ->and($report['weekly_performance'][0]['baseline_total_mae'])->toBe(3.0);
});

it('keeps native monitoring snapshots batched as observation count grows', function () {
    for ($index = 0; $index < 5; $index++) {
        [$artifact] = mlbMonitoringRetirementFixture();
    }
    ShadowModelOutput::query()->update(['model_artifact_id' => $artifact->id]);
    [$report, $queries] = mlbMonitoringRetirementReport($artifact->id);
    expect($report['weekly_performance'][0]['games'])->toBe(5)
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'from "event_input_snapshots"') && ! str_contains($query, 'from "predictions"')))->toHaveCount(2)
        ->and($queries->implode(' '))->not->toContain('prediction_feature_snapshots');
});

it('retains admin access and the existing monitoring page shape for native observations', function () {
    mlbMonitoringRetirementFixture();
    $route = route('admin.mlb-model-monitoring');
    $this->get($route)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get($route)->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get($route)->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/MlbModelMonitoring')
            ->has('observations', 1)->where('observations.0.pregame_safe', true)
            ->where('weekly_performance.0.baseline_margin_mae', 1)
            ->where('summary.decisions', 0)->where('summary.tracking_bets', 0));
});

it('retains recorded native source identity after switching public reads back to legacy', function () {
    [$artifact, , $shadow, $legacy] = mlbMonitoringRetirementFixture();
    $shadow->update(['prediction_feature_snapshot_id' => $legacy->id]);
    config(['prediction_lifecycle.canonical_reads.mlb' => false]);
    [$report] = mlbMonitoringRetirementReport($artifact->id);
    expect($report['weekly_performance'][0]['baseline_margin_mae'])->toBe(1.0)
        ->and($report['observations'][0]['feature_hash'])->not->toBe($legacy->feature_hash);
});

it('does not replace missing historical snapshot lineage with a current canonical forecast', function () {
    [$artifact, , $shadow] = mlbMonitoringRetirementFixture('legacy');
    $shadow->update(['prediction_feature_snapshot_id' => null]);
    [$report] = mlbMonitoringRetirementReport($artifact->id);
    expect($report['weekly_performance'][0]['baseline_margin_mae'])->toBeNull()
        ->and($report['weekly_performance'][0]['baseline_total_mae'])->toBeNull()
        ->and($report['observations'][0]['pregame_safe'])->toBeFalse();
});

it('does not use full-game canonical baselines as F3 or F5 period forecasts', function (string $market) {
    [$artifact, , $shadow] = mlbMonitoringRetirementFixture();
    $shadow->update(['market_type' => $market, 'explanation' => [
        'period_moneyline_contract' => true,
        'baseline_outputs' => ['conditional_home_win_probability' => 0.6],
        'challenger_outputs' => ['home_win_probability' => 0.65, 'conditional_home_win_probability' => 0.75],
    ]]);
    [$report] = mlbMonitoringRetirementReport($artifact->id);
    expect($report['weekly_performance'][0]['baseline_brier'])->toBeNull()
        ->and($report['weekly_performance'][0]['baseline_margin_mae'])->toBeNull()
        ->and($report['weekly_performance'][0]['baseline_total_mae'])->toBeNull()
        ->and($report['observations'][0]['pregame_safe'])->toBeTrue();
})->with(['first_3_moneyline', 'first_5_moneyline']);
