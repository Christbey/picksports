<?php

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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-31 12:00:00');
    config(['prediction_lifecycle.canonical_pipeline.mlb' => false, 'prediction_lifecycle.canonical_reads.mlb' => false]);
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function mlbReadinessFixture(string $variant = 'valid', int $season = 2026, int $daysAgo = 2): array
{
    $start = now()->subDays($daysAgo);
    $generated = $start->copy()->subHours(2);
    $captured = $generated->copy()->subHour();
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'season' => $season, 'starts_at' => $start]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => $season, 'status' => 'STATUS_FINAL',
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(), 'home_score' => 5, 'away_score' => 3,
        'odds_data' => ['bookmakers' => [['markets' => [['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 999]]]]]]]]);
    $legacy = Prediction::query()->create(['game_id' => $game->id, 'season' => $season, 'season_type' => '2', 'feature_version' => 'core-v3', 'predicted_spread' => -7,
        'predicted_total' => 20, 'win_probability' => 0.1, 'confidence_score' => 90, 'winner_correct' => false, 'graded_at' => now()]);
    $input = EventInputSnapshot::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id, 'phase' => 'pregame',
        'schema_version' => $variant === 'wrong_schema' ? 'other' : MlbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
        'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified', 'captured_at' => $captured, 'cutoff_at' => $start,
        'latest_source_available_at' => $variant === 'future_source' ? $generated->copy()->addMinute() : $captured,
        'inputs' => ['event' => ['starts_at' => $variant === 'missing_start' ? null : ($variant === 'kickoff_mismatch' ? $start->copy()->addHour() : $start)->toIso8601String()],
            'pregame_market' => ['captured_at' => $variant === 'missing_quote_time' ? null : $captured->toIso8601String(), 'available' => $variant !== 'missing_market', 'consensus' => ['spread' => ['line' => -1.5], 'total' => ['line' => 9.0],
                'moneyline' => ['market_key' => 'h2h', 'side' => 'home', 'price' => -120, 'opposite_quote' => ['market_key' => 'h2h', 'side' => 'away', 'price' => 110]]]]]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'mlb', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id, 'calculation_release_id' => $release->id, 'status' => 'succeeded', 'diagnostics' => $variant === 'recorded_adjustments' ? ['park_total_adjustment' => 0.3, 'weather_total_adjustment' => -0.4] : []]);
    $native = CanonicalPrediction::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null, 'generated_at' => $generated]);
    foreach ([['spread', 'home', $variant === 'missing_spread' ? null : -2.0, null], ['total', 'combined', $variant === 'missing_total' ? null : 9.5, null],
        ['moneyline', 'home', null, $variant === 'missing_probability' ? null : 0.65]] as [$market, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $market, 'selection' => $side, 'projected_line' => $line, 'probability' => $probability,
            'confidence_score' => $variant === 'missing_confidence' ? null : 65]);
    }
    if ($variant !== 'draft') {
        $native->update(['publication_state' => 'published', 'published_at' => $variant === 'late_publication' ? $start : $generated]);
    }
    if ($variant === 'withdrawn') {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    return [$game, $native, $legacy, $input];
}

function mlbNativeReadinessReport(array $args = []): array
{
    expect(Artisan::call('mlb:validate-recommendation-readiness', ['--forecast-source' => 'canonical', '--season' => 2026, '--json' => true, ...$args]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('reports MLB readiness from frozen native inputs while preserving approval holds', function (bool $pipeline, bool $reads) {
    [, $native,,$input] = mlbReadinessFixture();
    config(['prediction_lifecycle.canonical_pipeline.mlb' => $pipeline, 'prediction_lifecycle.canonical_reads.mlb' => $reads]);
    $statuses = CalculationRelease::pluck('status', 'id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        Artisan::call('mlb:validate-recommendation-readiness', ['--season' => 2026, '--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($report['forecast_source'])->toBe('canonical')->and($report['summary'])->toMatchArray(['rows' => 1, 'model_winner_accuracy' => 100, 'total_bias_vs_market' => 0.5, 'public_promoted_rows' => 0])
        ->and($report['ready'])->toBeFalse()->and($report['forecast_lineage'][0]['canonical_prediction_id'])->toBe($native->id)
        ->and($report['forecast_lineage'][0]['event_input_snapshot_id'])->toBe($input->id)
        ->and($queries->implode(' '))->not->toContain('mlb_predictions', 'prediction_feature_snapshots', 'odds_data')
        ->and(BetDecision::count())->toBe(0)->and(CalculationRelease::pluck('status', 'id')->all())->toBe($statuses);
})->with([[true, false], [false, true], [true, true]]);

it('excludes incomplete or unsafe native MLB readiness evidence without fallback', function (string $variant) {
    mlbReadinessFixture($variant);
    expect(mlbNativeReadinessReport()['summary']['rows'])->toBe(0);
})->with(['unsafe', 'future_source', 'missing_start', 'kickoff_mismatch', 'draft', 'withdrawn', 'late_publication', 'wrong_schema', 'missing_spread', 'missing_total', 'missing_probability', 'missing_confidence']);

it('does not replace missing frozen MLB markets with mutable odds', function () {
    mlbReadinessFixture('missing_market');
    $report = mlbNativeReadinessReport();
    expect($report['summary'])->toMatchArray(['rows' => 1, 'market_baseline_accuracy' => null, 'total_bias_vs_market' => null])->and($report['ready'])->toBeFalse();
});

it('retains historical MLB readiness reports after cutover', function () {
    mlbReadinessFixture();
    Artisan::call('mlb:validate-recommendation-readiness', ['--season' => 2026, '--json' => true]);
    $old = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true]);
    Artisan::call('mlb:validate-recommendation-readiness', ['--forecast-source' => 'legacy', '--season' => 2026, '--json' => true]);
    $legacy = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    unset($old['generated_at'], $legacy['generated_at']);
    expect($legacy)->toBe($old)->and($legacy['feature_version'])->toBe('core-v3');
});

it('scopes native MLB readiness by dates schema season and post-eligibility limit', function () {
    [, $valid] = mlbReadinessFixture(daysAgo: 4);
    mlbReadinessFixture('missing_confidence', daysAgo: 2);
    mlbReadinessFixture(season: 2025, daysAgo: 1);
    expect(mlbNativeReadinessReport(['--limit' => 1])['forecast_lineage'][0]['canonical_prediction_id'])->toBe($valid->id);
    expect(mlbNativeReadinessReport(['--from' => now()->subDays(3)->toDateString()])['summary']['rows'])->toBe(0);
    expect(mlbNativeReadinessReport(['--feature-version' => 'core-v3'])['summary']['rows'])->toBe(0);
    $this->artisan('mlb:validate-recommendation-readiness', ['--forecast-source' => 'invalid'])->assertFailed();
});

it('batches native readiness hydration and stops after eligible limit without granting approval', function () {
    foreach (range(1, 26) as $i) {
        mlbReadinessFixture();
    }
    foreach ([1 => 1, 100 => 2] as $limit => $batches) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $report = mlbNativeReadinessReport(['--limit' => $limit, '--strict-pregame' => true]);
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        expect($report['summary']['rows'])->toBe($limit === 1 ? 1 : 26)->and($report['ready'])->toBeFalse()
            ->and($report['summary']['public_promoted_rows'])->toBe(0)
            ->and($queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "event_input_snapshots"')))->toHaveCount($batches);
    }
    expect(BetDecision::count())->toBe(0);
});

function mlbNativeCalibrationReport(array $args = []): array
{
    expect(Artisan::call('mlb:report-calibration', ['--forecast-source' => 'canonical', '--season' => 2026, '--json' => true, ...$args]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('reports native MLB calibration from frozen evidence without mutable fallbacks', function (bool $pipeline, bool $reads) {
    [, $native,,$input] = mlbReadinessFixture();
    config(['prediction_lifecycle.canonical_pipeline.mlb' => $pipeline, 'prediction_lifecycle.canonical_reads.mlb' => $reads]);
    $statuses = CalculationRelease::pluck('status', 'id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        Artisan::call('mlb:report-calibration', ['--season' => 2026, '--json' => true, '--diagnostics' => true, '--compare-market' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($report['forecast_source'])->toBe('canonical')->and($report['summary'])->toMatchArray(['count' => 1, 'winner_accuracy' => 100, 'spread_mae' => 0, 'total_mae' => 1.5, 'market_spread_mae' => 0.5, 'market_total_mae' => 1])
        ->and($report['forecast_lineage'][0]['canonical_prediction_id'])->toBe($native->id)
        ->and($report['forecast_lineage'][0]['event_input_snapshot_id'])->toBe($input->id)
        ->and($queries->implode(' '))->not->toContain('mlb_predictions', 'prediction_feature_snapshots', 'odds_data')
        ->and(CalculationRelease::pluck('status', 'id')->all())->toBe($statuses)->and(BetDecision::count())->toBe(0);
    expect(json_encode($report['diagnostics']['park_weather_breakdowns']))->toContain('park_unknown', 'weather_unknown', 'combined_unknown');
})->with([[true, false], [false, true], [true, true]]);

it('excludes unsafe or incomplete MLB calibration evidence without legacy fallback', function (string $variant) {
    mlbReadinessFixture($variant);
    $this->artisan('mlb:report-calibration', ['--forecast-source' => 'canonical', '--season' => 2026])->expectsOutputToContain('No graded MLB predictions found')->assertSuccessful();
})->with(['unsafe', 'future_source', 'missing_start', 'kickoff_mismatch', 'draft', 'withdrawn', 'late_publication', 'wrong_schema', 'missing_spread', 'missing_total', 'missing_probability', 'missing_confidence']);

it('keeps MLB calibration missing markets unavailable and strict warnings intact', function () {
    mlbReadinessFixture('missing_market');
    $report = mlbNativeCalibrationReport(['--diagnostics' => true]);
    expect($report['summary'])->toMatchArray(['count' => 1, 'market_spread_sample' => 0, 'market_total_sample' => 0, 'market_spread_mae' => null, 'market_total_mae' => null]);
    $this->artisan('mlb:report-calibration', ['--forecast-source' => 'canonical', '--season' => 2026, '--strict-pregame' => true])
        ->expectsOutputToContain('No strict-pregame eligible MLB predictions')->expectsOutputToContain('missing_pregame_safe_market_context')->assertSuccessful();
    mlbReadinessFixture(daysAgo: 1);
    expect(mlbNativeCalibrationReport(['--strict-pregame' => true])['strict_pregame']['excluded_count'])->toBe(1);
    expect(mlbNativeCalibrationReport()['strict_pregame']['excluded_count'])->toBe(0);
});

it('preserves historical MLB calibration output and scopes native input schemas', function () {
    mlbReadinessFixture();
    Artisan::call('mlb:report-calibration', ['--season' => 2026, '--json' => true, '--diagnostics' => true]);
    $before = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true]);
    Artisan::call('mlb:report-calibration', ['--forecast-source' => 'legacy', '--season' => 2026, '--json' => true, '--diagnostics' => true]);
    $after = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    unset($before['generated_at'], $after['generated_at']);
    expect($after)->toBe($before);
    $this->artisan('mlb:report-calibration', ['--forecast-source' => 'canonical', '--feature-version' => 'core-v3'])->expectsOutputToContain('No graded MLB predictions')->assertSuccessful();
    $this->artisan('mlb:report-calibration', ['--forecast-source' => 'invalid'])->assertFailed();
});

it('batches native MLB calibration reads and keeps strict quote-time exclusions', function () {
    mlbReadinessFixture('missing_quote_time');
    $this->artisan('mlb:report-calibration', ['--forecast-source' => 'canonical', '--strict-pregame' => true])->expectsOutputToContain('missing_odds_timestamp')->assertSuccessful();
    for ($i = 0; $i < 25; $i++) {
        mlbReadinessFixture();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(mlbNativeCalibrationReport(['--strict-pregame' => true])['summary']['count'])->toBe(25);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "event_input_snapshots"')))->toHaveCount(2);
});

it('uses recorded native adjustment diagnostics and renders calibration without granting approval', function () {
    mlbReadinessFixture('recorded_adjustments');
    $report = mlbNativeCalibrationReport(['--diagnostics' => true]);
    expect($report['diagnostics']['park_weather_breakdowns']['park'][0]['avg_park_adjustment'])->toBe(0.3)
        ->and($report['diagnostics']['park_weather_breakdowns']['weather'][0]['avg_weather_adjustment'])->toBe(-0.4);
    $this->artisan('mlb:report-calibration', ['--forecast-source' => 'canonical', '--season' => 2026, '--diagnostics' => true])
        ->expectsOutputToContain('Forecast source: canonical')->expectsOutputToContain('grants no calibration')->assertSuccessful();
    expect(BetDecision::count())->toBe(0);
});
