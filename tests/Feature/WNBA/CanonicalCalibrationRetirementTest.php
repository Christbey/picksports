<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\Team;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-31 12:00:00');
    config(['prediction_lifecycle.canonical_pipeline.wnba' => false, 'prediction_lifecycle.canonical_reads.wnba' => false]);
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function wnbaBacktestRetirementFixture(string $variant = 'valid', int $season = 2027, int $seasonType = 2, int $daysAgo = 2): array
{
    $home = Team::where('abbreviation', 'DEN')->first() ?? Team::factory()->create(['abbreviation' => 'DEN']);
    $away = Team::where('abbreviation', 'POR')->first() ?? Team::factory()->create(['abbreviation' => 'POR']);
    $start = now()->subDays($daysAgo);
    $generated = $start->copy()->subHours(2);
    $captured = $generated->copy()->subHour();
    $event = SportEvent::factory()->create(['sport' => 'wnba', 'season' => $season, 'starts_at' => $start]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => $season, 'season_type' => $seasonType, 'status' => 'STATUS_FINAL', 'home_score' => 120, 'away_score' => 115,
        'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(),
        'odds_data' => ['home_team' => 'DEN', 'bookmakers' => [['markets' => [['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 999]]]]]]]]);
    $legacy = Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 99, 'predicted_total' => 888]);
    $input = EventInputSnapshot::factory()->create(['sport' => 'wnba', 'sport_event_id' => $event->id, 'phase' => 'pregame',
        'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified', 'captured_at' => $captured, 'cutoff_at' => $start,
        'latest_source_available_at' => $variant === 'future_source' ? $generated->copy()->addMinute() : $captured,
        'inputs' => ['event' => ['starts_at' => $variant === 'missing_start' ? null : ($variant === 'kickoff_mismatch' ? $start->copy()->addHour() : $start)->toIso8601String()],
            'home' => ['elo' => 1600], 'away' => ['elo' => 1500],
            'pregame_market' => $variant === 'no_frozen_market' ? [] : ['available' => $variant !== 'missing_market', 'consensus' => [
                'spread' => ['line' => $variant === 'missing_spread_line' ? null : -3.5],
                'total' => ['line' => $variant === 'missing_total_line' ? null : 230.0],
            ]]]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'wnba', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => 'wnba', 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null, 'generated_at' => $generated,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['spread', 'home', $variant === 'missing_spread' ? null : -8.0, null],
        ['total', 'combined', $variant === 'missing_total' ? null : 240.0, null], ['moneyline', 'home', null, 0.7]] as [$type, $selection, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type, 'selection' => $selection,
            'projected_line' => $line, 'probability' => $probability, 'confidence_score' => $variant === 'missing_confidence' ? null : 70]);
    }
    if ($variant !== 'draft') {
        $native->update(['publication_state' => 'published', 'published_at' => $variant === 'late_publication' ? $start : $generated]);
    }
    if ($variant === 'withdrawn') {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    return [$game, $native, $legacy];
}

function wnbaCalibrationReport(array $args = []): array
{
    expect(Artisan::call('wnba:report-calibration', ['--forecast-source' => 'canonical', '--season' => 2027, '--json' => true, ...$args]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('reports frozen native WNBA metrics without legacy or mutable inputs', function (bool $pipeline, bool $reads) {
    [, $native, $legacy] = wnbaBacktestRetirementFixture();
    $legacy->update(['winner_correct' => false, 'graded_at' => now()]);
    config(['prediction_lifecycle.canonical_pipeline.wnba' => $pipeline, 'prediction_lifecycle.canonical_reads.wnba' => $reads]);
    $statuses = CalculationRelease::pluck('status', 'id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('wnba:report-calibration', ['--season' => 2027, '--json' => true]))->toBe(0);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($report['summary'])->toMatchArray(['count' => 1, 'winner_accuracy' => 100, 'spread_mae' => 3, 'total_mae' => 5, 'spread_bias' => 3, 'total_bias' => 5, 'avg_confidence' => 70])
        ->and($report['forecast_source'])->toBe('canonical')
        ->and($report['forecast_lineage'][0]['canonical_prediction_id'])->toBe($native->id)
        ->and($queries->implode(' '))->not->toContain('wnba_predictions', 'odds_data', 'prediction_feature_snapshots')
        ->and(CalculationRelease::pluck('status', 'id')->all())->toBe($statuses);
})->with([[true, false], [false, true], [true, true]]);

it('excludes unsafe or incomplete native WNBA calibration inputs', function (string $variant) {
    wnbaBacktestRetirementFixture($variant);
    $this->artisan('wnba:report-calibration', ['--forecast-source' => 'canonical', '--season' => 2027])->expectsOutputToContain('No eligible canonical WNBA forecasts')->assertSuccessful();
})->with(['unsafe', 'draft', 'withdrawn', 'future_source', 'missing_start', 'kickoff_mismatch', 'late_publication', 'missing_spread', 'missing_total']);

it('keeps absent confidence unknown and does not require market quotes', function () {
    wnbaBacktestRetirementFixture('missing_confidence');
    $report = wnbaCalibrationReport();
    expect($report['summary']['avg_confidence'])->toBeNull()->and($report['summary']['min_confidence'])->toBeNull()->and($report['summary']['max_confidence'])->toBeNull()->and($report['confidence_buckets'])->toBeEmpty();
    $this->artisan('wnba:report-calibration', ['--forecast-source' => 'canonical', '--season' => 2027])->expectsOutputToContain('n/a')->assertSuccessful();
    wnbaBacktestRetirementFixture('no_frozen_market');
    $mixed = wnbaCalibrationReport();
    expect($mixed['summary']['count'])->toBe(2)->and($mixed['summary']['avg_confidence'])->toBe(70)->and($mixed['confidence_buckets'][0]['games'])->toBe(1);
});

it('retains historical JSON and text output after WNBA cutover', function () {
    [,,$legacy] = wnbaBacktestRetirementFixture();
    $legacy->update(['graded_at' => now(), 'winner_correct' => false]);
    Artisan::call('wnba:report-calibration', ['--season' => 2027, '--json' => true]);
    $json = Artisan::output();
    Artisan::call('wnba:report-calibration', ['--season' => 2027]);
    $text = Artisan::output();
    config(['prediction_lifecycle.canonical_pipeline.wnba' => true]);
    Artisan::call('wnba:report-calibration', ['--forecast-source' => 'legacy', '--season' => 2027, '--json' => true]);
    expect(Artisan::output())->toBe($json);
    Artisan::call('wnba:report-calibration', ['--forecast-source' => 'legacy', '--season' => 2027]);
    expect(Artisan::output())->toBe($text);
});

it('applies WNBA limits after eligibility and preserves season scope', function () {
    [, $older] = wnbaBacktestRetirementFixture(daysAgo: 4);
    [, $newer] = wnbaBacktestRetirementFixture(daysAgo: 3);
    wnbaBacktestRetirementFixture('missing_spread', daysAgo: 2);
    wnbaBacktestRetirementFixture(season: 2026, daysAgo: 1);
    expect(wnbaCalibrationReport(['--limit' => 1])['forecast_lineage'][0]['canonical_prediction_id'])->toBe($newer->id)
        ->and(wnbaCalibrationReport(['--limit' => 20])['summary']['count'])->toBe(2);
    $this->artisan('wnba:report-calibration', ['--forecast-source' => 'invalid'])->assertFailed();
});

it('hydrates WNBA calibration snapshots in bounded batches', function () {
    foreach (range(1, 26) as $i) {
        wnbaBacktestRetirementFixture();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(wnbaCalibrationReport()['summary']['count'])->toBe(26);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "event_input_snapshots"')))->toHaveCount(2);
});

it('keeps WNBA spread-based winner ties and signed error behavior', function () {
    [$game] = wnbaBacktestRetirementFixture();
    $game->update(['home_score' => 115, 'away_score' => 115]);
    expect(wnbaCalibrationReport()['summary'])->toMatchArray(['winner_accuracy' => 0, 'spread_bias' => 8, 'spread_mae' => 8]);
    $game->update(['home_score' => 110, 'away_score' => 115]);
    expect(wnbaCalibrationReport()['summary'])->toMatchArray(['winner_accuracy' => 0, 'spread_bias' => 13, 'spread_mae' => 13]);
});
