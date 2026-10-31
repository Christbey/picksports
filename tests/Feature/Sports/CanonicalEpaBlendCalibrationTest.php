<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Services\Predictions\EpaBlendCalibrationSource;
use App\Support\NflModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-31 12:00:00');
    Http::preventStrayRequests();
    foreach (EpaBlendCalibrationSource::SPORTS as $sport) {
        config(["prediction_lifecycle.canonical_pipeline.{$sport}" => false, "prediction_lifecycle.canonical_reads.{$sport}" => false]);
    }
});
afterEach(fn () => $this->travelBack());

function epaReportForecast(string $sport, string $variant = 'valid', mixed $application = true, int $season = 2026): array
{
    $teamClass = 'App\\Models\\'.strtoupper($sport).'\\Team';
    $gameClass = 'App\\Models\\'.strtoupper($sport).'\\Game';
    $legacyClass = 'App\\Models\\'.strtoupper($sport).'\\Prediction';
    $home = $teamClass::where('abbreviation', 'HOM')->first() ?? $teamClass::factory()->create(['abbreviation' => 'HOM']);
    $away = $teamClass::where('abbreviation', 'AWY')->first() ?? $teamClass::factory()->create(['abbreviation' => 'AWY']);
    $start = now()->subDay();
    $generated = $start->copy()->subHours(2);
    $captured = $generated->copy()->subHour();
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => $season, 'starts_at' => $start]);
    $game = $gameClass::factory()->create(['sport_event_id' => $event->id, 'season' => $season, 'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'home_score' => 27, 'away_score' => 20]);
    $legacy = $legacyClass::factory()->create(['game_id' => $game->id, 'predicted_spread' => 99, 'actual_spread' => 7,
        'predicted_total' => 99, 'actual_total' => 47, 'winner_correct' => false, 'graded_at' => now(),
        'model_metadata' => ['true_epa' => ['applied' => false]]]);
    $metadata = $application === null ? [] : ['true_epa' => ['applied' => $application]];
    if ($variant === 'precedence') {
        $metadata = ['true_epa' => ['true_epa_applied' => false, 'applied' => true]];
    }
    $inputs = ['event' => ['starts_at' => match ($variant) {
        'missing_start' => null, 'kickoff_mismatch' => $start->copy()->addHour()->toIso8601String(), default => $start->toIso8601String()
    }],
        'recommendation_context' => ['version' => NflRecommendationInputBuilder::VERSION,
            'model_version' => $variant === 'wrong_version' ? 'unrelated-version' : NflModelVersion::current(),
            'outputs' => ['predicted_spread' => $variant === 'missing_spread' ? null : 4.0, 'predicted_total' => $variant === 'missing_total' ? null : 44.0],
            'model_metadata' => $metadata]];
    $input = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id, 'phase' => 'pregame',
        'captured_at' => $captured, 'cutoff_at' => $start, 'latest_source_available_at' => $variant === 'future_source' ? $start->copy()->addHour() : $captured,
        'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified', 'inputs' => $inputs]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $prediction = CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null, 'generated_at' => $generated,
        'output_metadata' => $sport === 'nfl' ? ['true_epa' => ['applied' => false]] : $metadata]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'spread', 'selection' => 'home',
        'projected_line' => $variant === 'missing_spread' ? null : ($sport === 'nfl' ? -12 : -4)]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'total', 'selection' => 'combined',
        'projected_line' => $variant === 'missing_total' ? null : ($sport === 'nfl' ? 70 : 44)]);
    if ($variant !== 'draft') {
        $prediction->update(['publication_state' => 'published', 'published_at' => $variant === 'late_publication' ? $start : $generated]);
    }
    if ($variant === 'withdrawn') {
        $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    return [$game, $prediction, $legacy, $input];
}

it('grades the matching frozen source without legacy reads in every canonical switch mode', function (string $sport, bool $pipeline, bool $reads) {
    epaReportForecast($sport);
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => $pipeline, "prediction_lifecycle.canonical_reads.{$sport}" => $reads]);
    $before = CalculationRelease::pluck('status', 'id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('sports:report-epa-blend-calibration', ['sport' => $sport, '--season' => 2026]))->toBe(0);
        expect(Artisan::output())->toContain('Forecast source: canonical', 'Total graded predictions: 1', '3.000', '100.00');
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->implode(' '))->not->toContain($sport.'_predictions')->not->toContain('prediction_feature_snapshots')->not->toContain('odds_data')
        ->and($queries->filter(fn ($sql) => preg_match('/^(insert|update|delete)/i', $sql)))->toBeEmpty()
        ->and(CalculationRelease::pluck('status', 'id')->all())->toBe($before);
    $row = collect(app(EpaBlendCalibrationSource::class)->rows($sport, 'canonical', 2026))->sole();
    expect((array) $row)->toMatchArray(['predicted_spread' => 4.0, 'predicted_total' => 44.0, 'actual_spread' => 7.0, 'actual_total' => 47.0, 'winner_correct' => true, 'epa_applied' => true]);
})->with(['nfl', 'nba', 'cbb', 'wcbb'])->with([[true, false], [false, true], [true, true]]);

it('keeps unsafe or missing native evidence empty without borrowing legacy forecasts', function (string $sport, string $variant) {
    epaReportForecast($sport, $variant);
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $this->artisan('sports:report-epa-blend-calibration', ['sport' => $sport, '--season' => 2026])
        ->expectsOutputToContain('No graded predictions found')->assertSuccessful();
})->with(['nfl', 'nba', 'cbb', 'wcbb'])->with(['unsafe', 'draft', 'withdrawn', 'future_source', 'missing_start', 'kickoff_mismatch', 'late_publication', 'missing_spread']);

it('keeps missing totals unavailable while retaining eligible spread evidence', function (string $sport) {
    epaReportForecast($sport, 'missing_total');
    $row = collect(app(EpaBlendCalibrationSource::class)->rows($sport, 'canonical', 2026))->sole();
    expect($row->predicted_total)->toBeNull()->and($row->actual_total)->toBe(47.0);
    $this->artisan('sports:report-epa-blend-calibration', ['sport' => $sport, '--forecast-source' => 'canonical'])
        ->expectsOutputToContain('Total graded predictions: 1')->expectsOutputToContain('n/a')->assertSuccessful();
})->with(['nfl', 'nba', 'cbb', 'wcbb']);

it('separates unknown native application evidence instead of labeling it not applied', function (string $sport, mixed $flag) {
    epaReportForecast($sport, application: $flag);
    $row = collect(app(EpaBlendCalibrationSource::class)->rows($sport, 'canonical', 2026))->sole();
    expect($row->epa_applied)->toBeNull();
    $this->artisan('sports:report-epa-blend-calibration', ['sport' => $sport, '--forecast-source' => 'canonical'])
        ->expectsOutputToContain('true_epa_unknown')->expectsOutputToContain('Spread MAE delta: n/a')->assertSuccessful();
})->with(['nfl', 'nba', 'cbb', 'wcbb'])->with([null, 'false']);

it('retains historical grading and default historical selection before cutover', function (string $sport) {
    epaReportForecast($sport);
    expect(Artisan::call('sports:report-epa-blend-calibration', ['sport' => $sport, '--season' => 2026]))->toBe(0);
    expect(Artisan::output())->toContain('Forecast source: legacy', '92.000', '52.000')->not->toContain('true_epa_unknown');
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    expect(Artisan::call('sports:report-epa-blend-calibration', ['sport' => $sport, '--forecast-source' => 'legacy', '--season' => 2026]))->toBe(0);
    expect(Artisan::output())->toContain('92.000', '52.000');
})->with(['nfl', 'nba', 'cbb', 'wcbb']);

it('respects native season and NFL recommendation version filters', function () {
    epaReportForecast('nfl', season: 2025);
    epaReportForecast('nfl', 'wrong_version');
    expect(collect(app(EpaBlendCalibrationSource::class)->rows('nfl', 'canonical', 2026)))->toBeEmpty();
    expect(collect(app(EpaBlendCalibrationSource::class)->rows('nfl', 'canonical', 2026, 'unrelated-version')))->toHaveCount(1);
    foreach (['nba', 'cbb', 'wcbb'] as $sport) {
        epaReportForecast($sport, season: 2025);
        expect(collect(app(EpaBlendCalibrationSource::class)->rows($sport, 'canonical', 2026)))->toBeEmpty();
    }
});

it('batches native inputs without loading full snapshots or issuing per-game queries', function (string $sport) {
    foreach (range(1, 26) as $index) {
        epaReportForecast($sport);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->artisan('sports:report-epa-blend-calibration', ['sport' => $sport, '--forecast-source' => 'canonical'])
            ->expectsOutputToContain('Total graded predictions: 26')->assertSuccessful();
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    $alias = $sport === 'nfl' ? 'as "audit_outputs"' : 'as "audit_starts_at"';
    expect($queries->filter(fn ($sql) => str_contains($sql, $alias)))->toHaveCount(2)
        ->and($queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "event_input_snapshots"')))->toBeEmpty()
        ->and($queries->implode(' '))->not->toContain($sport.'_predictions');
})->with(['nfl', 'nba', 'cbb', 'wcbb']);

it('rejects unsupported sports and invalid forecast sources', function () {
    $this->artisan('sports:report-epa-blend-calibration', ['sport' => 'cfb'])->assertFailed();
    $this->artisan('sports:report-epa-blend-calibration', ['sport' => 'nfl', '--forecast-source' => 'unknown'])->assertFailed();
});

it('retains explicit not-applied flags and their precedence', function (string $sport) {
    epaReportForecast($sport, 'precedence');
    $row = collect(app(EpaBlendCalibrationSource::class)->rows($sport, 'canonical', 2026))->sole();
    expect($row->epa_applied)->toBeFalse();
    Artisan::call('sports:report-epa-blend-calibration', ['sport' => $sport, '--forecast-source' => 'canonical']);
    expect(Artisan::output())->toMatch('/true_epa_not_applied\s+\|\s+1\s+\|/')->toMatch('/true_epa_unknown\s+\|\s+0\s+\|/');
})->with(['nfl', 'nba', 'cbb', 'wcbb']);

it('retains approved historical releases but excludes invalidated releases', function (string $sport) {
    [, $prediction, , $input] = epaReportForecast($sport);
    $release = $prediction->calculationRun->release;
    $release->update(['status' => 'retired', 'retired_at' => now()]);
    $row = collect(app(EpaBlendCalibrationSource::class)->rows($sport, 'canonical', 2026))->sole();
    expect($row->canonical_prediction_id)->toBe($prediction->id)->and($row->canonical_lineage['event_input_snapshot_id'])->toBe($input->id);
    $release->update(['status' => 'invalidated', 'invalidated_at' => now(), 'invalidation_reason' => 'Fixture invalidation']);
    expect(collect(app(EpaBlendCalibrationSource::class)->rows($sport, 'canonical', 2026)))->toBeEmpty();
})->with(['nfl', 'nba', 'cbb', 'wcbb']);

it('streams historical rows in bounded batches and preserves optional-total and stored-winner math', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    foreach (range(1, 251) as $index) {
        $game = Game::factory()->create(['season' => 2026, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);
        Prediction::factory()->create(['game_id' => $game->id, 'graded_at' => now(),
            'predicted_spread' => 4, 'actual_spread' => 7, 'predicted_total' => $index === 251 ? null : 44,
            'actual_total' => 47, 'winner_correct' => false, 'model_metadata' => null]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('sports:report-epa-blend-calibration', ['sport' => 'nfl', '--forecast-source' => 'legacy']))->toBe(0);
        $output = Artisan::output();
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'from "nfl_predictions" as "p"'));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($output)->toContain('Total graded predictions: 251')->toMatch('/all\s+\|\s+251\s+\|\s+3.000\s+\|\s+3.000\s+\|\s+0.00/')
        ->and($queries)->toHaveCount(2)->and($queries->every(fn ($sql) => str_contains($sql, 'limit 250')))->toBeTrue();
});
