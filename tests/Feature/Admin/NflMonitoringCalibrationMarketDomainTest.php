<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\ModelArtifact;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Models\User;
use App\Services\NFL\NflMonitorReportCache;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('does not treat recorded spread and total point outputs as binary probability calibration values', function () {
    $this->withoutVite(); // Same data-response boundary as existing admin monitoring fixture.
    Http::preventStrayRequests();
    Queue::fake();
    config(['inertia.ssr.enabled' => false]); // Owning HTTP payload contract, not SSR runtime qualification.
    $this->travelTo('2026-09-17 12:00:00');
    $admin = User::factory()->admin()->create();
    $run = app(ModelRunRecorder::class)->create(
        sport: 'nfl', runType: 'profile_validation', modelVersion: 'synthetic-monitor-calibration',
        featureVersion: 'nfl-pregame-core-v1', blendVersion: 'synthetic-monitor-calibration',
        status: 'completed', completedAt: now(),
    );
    $artifact = ModelArtifact::query()->create([
        'id' => (string) Str::uuid(), 'training_run_id' => $run->id, 'sport' => 'nfl',
        'market_type' => 'multi_market', 'model_type' => 'nfl_full_historical_profile',
        'model_version' => 'synthetic-monitor-calibration', 'feature_version' => 'nfl-pregame-core-v1',
        'dataset_hash' => str_repeat('a', 64), 'artifact_hash' => str_repeat('b', 64),
        'artifact_path' => storage_path('framework/testing/unmaterialized-calibration-source.json'),
        'evaluation_report_path' => null, 'evaluation_report_hash' => null, 'status' => 'shadow',
    ]);
    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-09-17', 'game_time' => '20:00:00', 'home_score' => null, 'away_score' => null,
    ]);
    $snapshot = PredictionFeatureSnapshot::query()->create([
        'sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => 901,
        'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(), 'model_run_id' => $run->id,
        'model_version' => 'synthetic-monitor-calibration', 'feature_version' => 'nfl-pregame-core-v1',
        'blend_version' => 'synthetic-monitor-calibration',
        'features' => ['feature_market_home_spread' => 3.5, 'feature_market_total' => 44.5],
        'outputs' => ['baseline_win_probability' => 0.6, 'challenger_win_probability' => 0.65],
        'feature_hash' => str_repeat('c', 64), 'model_metadata' => [],
        'generated_at' => '2026-09-17 12:00:00', 'features_available_at' => '2026-09-17 12:00:00',
        'game_start_at' => '2026-09-17 20:00:00', 'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    $odds = GameOddsSnapshot::query()->create([
        'sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id, 'source' => 'synthetic',
        'commence_time' => '2026-09-17 20:00:00', 'captured_at' => '2026-09-17 11:59:00',
        'payload_hash' => hash('sha256', 'calibration-odds-'.$game->id), 'odds_data' => [],
    ]);
    foreach ([['h2h', 'home', null], ['spreads', 'home', -3.5], ['totals', 'over', 44.5]] as [$market, $side, $line]) {
        MarketQuote::query()->create([
            'game_odds_snapshot_id' => $odds->id, 'sport' => 'nfl', 'game_table' => 'nfl_games',
            'game_id' => $game->id, 'source' => 'synthetic', 'bookmaker_key' => 'syntheticbook',
            'market_key' => $market, 'side' => $side, 'line' => $line, 'price' => -110,
            'implied_probability' => 0.52, 'no_vig_probability' => 0.5,
            'commence_time' => '2026-09-17 20:00:00', 'captured_at' => '2026-09-17 11:59:00',
            'is_pregame' => true, 'quote_hash' => hash('sha256', $market.'-'.$game->id),
        ]);
    }
    $addOutput = fn (string $market, float $baseline, float $challenger) => ShadowModelOutput::query()->create([
        'inference_run_id' => $run->id, 'model_artifact_id' => $artifact->id,
        'prediction_feature_snapshot_id' => $snapshot->id, 'sport' => 'nfl', 'game_table' => 'nfl_games',
        'game_id' => $game->id, 'prediction_table' => 'nfl_predictions', 'prediction_id' => 901,
        'market_type' => $market, 'baseline_output' => $baseline, 'challenger_output' => $challenger,
        'output_delta' => $challenger - $baseline, 'status' => 'shadow',
        'explanation' => ['challenger_outputs' => ['win_probability' => 0.65, 'home_cover_probability' => 0.6, 'over_probability' => 0.6]],
        'generated_at' => '2026-09-17 12:00:00',
    ]);
    $win = $addOutput('win_probability', 0.6, 0.65);
    $addOutput('spread', 3.5, 4.5);
    $addOutput('total', 44.5, 45.5);
    // All observations are created before kickoff; the actual command limit stages its queue.
    $this->artisan('sports:record-shadow-bet-decisions', ['--sport' => 'nfl', '--artifact' => $artifact->id, '--limit' => 1])->assertSuccessful();
    $stageOneDecisions = BetDecision::query()->where('model_artifact_id', $artifact->id)->orderBy('id')->get();
    $orderedStoredOutputIds = ShadowModelOutput::query()->where('model_artifact_id', $artifact->id)->orderBy('id')->pluck('id')->all();
    $this->travelTo('2026-09-18 12:00:00');
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 27, 'away_score' => 20]);
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl'])->assertSuccessful();
    app(NflMonitorReportCache::class)->refresh();
    $winOnly = null;
    $this->actingAs($admin)->get(route('admin.nfl-model-monitoring'))->assertOk()
        ->assertInertia(function (Assert $page) use (&$winOnly) {
            $page->component('Admin/NflModelMonitoring');
            $winOnly = $page->toArray()['props']['summary'];
        });
    // Remaining already-stored pregame observations are processed after completion, not fabricated then.
    $this->artisan('sports:record-shadow-bet-decisions', ['--sport' => 'nfl', '--artifact' => $artifact->id])->assertSuccessful();
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'nfl'])->assertSuccessful();
    app(NflMonitorReportCache::class)->refresh();
    $mixed = null;
    $this->actingAs($admin)->get(route('admin.nfl-model-monitoring'))->assertOk()
        ->assertInertia(function (Assert $page) use (&$mixed) {
            $page->component('Admin/NflModelMonitoring');
            $mixed = $page->toArray()['props']['summary'];
        });
    $decisions = BetDecision::query()->where('model_artifact_id', $artifact->id)->orderBy('id')->get();
    $settlements = BetSettlement::query()->whereIn('bet_decision_id', $decisions->modelKeys())->orderBy('id')->get();
    if ($path = getenv('REVIEW_NFL_CALIBRATION_TRACE')) {
        file_put_contents($path, json_encode([
            'win_only_http_summary' => $winOnly, 'mixed_http_summary' => $mixed,
            'stage_one_decisions' => $stageOneDecisions->map->getRawOriginal()->all(),
            'ordered_stored_output_ids' => $orderedStoredOutputIds, 'expected_win_output_id' => $win->id,
            'artifact' => $artifact->fresh()->getRawOriginal(), 'snapshot' => $snapshot->fresh()->getRawOriginal(),
            'outputs' => ShadowModelOutput::query()->where('model_artifact_id', $artifact->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'decisions' => $decisions->map->getRawOriginal()->all(), 'settlements' => $settlements->map->getRawOriginal()->all(),
        ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
    }
    // Qualify producer/settler reachability before interpreting the probability-domain assertion.
    expect($stageOneDecisions)->toHaveCount(1)
        ->and((int) $stageOneDecisions->first()->shadow_model_output_id)->toBe((int) $win->id)
        ->and((int) $orderedStoredOutputIds[0])->toBe((int) $win->id)
        ->and($decisions->pluck('market_type')->sort()->values()->all())->toBe(['moneyline', 'spread', 'total'])
        ->and($decisions->every(fn ($row) => ! $row->is_bet && $row->is_tracking_only))->toBeTrue()
        ->and($settlements)->toHaveCount(3)
        ->and($winOnly['baseline_brier'])->toEqualWithDelta(0.16, 1e-12)
        ->and($winOnly['challenger_brier'])->toEqualWithDelta(0.1225, 1e-12);
    foreach (['baseline_brier', 'challenger_brier'] as $key) {
        expect($mixed[$key])->toBeNumeric()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1);
    }
    expect($mixed['baseline_brier'])->toEqualWithDelta($winOnly['baseline_brier'], 1e-12)
        ->and($mixed['challenger_brier'])->toEqualWithDelta($winOnly['challenger_brier'], 1e-12);
    Http::assertNothingSent();
});
