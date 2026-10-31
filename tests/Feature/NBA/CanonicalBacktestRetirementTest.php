<?php

use App\Actions\NBA\GeneratePrediction;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-10-31 12:00:00');
    config(['prediction_lifecycle.canonical_pipeline.nba' => false, 'prediction_lifecycle.canonical_reads.nba' => false]);
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function nbaBacktestRetirementFixture(string $variant = 'valid', int $season = 2027, int $seasonType = 2, int $daysAgo = 2): array
{
    $home = Team::where('abbreviation', 'DEN')->first() ?? Team::factory()->create(['abbreviation' => 'DEN']);
    $away = Team::where('abbreviation', 'POR')->first() ?? Team::factory()->create(['abbreviation' => 'POR']);
    $start = now()->subDays($daysAgo);
    $generated = $start->copy()->subHours(2);
    $captured = $generated->copy()->subHour();
    $event = SportEvent::factory()->create(['sport' => 'nba', 'season' => $season, 'starts_at' => $start]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => $season, 'season_type' => $seasonType, 'status' => 'STATUS_FINAL', 'home_score' => 120, 'away_score' => 115,
        'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(),
        'odds_data' => ['home_team' => 'DEN', 'bookmakers' => [['markets' => [['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 999]]]]]]]]);
    $legacy = Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 99, 'predicted_total' => 888, 'vegas_spread' => -3.5]);
    $legacyRun = app(ModelRunRecorder::class)->create(sport: 'nba', runType: 'prediction', modelVersion: 'test', featureVersion: 'test', blendVersion: 'test');
    $historical = PredictionFeatureSnapshot::create(['sport' => 'nba', 'prediction_table' => 'nba_predictions', 'prediction_id' => $legacy->id,
        'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(), 'model_run_id' => $legacyRun->id,
        'model_version' => 'test', 'feature_version' => 'test', 'blend_version' => 'test', 'features' => [],
        'outputs' => ['predicted_total' => 210], 'market_context' => ['market_total' => 220], 'feature_hash' => str_repeat('a', 64),
        'generated_at' => $generated, 'features_available_at' => $captured, 'game_start_at' => $start,
        'pregame_safe' => true, 'availability_status' => 'observed_pregame']);
    $input = EventInputSnapshot::factory()->create(['sport' => 'nba', 'sport_event_id' => $event->id, 'phase' => 'pregame',
        'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified', 'captured_at' => $captured, 'cutoff_at' => $start,
        'latest_source_available_at' => $variant === 'future_source' ? $generated->copy()->addMinute() : $captured,
        'inputs' => ['event' => ['starts_at' => $variant === 'missing_start' ? null : ($variant === 'kickoff_mismatch' ? $start->copy()->addHour() : $start)->toIso8601String()],
            'home' => ['elo' => 1600], 'away' => ['elo' => 1500],
            'pregame_market' => $variant === 'no_frozen_market' ? [] : ['available' => $variant !== 'missing_market', 'consensus' => [
                'spread' => ['line' => $variant === 'missing_spread_line' ? null : -3.5],
                'total' => ['line' => $variant === 'missing_total_line' ? null : 230.0],
            ]]]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'nba', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => 'nba', 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
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

    return [$game, $native, $legacy, $historical];
}

function nbaRetirementRows(string $command): Collection
{
    return (new ReflectionMethod(Artisan::all()[$command], 'canonicalRows'))->invoke(Artisan::all()[$command]);
}

it('uses native projections and same-input market lines without legacy reads or regeneration', function (bool $pipeline, bool $reads) {
    [$game, $native] = nbaBacktestRetirementFixture();
    config(['prediction_lifecycle.canonical_pipeline.nba' => $pipeline, 'prediction_lifecycle.canonical_reads.nba' => $reads]);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('nba:backtest-spreads', ['--season' => 2027, '--detailed' => true]))->toBe(0);
        expect(Artisan::output())->toContain('Forecast source: canonical', 'Rows: 1', '1-0-0');
        $spread = nbaRetirementRows('nba:backtest-spreads')->sole();
        expect(Artisan::call('nba:backtest-totals', ['--season' => 2027, '--stored' => true, '--detailed' => true]))->toBe(0);
        expect(Artisan::output())->toContain('Rows: 1', '240.0', '230.0', '235.0', '1-0-0')->not->toContain('888.0', '999.0', '210.0');
        $total = nbaRetirementRows('nba:backtest-totals')->sole();
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($spread)->toMatchArray(['canonical_prediction_id' => $native->id, 'model_spread' => 8.0, 'market_spread' => 3.5,
        'market_sportsbook_spread' => -3.5, 'actual_margin' => 5.0, 'pick' => 'home', 'result' => 'win', 'edge' => 4.5, 'model_error' => 3.0])
        ->and($total)->toMatchArray(['canonical_prediction_id' => $native->id, 'snapshot_id' => null, 'model_total' => 240.0,
            'market_total' => 230.0, 'actual_total' => 235.0, 'pick' => 'over', 'result' => 'over', 'model_error' => 5.0])
        ->and($total['canonical_lineage']['calculation_run_id'])->toBe($native->calculation_run_id)
        ->and($sql)->not->toContain('nba_predictions')->not->toContain('prediction_feature_snapshots')->not->toContain('odds_data');
})->with([[true, false], [false, true], [true, true]]);

it('excludes unusable native evidence without borrowing historical forecasts or quotes', function (string $variant) {
    nbaBacktestRetirementFixture($variant);
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    $this->artisan('nba:backtest-spreads', ['--season' => 2027])->expectsOutputToContain('No final NBA predictions with spread market data')->assertSuccessful();
    $this->artisan('nba:backtest-totals', ['--season' => 2027])->expectsOutputToContain('Excluded games without usable pregame evidence: 1')
        ->expectsOutputToContain('No final NBA games with usable frozen pregame totals')->assertSuccessful();
})->with(['unsafe', 'draft', 'withdrawn', 'future_source', 'missing_start', 'kickoff_mismatch', 'late_publication', 'missing_market', 'no_frozen_market']);

it('keeps per-market gaps independent and never converts missing projections to zero', function (string $variant, string $usable) {
    nbaBacktestRetirementFixture($variant);
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    Artisan::call('nba:backtest-spreads', ['--season' => 2027]);
    $spreads = nbaRetirementRows('nba:backtest-spreads');
    Artisan::call('nba:backtest-totals', ['--season' => 2027]);
    $totals = nbaRetirementRows('nba:backtest-totals');
    expect($spreads->count())->toBe($usable === 'spread' ? 1 : 0)->and($totals->count())->toBe($usable === 'total' ? 1 : 0);
})->with([['missing_spread', 'total'], ['missing_spread_line', 'total'], ['missing_total', 'spread'], ['missing_total_line', 'spread']]);

it('retains stored and recomputed historical spreads and frozen historical totals after cutover', function () {
    [$game] = nbaBacktestRetirementFixture();
    config(['prediction_lifecycle.canonical_pipeline.nba' => true, 'prediction_lifecycle.canonical_reads.nba' => true]);
    $this->artisan('nba:backtest-spreads', ['--season' => 2027, '--forecast-source' => 'legacy', '--stored' => true])
        ->expectsOutputToContain('99.0')->assertSuccessful();
    $this->mock(GeneratePrediction::class)->shouldReceive('preview')->once()->withArgs(fn ($preview) => $preview->id === $game->id && $preview->status === 'STATUS_SCHEDULED')
        ->andReturn(['predicted_spread' => 6.0]);
    $this->artisan('nba:backtest-spreads', ['--season' => 2027, '--forecast-source' => 'legacy'])->expectsOutputToContain('6.0')->assertSuccessful();
    $this->artisan('nba:backtest-totals', ['--season' => 2027, '--forecast-source' => 'legacy'])->expectsOutputToContain('210.0')->expectsOutputToContain('220.0')->assertSuccessful();
    expect($game->refresh()->status)->toBe('STATUS_FINAL');
});

it('keeps totals passes and pushes and spread pushes unchanged', function () {
    [$game] = nbaBacktestRetirementFixture();
    $game->update(['home_score' => 117, 'away_score' => 113]);
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    Artisan::call('nba:backtest-totals', ['--season' => 2027]);
    expect(nbaRetirementRows('nba:backtest-totals')->sole()['result'])->toBe('push');
    $command = Artisan::all()['nba:backtest-totals'];
    $pass = (new ReflectionMethod($command, 'totalRow'))->invoke($command, $game, 230.0, 230.0);
    expect($pass['pick'])->toBe('pass');
    Artisan::call('nba:backtest-spreads', ['--season' => 2027]);
    $spreadCommand = Artisan::all()['nba:backtest-spreads'];
    $push = (new ReflectionMethod($spreadCommand, 'spreadRow'))->invoke($spreadCommand, $game, 8.0, -4.0, 70.0);
    expect($push['result'])->toBe('push');
});

it('applies native row limits after rejecting unusable forecasts and respects season filters', function () {
    [, $older] = nbaBacktestRetirementFixture(daysAgo: 4);
    [, $newer] = nbaBacktestRetirementFixture(daysAgo: 3);
    nbaBacktestRetirementFixture('missing_market', daysAgo: 2);
    nbaBacktestRetirementFixture(season: 2026, daysAgo: 1);
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    foreach (['nba:backtest-spreads', 'nba:backtest-totals'] as $command) {
        Artisan::call($command, ['--season' => 2027, '--limit' => 1]);
        expect(nbaRetirementRows($command)->pluck('canonical_prediction_id')->all())->toBe([$newer->id]);
        Artisan::call($command, ['--season' => 2027, '--limit' => 0]);
        expect(nbaRetirementRows($command)->pluck('canonical_prediction_id')->all())->toBe([$newer->id, $older->id]);
    }
});

it('retains totals regular-season defaults and explicit postseason scope', function () {
    nbaBacktestRetirementFixture(seasonType: 1);
    [, $regular] = nbaBacktestRetirementFixture(seasonType: 2);
    [, $postseason] = nbaBacktestRetirementFixture(seasonType: 3);
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    Artisan::call('nba:backtest-totals', ['--season' => 2027]);
    expect(nbaRetirementRows('nba:backtest-totals')->pluck('canonical_prediction_id')->all())->toBe([$regular->id]);
    Artisan::call('nba:backtest-totals', ['--season' => 2027, '--season-type' => 3]);
    expect(nbaRetirementRows('nba:backtest-totals')->pluck('canonical_prediction_id')->all())->toBe([$postseason->id]);
    Artisan::call('nba:backtest-spreads', ['--season' => 2027]);
    expect(nbaRetirementRows('nba:backtest-spreads'))->toHaveCount(3);
});

it('batches canonical NBA inputs without per-game or legacy snapshot reads', function () {
    foreach (range(1, 26) as $index) {
        nbaBacktestRetirementFixture();
    }
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    foreach (['nba:backtest-spreads', 'nba:backtest-totals'] as $command) {
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
            ->and($queries->implode(' '))->not->toContain('nba_predictions')->not->toContain('prediction_feature_snapshots');
    }
});

it('does not label missing native confidence as a low-confidence sample', function () {
    nbaBacktestRetirementFixture('missing_confidence');
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    Artisan::call('nba:backtest-spreads', ['--season' => 2027]);
    $rows = nbaRetirementRows('nba:backtest-spreads');
    $command = Artisan::all()['nba:backtest-spreads'];
    expect($rows->sole()['confidence_score'])->toBeNull()
        ->and((new ReflectionMethod($command, 'confidenceBuckets'))->invoke($command, $rows))->toBeEmpty();
});

it('allows explicit canonical inspection before cutover and rejects invalid sources', function () {
    nbaBacktestRetirementFixture();
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    foreach (['nba:backtest-spreads', 'nba:backtest-totals'] as $command) {
        $this->artisan($command, ['--season' => 2027, '--forecast-source' => 'canonical'])->expectsOutputToContain('Forecast source: canonical')
            ->expectsOutputToContain('Rows: 1')->assertSuccessful();
        $this->artisan($command, ['--forecast-source' => 'invalid'])->assertFailed();
    }
});

it('preserves historical spread fallback when the current preview has no projection', function () {
    nbaBacktestRetirementFixture();
    $this->mock(GeneratePrediction::class)->shouldReceive('preview')->once()->andReturn([]);
    $this->artisan('nba:backtest-spreads', ['--season' => 2027, '--forecast-source' => 'legacy'])
        ->expectsOutputToContain('99.0')->assertSuccessful();
});
