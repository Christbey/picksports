<?php

use App\Actions\CBB\GeneratePrediction;
use App\Actions\CBB\UpdateLivePrediction;
use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\EventInputSnapshot;
use App\Models\LivePredictionSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\CBB\CbbLiveAuditSource;
use App\Services\Predictions\Basketball\BasketballBacktestForecastSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-10-31 12:00:00');
    config(['prediction_lifecycle.canonical_pipeline.cbb' => false, 'prediction_lifecycle.canonical_reads.cbb' => false]);
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function cbbBacktestRetirementFixture(string $variant = 'valid', int $season = 2027, int $seasonType = 2, int $daysAgo = 2): array
{
    $home = Team::where('abbreviation', 'DEN')->first() ?? Team::factory()->create(['abbreviation' => 'DEN']);
    $away = Team::where('abbreviation', 'POR')->first() ?? Team::factory()->create(['abbreviation' => 'POR']);
    $start = now()->subDays($daysAgo);
    $generated = $start->copy()->subHours(2);
    $captured = $generated->copy()->subHour();
    $event = SportEvent::factory()->create(['sport' => 'cbb', 'season' => $season, 'starts_at' => $start]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => $season, 'season_type' => $seasonType, 'status' => 'STATUS_FINAL', 'home_score' => 120, 'away_score' => 115,
        'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(),
        'odds_data' => ['home_team' => 'DEN', 'bookmakers' => [['markets' => [['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 999]]]]]]]]);
    $legacy = Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 99, 'predicted_total' => 888, 'vegas_spread' => -3.5]);
    $input = EventInputSnapshot::factory()->create(['sport' => 'cbb', 'sport_event_id' => $event->id, 'phase' => 'pregame',
        'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified', 'captured_at' => $captured, 'cutoff_at' => $start,
        'latest_source_available_at' => $variant === 'future_source' ? $generated->copy()->addMinute() : $captured,
        'inputs' => ['event' => ['starts_at' => $variant === 'missing_start' ? null : ($variant === 'kickoff_mismatch' ? $start->copy()->addHour() : $start)->toIso8601String()],
            'home' => ['elo' => 1600], 'away' => ['elo' => 1500],
            'pregame_market' => $variant === 'no_frozen_market' ? [] : ['available' => $variant !== 'missing_market', 'consensus' => [
                'spread' => ['line' => $variant === 'missing_spread_line' ? null : -3.5],
                'total' => ['line' => $variant === 'missing_total_line' ? null : 230.0],
            ]]]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'cbb', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => 'cbb', 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null, 'generated_at' => $generated,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['spread', 'home', $variant === 'missing_spread' ? null : -8.0, null],
        ['total', 'combined', $variant === 'missing_total' ? null : 240.0, null], ['moneyline', 'home', null, $variant === 'missing_probability' ? null : 0.7]] as [$type, $selection, $line, $probability]) {
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

function cbbRetirementRows(string $command): Collection
{
    return (new ReflectionMethod(Artisan::all()[$command], 'canonicalRows'))->invoke(Artisan::all()[$command]);
}

it('uses native projections and same-input market lines without legacy reads or regeneration', function (bool $pipeline, bool $reads) {
    [$game, $native] = cbbBacktestRetirementFixture();
    config(['prediction_lifecycle.canonical_pipeline.cbb' => $pipeline, 'prediction_lifecycle.canonical_reads.cbb' => $reads]);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('cbb:backtest-spreads', ['--season' => 2027, '--detailed' => true]))->toBe(0);
        expect(Artisan::output())->toContain('Forecast source: canonical', 'Rows: 1', '1-0-0');
        $spread = cbbRetirementRows('cbb:backtest-spreads')->sole();
        expect(Artisan::call('cbb:backtest-totals', ['--season' => 2027, '--stored' => true, '--detailed' => true]))->toBe(0);
        expect(Artisan::output())->toContain('Rows: 1', '240.0', '230.0', '235.0', '1-0-0')->not->toContain('888.0', '999.0', '210.0');
        $total = cbbRetirementRows('cbb:backtest-totals')->sole();
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($spread)->toMatchArray(['canonical_prediction_id' => $native->id, 'model_spread' => 8.0, 'market_spread' => 3.5,
        'market_sportsbook_spread' => -3.5, 'actual_margin' => 5.0, 'pick' => 'home', 'result' => 'win', 'edge' => 4.5, 'model_error' => 3.0])
        ->and($total)->toMatchArray(['canonical_prediction_id' => $native->id, 'model_total' => 240.0,
            'market_total' => 230.0, 'actual_total' => 235.0, 'pick' => 'over', 'result' => 'over', 'model_error' => 5.0])
        ->and($total['canonical_lineage']['calculation_run_id'])->toBe($native->calculation_run_id)
        ->and($sql)->not->toContain('cbb_predictions')->not->toContain('prediction_feature_snapshots')->not->toContain('odds_data');
})->with([[true, false], [false, true], [true, true]]);

it('excludes unusable native evidence without borrowing historical forecasts or quotes', function (string $variant) {
    cbbBacktestRetirementFixture($variant);
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true]);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    $this->artisan('cbb:backtest-spreads', ['--season' => 2027])->expectsOutputToContain('No final CBB predictions with spread market data')->assertSuccessful();
    $this->artisan('cbb:backtest-totals', ['--season' => 2027])->expectsOutputToContain('No final CBB predictions with totals market data')->assertSuccessful();
})->with(['unsafe', 'draft', 'withdrawn', 'future_source', 'missing_start', 'kickoff_mismatch', 'late_publication', 'missing_market', 'no_frozen_market']);

it('keeps per-market gaps independent and never converts missing projections to zero', function (string $variant, string $usable) {
    cbbBacktestRetirementFixture($variant);
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true]);
    Artisan::call('cbb:backtest-spreads', ['--season' => 2027]);
    $spreads = cbbRetirementRows('cbb:backtest-spreads');
    Artisan::call('cbb:backtest-totals', ['--season' => 2027]);
    $totals = cbbRetirementRows('cbb:backtest-totals');
    expect($spreads->count())->toBe($usable === 'spread' ? 1 : 0)->and($totals->count())->toBe($usable === 'total' ? 1 : 0);
})->with([['missing_spread', 'total'], ['missing_spread_line', 'total'], ['missing_total', 'spread'], ['missing_total_line', 'spread']]);

it('retains stored and recomputed historical spreads and frozen historical totals after cutover', function () {
    [$game] = cbbBacktestRetirementFixture();
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true, 'prediction_lifecycle.canonical_reads.cbb' => true]);
    $this->artisan('cbb:backtest-spreads', ['--season' => 2027, '--forecast-source' => 'legacy', '--stored' => true])
        ->expectsOutputToContain('99.0')->assertSuccessful();
    $this->mock(GeneratePrediction::class)->shouldReceive('preview')->once()->withArgs(fn ($preview) => $preview->id === $game->id && $preview->status === 'STATUS_SCHEDULED')
        ->andReturn(['predicted_spread' => 6.0]);
    $this->artisan('cbb:backtest-spreads', ['--season' => 2027, '--forecast-source' => 'legacy'])->expectsOutputToContain('6.0')->assertSuccessful();
    $this->artisan('cbb:backtest-totals', ['--season' => 2027, '--forecast-source' => 'legacy', '--stored' => true])->expectsOutputToContain('888.0')->expectsOutputToContain('999.0')->assertSuccessful();
    expect($game->refresh()->status)->toBe('STATUS_FINAL');
});

it('keeps totals passes and pushes and spread pushes unchanged', function () {
    [$game] = cbbBacktestRetirementFixture();
    $game->update(['home_score' => 117, 'away_score' => 113]);
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true]);
    Artisan::call('cbb:backtest-totals', ['--season' => 2027]);
    expect(cbbRetirementRows('cbb:backtest-totals')->sole()['result'])->toBe('push');
    $command = Artisan::all()['cbb:backtest-totals'];
    $pass = (new ReflectionMethod($command, 'totalRow'))->invoke($command, $game, 230.0, 230.0);
    expect($pass['pick'])->toBe('under');
    Artisan::call('cbb:backtest-spreads', ['--season' => 2027]);
    $spreadCommand = Artisan::all()['cbb:backtest-spreads'];
    $push = (new ReflectionMethod($spreadCommand, 'spreadRow'))->invoke($spreadCommand, $game, 8.0, -4.0, 70.0);
    expect($push['result'])->toBe('push');
});

it('applies native row limits after rejecting unusable forecasts and respects season filters', function () {
    [, $older] = cbbBacktestRetirementFixture(daysAgo: 4);
    [, $newer] = cbbBacktestRetirementFixture(daysAgo: 3);
    cbbBacktestRetirementFixture('missing_market', daysAgo: 2);
    cbbBacktestRetirementFixture(season: 2026, daysAgo: 1);
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true]);
    foreach (['cbb:backtest-spreads', 'cbb:backtest-totals'] as $command) {
        Artisan::call($command, ['--season' => 2027, '--limit' => 1]);
        expect(cbbRetirementRows($command)->pluck('canonical_prediction_id')->all())->toBe([$newer->id]);
        Artisan::call($command, ['--season' => 2027, '--limit' => 0]);
        expect(cbbRetirementRows($command)->pluck('canonical_prediction_id')->all())->toBe([$newer->id, $older->id]);
    }
});

it('batches canonical CBB inputs without per-game or legacy snapshot reads', function () {
    foreach (range(1, 26) as $index) {
        cbbBacktestRetirementFixture();
    }
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true]);
    foreach (['cbb:backtest-spreads', 'cbb:backtest-totals'] as $command) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            Artisan::call($command, ['--season' => 2027]);
            expect(Artisan::output())->toContain('Rows: 26');
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        expect($queries->filter(fn (string $sql): bool => str_starts_with($sql, 'select * from "event_input_snapshots"'))->count())->toBe(2)
            ->and($queries->implode(' '))->not->toContain('cbb_predictions')->not->toContain('prediction_feature_snapshots');
    }
});

it('does not label missing native confidence as a low-confidence sample', function () {
    cbbBacktestRetirementFixture('missing_confidence');
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true]);
    Artisan::call('cbb:backtest-spreads', ['--season' => 2027]);
    $rows = cbbRetirementRows('cbb:backtest-spreads');
    $command = Artisan::all()['cbb:backtest-spreads'];
    expect($rows->sole()['confidence_score'])->toBeNull()
        ->and((new ReflectionMethod($command, 'confidenceBuckets'))->invoke($command, $rows))->toBeEmpty();
});

it('allows explicit canonical inspection before cutover and rejects invalid sources', function () {
    cbbBacktestRetirementFixture();
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    foreach (['cbb:backtest-spreads', 'cbb:backtest-totals'] as $command) {
        $this->artisan($command, ['--season' => 2027, '--forecast-source' => 'canonical'])->expectsOutputToContain('Forecast source: canonical')
            ->expectsOutputToContain('Rows: 1')->assertSuccessful();
        $this->artisan($command, ['--forecast-source' => 'invalid'])->assertFailed();
    }
});

it('preserves historical spread fallback when the current preview has no projection', function () {
    cbbBacktestRetirementFixture();
    $this->mock(GeneratePrediction::class)->shouldReceive('preview')->once()->andReturn([]);
    $this->artisan('cbb:backtest-spreads', ['--season' => 2027, '--forecast-source' => 'legacy'])
        ->expectsOutputToContain('99.0')->assertSuccessful();
});

it('preserves CBB historical totals recomputation and explicit source parity', function () {
    [$game] = cbbBacktestRetirementFixture();
    Artisan::call('cbb:backtest-totals', ['--season' => 2027, '--stored' => true]);
    $compatibility = Artisan::output();
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true]);
    Artisan::call('cbb:backtest-totals', ['--forecast-source' => 'legacy', '--season' => 2027, '--stored' => true]);
    expect(Artisan::output())->toBe($compatibility);
    $this->mock(GeneratePrediction::class)->shouldReceive('preview')->once()
        ->withArgs(fn ($preview) => $preview->id === $game->id && $preview->status === 'STATUS_SCHEDULED')
        ->andReturn(['predicted_total' => 215.0]);
    $this->artisan('cbb:backtest-totals', ['--forecast-source' => 'legacy', '--season' => 2027])->expectsOutputToContain('215.0')->assertSuccessful();
    expect($game->refresh()->status)->toBe('STATUS_FINAL');
});

it('preserves CBB spread equality and approval state in native reporting', function () {
    [$game] = cbbBacktestRetirementFixture();
    $statuses = CalculationRelease::pluck('status', 'id')->all();
    $this->artisan('cbb:backtest-spreads', ['--forecast-source' => 'canonical', '--season' => 2027])->assertSuccessful();
    $command = Artisan::all()['cbb:backtest-spreads'];
    $row = (new ReflectionMethod($command, 'spreadRow'))->invoke($command, $game, 3.5, -3.5, null);
    expect($row)->toMatchArray(['pick' => 'away', 'edge' => 0.0, 'confidence_score' => null])
        ->and(CalculationRelease::pluck('status', 'id')->all())->toBe($statuses);
});

function cbbLiveAuditSnapshot($game, $native, array $overrides = []): LivePredictionSnapshot
{
    return LivePredictionSnapshot::create([...[
        'sport' => 'cbb', 'game_table' => 'cbb_games', 'game_id' => $game->id, 'canonical_prediction_id' => $native->id,
        'baseline' => ['prediction_source' => 'canonical', 'pinned' => true, 'canonical_prediction_id' => $native->id, 'calculation_run_id' => $native->calculation_run_id],
        'state' => ['status' => 'STATUS_IN_PROGRESS'],
        'projection' => ['live_win_probability' => 0.8, 'live_predicted_spread' => 8.0, 'live_predicted_total' => 240.0, 'live_seconds_remaining' => 120],
        'status' => 'live', 'calculator' => 'test', 'state_hash' => hash('sha256', (string) Str::uuid()),
        'observed_at' => $native->sportEvent->starts_at->addHour(),
    ], ...$overrides]);
}

it('audits canonical CBB live snapshots without legacy reads and ignores final markers', function (bool $pipeline, bool $reads) {
    [$game, $native] = cbbBacktestRetirementFixture();
    $live = cbbLiveAuditSnapshot($game, $native);
    cbbLiveAuditSnapshot($game, $native, ['status' => 'final', 'projection' => null, 'observed_at' => $live->observed_at->addMinute()]);
    config(['prediction_lifecycle.canonical_pipeline.cbb' => $pipeline, 'prediction_lifecycle.canonical_reads.cbb' => $reads]);
    $statuses = CalculationRelease::pluck('status', 'id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->artisan('cbb:backtest-live-predictions', ['--season' => 2027, '--detailed' => true])->expectsOutputToContain('Forecast source: canonical live snapshots')
            ->expectsOutputToContain('Metric samples (probability/spread/total): 1/1/1')->assertSuccessful();
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    $report = app(CbbLiveAuditSource::class)->report(2027);
    expect($report['final'])->toMatchArray(['final_predictions' => 1, 'final_rows_with_live_fields' => 1, 'live_spread_mae' => 3.0, 'live_total_mae' => 5.0])
        ->and($report['final']['brier_score'])->toEqualWithDelta(0.04, 0.000001)
        ->and($queries->implode(' '))->not->toContain('cbb_predictions', 'prediction_feature_snapshots', 'odds_data')
        ->and(CalculationRelease::pluck('status', 'id')->all())->toBe($statuses);
})->with([[true, false], [false, true], [true, true]]);

it('holds unsafe canonical CBB live baselines without borrowing legacy fields', function (string $variant) {
    [$game, $native] = cbbBacktestRetirementFixture($variant);
    cbbLiveAuditSnapshot($game, $native);
    expect(app(CbbLiveAuditSource::class)->report(2027)['final']['final_rows_with_live_fields'])->toBe(0);
})->with(['unsafe', 'draft', 'withdrawn', 'future_source', 'late_publication', 'missing_start', 'kickoff_mismatch']);

it('keeps missing CBB live metrics out of numeric samples', function () {
    [$game, $native] = cbbBacktestRetirementFixture();
    cbbLiveAuditSnapshot($game, $native, ['projection' => ['live_predicted_spread' => 8.0]]);
    $final = app(CbbLiveAuditSource::class)->report(2027)['final'];
    expect($final)->toMatchArray(['brier_score' => null, 'live_total_mae' => null, 'live_spread_mae' => 3.0,
        'brier_score_sample' => 0, 'live_spread_mae_sample' => 1, 'live_total_mae_sample' => 0]);
    $this->artisan('cbb:backtest-live-predictions', ['--forecast-source' => 'canonical', '--season' => 2027])->expectsOutputToContain('n/a')->assertSuccessful();
});

it('uses the latest CBB live snapshot for current coverage without bypassing holds', function () {
    [$game, $native] = cbbBacktestRetirementFixture();
    $game->update(['status' => 'STATUS_IN_PROGRESS']);
    $live = cbbLiveAuditSnapshot($game, $native);
    $source = app(CbbLiveAuditSource::class);
    expect($source->report(2027)['current']->sole())->toMatchArray(['has_live_fields' => true, 'live_win_probability' => 0.8]);
    cbbLiveAuditSnapshot($game, $native, ['status' => 'missing_pregame', 'projection' => null, 'observed_at' => $live->observed_at->addMinute()]);
    expect($source->report(2027)['current']->sole())->toMatchArray(['has_live_fields' => false, 'live_win_probability' => null]);
    $this->artisan('cbb:backtest-live-predictions', ['--forecast-source' => 'canonical', '--season' => 2027, '--detailed' => true])->assertSuccessful();
});

it('rejects mismatched or unpinned live lineage and future observations', function (string $variant) {
    [$game, $native] = cbbBacktestRetirementFixture();
    $baseline = ['prediction_source' => 'canonical', 'pinned' => true, 'canonical_prediction_id' => $native->id, 'calculation_run_id' => $native->calculation_run_id];
    if ($variant === 'unpinned') {
        $baseline['pinned'] = false;
    } elseif ($variant === 'legacy') {
        $baseline['prediction_source'] = 'legacy';
    } elseif ($variant === 'run') {
        $baseline['calculation_run_id'] = 'wrong';
    }
    cbbLiveAuditSnapshot($game, $native, ['baseline' => $baseline, 'observed_at' => $variant === 'future' ? now()->addHour() : $native->sportEvent->starts_at->addHour()]);
    expect(app(CbbLiveAuditSource::class)->report(2027)['final']['final_rows_with_live_fields'])->toBe(0);
})->with(['unpinned', 'legacy', 'run', 'future']);

it('retains explicit historical CBB live audit output and validates sources', function () {
    cbbBacktestRetirementFixture();
    Artisan::call('cbb:backtest-live-predictions', ['--season' => 2027]);
    $historical = Artisan::output();
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true]);
    Artisan::call('cbb:backtest-live-predictions', ['--forecast-source' => 'legacy', '--season' => 2027]);
    expect(Artisan::output())->toBe($historical);
    $this->artisan('cbb:backtest-live-predictions', ['--forecast-source' => 'invalid'])->assertFailed();
});

it('batches CBB live audit inputs and keeps season scopes isolated', function () {
    for ($i = 0; $i < 26; $i++) {
        [$game, $native] = cbbBacktestRetirementFixture();
        cbbLiveAuditSnapshot($game, $native);
    }
    [$outside, $native] = cbbBacktestRetirementFixture(season: 2026);
    cbbLiveAuditSnapshot($outside, $native);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $report = app(CbbLiveAuditSource::class)->report(2027);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($report['final'])->toMatchArray(['final_predictions' => 26, 'final_rows_with_live_fields' => 26, 'brier_score_sample' => 26]);
    expect($queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "event_input_snapshots"')))->toHaveCount(2);
});

function cbbReplayPlays(Game $game, int $count = 3): void
{
    for ($i = 1; $i <= $count; $i++) {
        Play::factory()->create(['game_id' => $game->id, 'sequence_number' => $i,
            'period' => 1, 'clock' => '10:00', 'home_score' => 20 + $i, 'away_score' => 18 + $i]);
    }
}

it('replays CBB plays with the canonical baseline and shared preview grading', function (bool $pipeline, bool $reads) {
    [$game, $native, $legacy] = cbbBacktestRetirementFixture();
    cbbReplayPlays($game);
    $legacy->delete();
    config(['prediction_lifecycle.canonical_pipeline.cbb' => $pipeline, 'prediction_lifecycle.canonical_reads.cbb' => $reads]);
    $updater = Mockery::mock(UpdateLivePrediction::class);
    $updater->shouldReceive('previewState')->times(3)->withArgs(fn ($g, $baseline, $period, $clock, $home, $away) => $g->id === $game->id
        && $baseline->canonical_prediction_id === $native->id && $baseline->predicted_spread === 8.0 && $baseline->predicted_total === 240.0
        && $baseline->win_probability === 0.7 && $period === 1 && $clock === '10:00' && $home - $away === 2)
        ->andReturn(['live_seconds_remaining' => 1800, 'live_win_probability' => 0.7, 'live_predicted_spread' => 8.0, 'live_predicted_total' => 240.0]);
    app()->instance(UpdateLivePrediction::class, $updater);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('cbb:backtest-live-from-plays', ['--season' => 2027, '--detailed' => true]))->toBe(0);
        expect(Artisan::output())->toContain('Forecast source: canonical', 'current live formulas', '100.0%', '3.00', '5.00');
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn ($sql) => str_contains($sql, 'cbb_predictions')))->toBeEmpty()
        ->and(LivePredictionSnapshot::count())->toBe(0)->and(BetDecision::count())->toBe(0);
})->with([[true, false], [false, true]]);

it('holds incomplete canonical play replay inputs without default or historical baselines', function (string $variant) {
    [$game] = cbbBacktestRetirementFixture($variant);
    cbbReplayPlays($game);
    $this->artisan('cbb:backtest-live-from-plays', ['--forecast-source' => 'canonical'])
        ->expectsOutputToContain('No final games with plays and predictions')->assertSuccessful();
})->with(['unsafe', 'future_source', 'missing_start', 'kickoff_mismatch', 'draft', 'withdrawn', 'late_publication', 'missing_spread', 'missing_total', 'missing_probability']);

it('keeps historical play replay and source validation while requiring plays in native mode', function () {
    [$game] = cbbBacktestRetirementFixture();
    cbbReplayPlays($game);
    Artisan::call('cbb:backtest-live-from-plays', ['--season' => 2027, '--detailed' => true]);
    $legacy = Artisan::output();
    Artisan::call('cbb:backtest-live-from-plays', ['--forecast-source' => 'legacy', '--season' => 2027, '--detailed' => true]);
    expect(Artisan::output())->toBe($legacy);
    cbbBacktestRetirementFixture('valid', daysAgo: 1);
    $source = app(BasketballBacktestForecastSource::class);
    expect($source->forCbbPlayReplay(2027, 1)->sole()->game->id)->toBe($game->id);
    $this->artisan('cbb:backtest-live-from-plays', ['--forecast-source' => 'invalid'])->assertFailed();
    $this->artisan('cbb:backtest-live-from-plays', ['--forecast-source' => 'canonical', '--states-per-game' => 10])
        ->expectsOutputToContain('No playable snapshots')->assertSuccessful();
});

it('batches native play baselines and stops after the eligible game limit', function () {
    for ($i = 0; $i < 26; $i++) {
        [$game] = cbbBacktestRetirementFixture();
        cbbReplayPlays($game, 1);
    }
    $source = app(BasketballBacktestForecastSource::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect($source->forCbbPlayReplay(2027, 26))->toHaveCount(26);
        expect(collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_starts_with($sql, 'select * from "event_input_snapshots"')))->toHaveCount(2);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($source->forCbbPlayReplay(2027, 1))->toHaveCount(1)->and($source->forCbbPlayReplay(2026, 26))->toBeEmpty();
});

it('keeps native play replay independent of confidence and market quote coverage', function (string $variant) {
    [$game] = cbbBacktestRetirementFixture($variant);
    cbbReplayPlays($game);
    $source = app(BasketballBacktestForecastSource::class);
    expect($source->forCbbPlayReplay(2027, 1)->sole()->win_probability)->toBe(0.7);
    expect(Artisan::call('cbb:backtest-live-from-plays', ['--forecast-source' => 'canonical', '--season' => 2027]))->toBe(0);
    expect(Artisan::output())->toContain('Winner Accuracy')->not->toContain('No playable');
    expect(LivePredictionSnapshot::count())->toBe(0)->and(BetDecision::count())->toBe(0);
})->with(['missing_confidence', 'missing_market']);

it('audits canonical CBB availability without legacy reads while preserving input warnings', function (bool $pipeline, bool $reads) {
    [$game, , $legacy] = cbbBacktestRetirementFixture();
    $legacy->delete();
    config(['prediction_lifecycle.canonical_pipeline.cbb' => $pipeline, 'prediction_lifecycle.canonical_reads.cbb' => $reads]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('cbb:audit-prediction-inputs', ['--game' => $game->id, '--include-placeholders' => true, '--show-ok' => true]))->toBe(0);
        expect(Artisan::output())->toContain('Forecast source: canonical', 'missing_team_metrics', 'missing_spreads', 'missing_h2h')
            ->not->toContain('missing_prediction_row');
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn ($sql) => str_contains($sql, 'cbb_predictions')))->toBeEmpty()
        ->and($queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "event_input_snapshots"')))->toBeEmpty()
        ->and(BetDecision::count())->toBe(0);
})->with([[true, false], [false, true]]);

it('keeps canonical input-audit gaps visible despite legacy coverage', function (string $variant) {
    [$game] = cbbBacktestRetirementFixture($variant);
    expect(Artisan::call('cbb:audit-prediction-inputs', ['--forecast-source' => 'canonical', '--game' => $game->id, '--include-placeholders' => true]))->toBe(0);
    expect(Artisan::output())->toContain('missing_prediction_row');
})->with(['unsafe', 'draft', 'withdrawn']);

it('preserves historical CBB input-audit output and last-play completeness', function () {
    [$game] = cbbBacktestRetirementFixture();
    cbbReplayPlays($game);
    $args = ['--game' => $game->id, '--include-placeholders' => true, '--show-ok' => true];
    Artisan::call('cbb:audit-prediction-inputs', $args);
    $before = Artisan::output();
    Artisan::call('cbb:audit-prediction-inputs', [...$args, '--forecast-source' => 'legacy']);
    expect(Artisan::output())->toBe($before)->not->toContain('missing_prediction_row');
    Play::query()->where('game_id', $game->id)->where('sequence_number', 3)->update(['home_score' => 120, 'away_score' => 115]);
    Artisan::call('cbb:audit-prediction-inputs', $args);
    expect(Artisan::output())->toContain('1 / 1');
    $this->artisan('cbb:audit-prediction-inputs', ['--forecast-source' => 'invalid'])->assertFailed();
});

it('batches CBB audit availability metrics and final-play lookups', function () {
    [$game] = cbbBacktestRetirementFixture();
    $game->update(['status' => 'STATUS_SCHEDULED']);
    Game::factory()->count(250)->create(['season' => 2027, 'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('cbb:audit-prediction-inputs', ['--forecast-source' => 'canonical', '--season' => 2027, '--include-historical' => true]))->toBe(0);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn ($sql) => str_contains($sql, 'select "cbb_games"."id" from "predictions"')))->toHaveCount(2)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'COUNT(*) as metric_count')))->toHaveCount(1)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'from "cbb_plays"') && str_contains($sql, 'latest')))->toHaveCount(1);
});

it('maps CBB canonical availability by game id and retains kickoff-boundary delivery semantics', function () {
    Game::factory()->create();
    [$game, $native] = cbbBacktestRetirementFixture('late_publication');
    expect($game->id)->not->toBe($native->id);
    Artisan::call('cbb:audit-prediction-inputs', ['--forecast-source' => 'canonical', '--game' => $game->id, '--include-placeholders' => true, '--show-ok' => true]);
    expect(Artisan::output())->not->toContain('missing_prediction_row');
});

it('preserves season-scoped missing-metric warnings in the batched CBB audit', function () {
    [$game] = cbbBacktestRetirementFixture();
    foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
        TeamMetric::factory()->create(['team_id' => $teamId, 'season' => 2026]);
    }
    $args = ['--forecast-source' => 'canonical', '--game' => $game->id, '--include-placeholders' => true, '--show-ok' => true];
    Artisan::call('cbb:audit-prediction-inputs', $args);
    expect(Artisan::output())->toContain('missing_team_metrics');
    foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
        TeamMetric::factory()->create(['team_id' => $teamId, 'season' => 2027]);
    }
    Artisan::call('cbb:audit-prediction-inputs', $args);
    expect(Artisan::output())->not->toContain('missing_team_metrics');
});
