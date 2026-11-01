<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\MLB\Predictions\MlbCalculationReleaseDefinition;
use App\Services\MLB\Predictions\MlbCalculator;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-10-31 12:00:00');
    config(['prediction_lifecycle.canonical_pipeline.mlb' => false, 'prediction_lifecycle.canonical_reads.mlb' => false]);
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function mlbTuningFixture(string $variant = 'valid'): array
{
    $start = now()->subDays(2)->toImmutable();
    $captured = $start->subHours(3);
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'season' => 2026, 'starts_at' => $start]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'status' => 'STATUS_FINAL',
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_date' => $start->toDateString(),
        'game_time' => $start->toTimeString(), 'home_score' => 5, 'away_score' => 3]);
    Prediction::query()->create(['game_id' => $game->id, 'season' => 2026, 'feature_version' => 'core-v3', 'home_combined_elo' => 1500,
        'away_combined_elo' => 1400, 'predicted_spread' => -7, 'predicted_total' => 20, 'actual_spread' => 2, 'actual_total' => 8, 'graded_at' => now()]);
    $inputs = ['event' => ['starts_at' => $start->toIso8601String(), 'neutral_site' => $variant === 'neutral'],
        'home' => ['elo' => 1500, 'metrics' => ['runs_per_game' => 5, 'runs_allowed_per_game' => 4, 'wins' => 20]],
        'away' => ['elo' => 1500, 'metrics' => ['runs_per_game' => 4, 'runs_allowed_per_game' => 4, 'wins' => 20]],
        'pitching' => ['home' => ['elo' => 1500], 'away' => ['elo' => 1500]]];
    $configuration = app(MlbCalculationReleaseDefinition::class)->configuration();
    data_set($configuration, 'elo.home_field_advantage', 0);
    $release = CalculationRelease::query()->where('sport', 'mlb')->first() ?? CalculationRelease::factory()->approved()->create(['sport' => 'mlb', 'phase' => 'pregame',
        'calculator_name' => $variant === 'unsupported' ? 'other-calculator' : MlbCalculationReleaseDefinition::CALCULATOR_NAME,
        'semantic_version' => MlbCalculationReleaseDefinition::SEMANTIC_VERSION,
        'input_schema_version' => MlbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION, 'configuration' => $configuration,
        'configuration_hash' => $variant === 'bad_hash' ? str_repeat('a', 64) : app(CanonicalPayloadHasher::class)->hash($configuration)]);
    $output = app(MlbCalculator::class)->calculate(new EventInputSnapshotData(MlbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION, $inputs, $captured), CalculationReleaseData::fromModel($release));
    if ($variant === 'incomplete') {
        unset($inputs['pitching']);
    }
    $input = EventInputSnapshot::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id, 'phase' => 'pregame',
        'schema_version' => MlbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION, 'inputs' => $inputs,
        'captured_at' => $captured, 'cutoff_at' => $start, 'latest_source_available_at' => $captured,
        'pregame_safety_status' => $variant === 'unsafe' ? 'unknown' : 'verified']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id, 'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null, 'generated_at' => $start->subHours(2)]);
    foreach ($output->markets as $market) {
        if ($variant === 'missing_total' && $market->marketType === 'total') {
            continue;
        }
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $market->marketType, 'selection' => $market->selection,
            'projected_line' => $variant === 'drift' && $market->marketType === 'spread' ? -9 : $market->projectedLine,
            'probability' => $market->probability, 'confidence_score' => $market->confidenceScore]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => $variant === 'late' ? $start : $start->subHours(2)]);

    return [$game, $native, $release, $input];
}

function mlbTuningReport(array $args = []): array
{
    expect(Artisan::call('mlb:tune-sweep', ['--forecast-source' => 'canonical', '--json' => true, ...$args]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('replays native tuning with frozen release defaults and actual canonical formulas', function () {
    [, $native, $release] = mlbTuningFixture();
    $before = [CalculationRun::count(), CanonicalPrediction::count(), CalculationRelease::count(), Prediction::count()];
    $report = mlbTuningReport();
    expect($report['sample'])->toBe(1)->and($report['results'][0]['hfa'])->toBeNull()
        ->and($report['results'][0]['spread_mae'])->toBe(1.8)
        ->and($report['results'][0]['total_mae'])->toEqualWithDelta(0.6, 0.000001)
        ->and($report['results'][0]['win_pct'])->toBe(1)
        ->and($report['canonical_evidence'][0]['calculation_release_id'])->toBe($release->id);
    config(['mlb.prediction.home_field_advantage' => 999, 'mlb.prediction.total_model.base_runs' => 999,
        'mlb.prediction.canonical.metric_weight' => 0.8, 'mlb.prediction.spread_to_probability_coefficient' => 999]);
    expect(mlbTuningReport())->toBe($report);
    $sweep = mlbTuningReport(['--hfa' => '0,100', '--average-total' => '10', '--coef' => '2']);
    expect($sweep['results'])->toHaveCount(2)
        ->and($sweep['results'][1]['spread_mae'])->toEqualWithDelta(0.4, 0.000001)
        ->and($sweep['results'][1]['total_mae'])->toEqualWithDelta(0.8, 0.000001)
        ->and($sweep['results'][1]['brier'])->toEqualWithDelta((1 - round(1 / (1 + exp(-1.6 / 2)), 6)) ** 2, 0.000001)
        ->and([CalculationRun::count(), CanonicalPrediction::count(), CalculationRelease::count(), Prediction::count()])->toBe($before)
        ->and(BetDecision::count())->toBe(0)->and($release->fresh()->status)->toBe('approved');
});

it('respects neutral sites when replaying HFA changes', function () {
    mlbTuningFixture('neutral');
    $results = mlbTuningReport(['--hfa' => '0,100'])['results'];
    expect($results[0]['spread_mae'])->toBe($results[1]['spread_mae']);
});

it('excludes nonreplayable native evidence without historical fallback', function (string $variant, ?string $reason) {
    mlbTuningFixture($variant);
    $report = mlbTuningReport();
    expect($report['sample'])->toBe(0)->and($report['canonical_evidence'])->toBeEmpty();
    if ($reason !== null) {
        expect($report['excluded'][$reason])->toBe(1);
    }
})->with([
    ['unsupported', 'unsupported_calculator_release'], ['bad_hash', 'configuration_hash_mismatch'],
    ['incomplete', 'incomplete_replay_inputs'], ['drift', 'baseline_replay_mismatch'],
    ['unsafe', null], ['late', null], ['missing_total', null],
]);

it('rejects legacy-only or invalid canonical tuning parameters', function (array $args) {
    $this->artisan('mlb:tune-sweep', ['--forecast-source' => 'canonical', ...$args])->assertFailed();
})->with([
    [['--base-runs' => '9']], [['--baseline-hfa' => '35']], [['--baseline-base-runs' => '9.7']],
    [['--coef' => '0']], [['--coef' => 'NaN']], [['--average-total' => '-1']], [['--hfa' => 'abc']],
]);

it('preserves legacy sweep behavior and chooses canonical under either switch', function () {
    mlbTuningFixture();
    Artisan::call('mlb:tune-sweep');
    $legacy = Artisan::output();
    Artisan::call('mlb:tune-sweep', ['--forecast-source' => 'legacy']);
    expect(Artisan::output())->toBe($legacy);
    foreach (['canonical_pipeline', 'canonical_reads'] as $switch) {
        config(['prediction_lifecycle.'.$switch.'.mlb' => true]);
        Artisan::call('mlb:tune-sweep', ['--json' => true]);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['forecast_source'])->toBe('canonical');
        config(['prediction_lifecycle.'.$switch.'.mlb' => false]);
    }
    $this->artisan('mlb:tune-sweep', ['--forecast-source' => 'invalid'])->assertFailed();
    $this->artisan('mlb:tune-sweep', ['--forecast-source' => 'canonical', '--feature-version' => 'core-v3'])
        ->expectsOutputToContain('No replayable canonical forecasts')->assertSuccessful();
});

it('batches native replay inputs with eligible limits and no legacy reads', function () {
    for ($i = 0; $i < 26; $i++) {
        mlbTuningFixture();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(mlbTuningReport()['sample'])->toBe(26);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "event_input_snapshots"')))->toHaveCount(2)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'mlb_predictions')))->toBeEmpty()
        ->and(mlbTuningReport(['--limit' => 1])['sample'])->toBe(1);
});

it('retains historical linear deltas and exports native research without writes', function () {
    mlbTuningFixture();
    $path = sys_get_temp_dir().'/mlb-tuning-'.Str::uuid().'.json';
    try {
        $this->artisan('mlb:tune-sweep', ['--forecast-source' => 'legacy', '--hfa' => '35,79', '--base-runs' => '10.7',
            '--baseline-hfa' => 35, '--baseline-base-runs' => 9.7, '--coef' => '2', '--output' => $path])->assertSuccessful();
        $legacy = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($legacy['sample'])->toBe(1)->and($legacy['results'][0]['spread_mae'])->toBe(9)
            ->and($legacy['results'][1]['spread_mae'])->toBe(8)->and($legacy['results'][0]['total_mae'])->toBe(13);
        $this->artisan('mlb:tune-sweep', ['--forecast-source' => 'canonical', '--output' => $path])
            ->expectsOutputToContain('grants no calibration')->assertSuccessful();
        $native = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($native)->toBe(mlbTuningReport())->and(BetDecision::count())->toBe(0);
    } finally {
        @unlink($path);
    }
});

it('reports missing native research metrics as unavailable', function () {
    $report = mlbTuningReport();
    expect($report['sample'])->toBe(0)->and($report['results'][0]['n'])->toBe(0)
        ->and($report['results'][0]['win_pct'])->toBeNull()->and($report['results'][0]['brier'])->toBeNull();
});
