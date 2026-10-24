<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\Simulation\GameSimulationService;
use App\Services\NFL\Simulation\ScoreDistributionSimulator;
use App\Services\NFL\Simulation\SimulationHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses()->group('nfl', 'simulation');

function simulationFixture(string $date, string $status = 'STATUS_SCHEDULED', string $model = 'fixture-model'): array
{
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'season_type' => 2, 'status' => $status, 'game_date' => $date, 'game_time' => '17:00:00',
        'home_score' => $status === 'STATUS_FINAL' ? 30 : null, 'away_score' => $status === 'STATUS_FINAL' ? 24 : null]);
    if ($status === 'STATUS_FINAL') {
        $game->update(['result_updated_at' => Carbon::parse($date.' 21:00:00', 'UTC')->setTimezone(config('app.timezone'))]);
    }
    $start = Carbon::parse($date.' 17:00:00', 'UTC')->setTimezone(config('app.timezone'));
    $available = $start->copy()->subHours(2)->min(now()->subHour());
    $source = PredictionFeatureSnapshot::create(['sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => $game->id,
        'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(), 'model_version' => $model, 'feature_version' => 'fixture', 'blend_version' => 'fixture',
        'features' => [], 'outputs' => ['predicted_spread' => 6.0, 'predicted_total' => 54.0],
        'model_metadata' => ['market_blend' => ['applied' => true], 'pre_market_outputs' => ['predicted_spread' => 4.0, 'predicted_total' => 52.0]],
        'feature_hash' => hash('sha256', 'fixture'), 'generated_at' => $available, 'game_start_at' => $start, 'features_available_at' => $available,
        'pregame_safe' => true, 'availability_status' => 'observed_pregame']);

    return [$game->fresh(), $source];
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
    config(['nfl_game_simulation.minimum_history' => 2, 'nfl_game_simulation.capture_enabled' => true]);
});

it('is reproducible and preserves global randomness while retaining joint residual dependence', function () {
    $engine = app(ScoreDistributionSimulator::class);
    mt_srand(1234);
    $expected = mt_rand();
    mt_srand(1234);
    $a = $engine->run(0, 50, [[-10, -10], [10, 10]], [], 2000, 42);
    expect(mt_rand())->toBe($expected)->and($a)->toBe($engine->run(0, 50, [[-10, -10], [10, 10]], [], 2000, 42))
        ->and($a['outright']['tie'])->toBe(1.0)->and($a['distributions']['margin']['p5'])->toBe(0)
        ->and($a['distributions']['total']['p5'])->toBe(30)->and($a['distributions']['total']['p95'])->toBe(70)
        ->and($a['calibrated'])->toBeFalse()->and($a['bet_eligible'])->toBeFalse();
});

it('settles both sides of spreads and totals with explicit pushes and correct handicap signs', function () {
    $result = app(ScoreDistributionSimulator::class)->run(6, 54, [[0, 0], [0, 0]], [
        'spreads' => [['side' => 'home', 'line' => -6], ['side' => 'away', 'line' => 6], ['side' => 'home', 'line' => -6.5]],
        'totals' => [['side' => 'over', 'line' => 54], ['side' => 'under', 'line' => 53.5]],
    ], 1000, 42);
    expect($result['markets'][0]['frequencies']['push'])->toBe(1.0)
        ->and($result['markets'][1]['frequencies']['push'])->toBe(1.0)
        ->and($result['markets'][2]['frequencies']['loss'])->toBe(1.0)
        ->and($result['markets'][3]['frequencies']['push'])->toBe(1.0)
        ->and($result['markets'][4]['frequencies']['loss'])->toBe(1.0);
});

it('requires explicit provisional assumptions and rejects invalid means and quarter-point lines', function () {
    $engine = app(ScoreDistributionSimulator::class);
    expect(fn () => $engine->run(6, 54, [], [], 1000, 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => $engine->run(INF, 54, [[0, 0]], [], 1000, 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => $engine->run(60, 54, [[0, 0]], [], 1000, 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => $engine->run(6, 54, [[0, 0]], ['spreads' => [['side' => 'home', 'line' => -6.25]]], 1000, 1))->toThrow(InvalidArgumentException::class);
    $output = $engine->run(0, 20, [], [], 1000, 1, [10, 10, 0.15]);
    expect($output['method'])->toBe('provisional_correlated_normal')->and(array_sum($output['outright']))->toEqualWithDelta(1, 1e-12)
        ->and($output['distributions']['home_score']['p5'])->toBeGreaterThanOrEqual(0);
});

it('excludes future results late snapshots other model versions and duplicate game observations from fitting', function () {
    [$target, $source] = simulationFixture('2026-10-25');
    [$prior, $priorSource] = simulationFixture('2026-10-18', 'STATUS_FINAL');
    $copy = $priorSource->replicate();
    $copy->snapshot_run_id = (string) Str::uuid();
    $copy->save();
    [$later, $lateSource] = simulationFixture('2026-10-17', 'STATUS_FINAL');
    $lateSource->update(['generated_at' => $lateSource->game_start_at->copy()->addHour()]);
    simulationFixture('2026-10-19', 'STATUS_FINAL', 'other-model');
    simulationFixture('2026-10-24', 'STATUS_FINAL');
    [$updated] = simulationFixture('2026-10-16', 'STATUS_FINAL');
    $updated->update(['result_updated_at' => now()]);
    $fit = app(SimulationHistory::class)->fit($target, $source, 'published', now());
    expect($fit['game_ids'])->toBe([$prior->id])->and($fit['snapshot_ids'])->toBe([$copy->id])->and($fit['pairs'])->toBe([[0.0, 0.0]]);
    $historicalCutoff = Carbon::parse('2026-10-18 12:00:00', 'UTC');
    expect(app(SimulationHistory::class)->fit($target, $source, 'published', $historicalCutoff)['pairs'])->toBe([]);
});

it('holds insufficient history and only permits a non-persisting provisional preview', function () {
    [$game] = simulationFixture('2026-10-25');
    $service = app(GameSimulationService::class);
    expect($service->preview($game)['reason'])->toBe('insufficient_same_model_history');
    $preview = $service->preview($game, provisional: true);
    expect($preview['fitted'])->toBeFalse()->and($preview['outputs']['method'])->toBe('provisional_correlated_normal');
    expect(fn () => $service->capture($game, $preview))->toThrow(RuntimeException::class);
    $this->artisan('nfl:simulate-game', ['game' => $game->id, '--record' => true, '--provisional' => true])->assertFailed();
});

it('captures once replays exactly grades immutably and preserves the original forecast', function () {
    [$game, $source] = simulationFixture('2026-10-25');
    simulationFixture('2026-10-18', 'STATUS_FINAL');
    simulationFixture('2026-10-11', 'STATUS_FINAL');
    $game->update(['odds_updated_at' => now(), 'odds_data' => ['home_team' => 'Home', 'away_team' => 'Away', 'bookmakers' => [[
        'key' => 'fixture', 'markets' => [
            ['key' => 'spreads', 'last_update' => now()->toIso8601String(), 'outcomes' => [['name' => 'Home', 'point' => -6, 'price' => -110], ['name' => 'Away', 'point' => 6, 'price' => -110]]],
            ['key' => 'totals', 'last_update' => now()->toIso8601String(), 'outcomes' => [['name' => 'Over', 'point' => 54, 'price' => -110], ['name' => 'Under', 'point' => 54, 'price' => -110]]],
        ],
    ]]]]);
    $service = app(GameSimulationService::class);
    $run = $service->preview($game, iterations: 1000);
    $original = $source->fresh()->toArray();
    $snapshot = $service->capture($game, $run);
    expect($snapshot->prediction_table)->toBe('prediction_feature_snapshots')->and($snapshot->prediction_id)->toBe($source->id)
        ->and(PredictionFeatureSnapshot::where('prediction_table', 'nfl_predictions')->where('game_id', $game->id)->latest('id')->first()->id)->toBe($source->id);
    expect($service->capture($game, $run)->id)->toBe($snapshot->id)
        ->and($snapshot->fresh()->outputs['bet_eligible'])->toBeFalse()
        ->and($source->fresh()->toArray())->toBe($original);
    expect(fn () => $snapshot->update(['outputs' => []]))->toThrow(LogicException::class);
    expect(fn () => $snapshot->delete())->toThrow(LogicException::class);
    $this->artisan('nfl:simulate-game', ['--replay' => $snapshot->id])->assertSuccessful();
    $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 30, 'away_score' => 24]);
    expect($service->grade($snapshot->fresh()))->toBeNull();
    $game->update(['result_updated_at' => now()->subHours(25)]);
    $homeId = $game->home_team_id;
    $game->update(['home_team_id' => $game->away_team_id]);
    expect($service->grade($snapshot->fresh()))->toBeNull();
    $game->update(['home_team_id' => $homeId]);
    $evaluation = $service->grade($snapshot->fresh());
    expect($evaluation)->toBeInstanceOf(PredictionEvaluation::class)->and($evaluation->errors['margin_absolute_error'])->toEqual(0)
        ->and($service->grade($snapshot->fresh())->id)->toBe($evaluation->id);
    expect(fn () => $evaluation->update(['actuals' => []]))->toThrow(LogicException::class);
    expect(fn () => $evaluation->delete())->toThrow(LogicException::class);
    expect(Artisan::call('nfl:simulate-game', ['--report' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true);
    expect($report['groups']['nfl-score-distribution-v1|published|fixture-model']['graded'])->toBe(1)
        ->and($report['groups']['nfl-score-distribution-v1|published|fixture-model']['market_metrics']['spreads']['actual_push_frequency'])->toEqual(1)
        ->and($report['groups']['nfl-score-distribution-v1|published|fixture-model']['market_metrics']['totals']['brier'])->toEqual(0);
});

it('rejects capture after kickoff or disabled capture and labels the pre-market basis honestly', function () {
    [$game] = simulationFixture('2026-10-25');
    simulationFixture('2026-10-18', 'STATUS_FINAL');
    simulationFixture('2026-10-11', 'STATUS_FINAL');
    $service = app(GameSimulationService::class);
    $run = $service->preview($game, 'pre_market', 1000);
    expect($run['market_directly_blended'])->toBeFalse()->and($run['features']['means']['margin'])->toBe(4.0);
    config(['nfl_game_simulation.capture_enabled' => false]);
    expect(fn () => $service->capture($game, $run))->toThrow(RuntimeException::class);
    config(['nfl_game_simulation.capture_enabled' => true]);
    $this->travelTo(Carbon::parse('2026-10-25 18:00:00', 'UTC'));
    expect(fn () => $service->capture($game, $run))->toThrow(RuntimeException::class);
});

it('refuses stale sources and late features while preserving source-basis distinctions', function () {
    [$game, $source] = simulationFixture('2026-10-25');
    $service = app(GameSimulationService::class);
    $source->update(['generated_at' => now()->subHours(37), 'features_available_at' => now()->subHours(37)]);
    expect($service->preview($game)['reason'])->toBe('observed_pregame_forecast_missing_or_stale');
    $source->update(['generated_at' => now()->subHour(), 'features_available_at' => now()]);
    expect($service->preview($game)['reason'])->toBe('observed_pregame_forecast_missing_or_stale');
    $source->update(['features_available_at' => $source->generated_at, 'model_metadata' => []]);
    expect($service->preview($game, 'pre_market')['reason'])->toBe('forecast_basis_unavailable');
});

it('reuses an identical later preview but rejects changed game state and altered payloads', function () {
    [$game] = simulationFixture('2026-10-25');
    simulationFixture('2026-10-18', 'STATUS_FINAL');
    simulationFixture('2026-10-11', 'STATUS_FINAL');
    $service = app(GameSimulationService::class);
    $run = $service->preview($game, iterations: 1000);
    $snapshot = $service->capture($game, $run);
    $this->travel(5)->minutes();
    $later = $service->preview($game, iterations: 1000);
    expect($later['hash'])->toBe($run['hash'])->and($service->capture($game, $later)->id)->toBe($snapshot->id);
    $tampered = $run;
    $tampered['outputs']['iterations'] = 5;
    expect(fn () => $service->capture($game, $tampered))->toThrow(RuntimeException::class, 'integrity');
    $game->update(['status' => 'STATUS_DELAYED']);
    expect(fn () => $service->capture($game, $run))->toThrow(RuntimeException::class, 'Game changed');
});

it('keeps insufficient-data previews read-only and rejects invalid command arguments', function () {
    [$game] = simulationFixture('2026-10-25');
    $count = PredictionFeatureSnapshot::count();
    $this->artisan('nfl:simulate-game', ['game' => $game->id])->assertFailed();
    $this->artisan('nfl:simulate-game', ['game' => $game->id, '--provisional' => true, '--iterations' => 1000])->assertSuccessful();
    $this->artisan('nfl:simulate-game', ['game' => $game->id, '--seed' => 'invalid'])->assertFailed();
    $this->artisan('nfl:simulate-game', ['game' => $game->id, '--grade' => true])->assertFailed();
    expect(PredictionFeatureSnapshot::count())->toBe($count);
});

it('grades beyond already settled observations with a bounded command batch', function () {
    $service = app(GameSimulationService::class);
    simulationFixture('2026-10-18', 'STATUS_FINAL');
    simulationFixture('2026-10-11', 'STATUS_FINAL');
    $snapshots = [];
    $games = [];
    foreach (range(1, 2) as $i) {
        [$game] = simulationFixture('2026-10-25');
        $games[] = $game;
        $snapshots[] = $service->capture($game, $service->preview($game, iterations: 1000));
    }
    $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
    foreach ($games as $game) {
        $game->update(['status' => 'STATUS_FINAL', 'home_score' => 30, 'away_score' => 24]);
        $game->update(['result_updated_at' => now()->subHours(25)]);
    }
    $this->artisan('nfl:simulate-game', ['--grade' => true, '--limit' => 1])->assertSuccessful();
    $this->artisan('nfl:simulate-game', ['--grade' => true, '--limit' => 1])->assertSuccessful();
    expect(PredictionEvaluation::where('blend_version', GameSimulationService::POLICY)->count())->toBe(2);
});

it('holds a genuinely different listed quarterback without treating an ordinary projected identity as a conflict', function () {
    [$game, $source] = simulationFixture('2026-10-25');
    $source->update(['model_metadata' => ['qb_form' => ['home' => ['qb_id' => 10, 'identity_status' => 'projected_not_confirmed_starter']]]]);
    $game->update(['home_qb_id' => 10]);
    expect(app(GameSimulationService::class)->preview($game, provisional: true)['status'])->toBe('shadow');
    $game->update(['home_qb_id' => 20]);
    expect(app(GameSimulationService::class)->preview($game, provisional: true)['reason'])->toBe('quarterback_identity_changed');
});
