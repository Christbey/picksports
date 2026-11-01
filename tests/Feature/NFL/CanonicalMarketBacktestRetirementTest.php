<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\NFL\NflBacktestForecastSource;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Support\NflModelVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-31 12:00:00');
    config(['prediction_lifecycle.canonical_pipeline.nfl' => false, 'prediction_lifecycle.canonical_reads.nfl' => false,
        'nfl.betting.totals.play_min_edge' => 4.0, 'nfl.betting.totals.play_max_edge' => 6.0, 'nfl.betting.totals.watchlist_only_rules' => []]);
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function nflMarketBacktestFixture(string $variant = 'valid', int $season = 2026, int $daysAgo = 2, array $moneyline = [], ?array $proLayer = null): array
{
    $start = now()->subDays($daysAgo);
    $generated = $start->copy()->subHours(2);
    $captured = $generated->copy()->subHour();
    $home = Team::where('abbreviation', 'HOM')->first() ?? Team::factory()->create(['abbreviation' => 'HOM']);
    $away = Team::where('abbreviation', 'AWY')->first() ?? Team::factory()->create(['abbreviation' => 'AWY']);
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'season' => $season, 'starts_at' => $start]);
    $odds = ['home_team' => 'Home Team', 'bookmakers' => [['key' => 'draftkings', 'markets' => [
        ['key' => 'spreads', 'outcomes' => [['name' => 'Home Team', 'point' => -3]]], ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 999]]],
    ]]]];
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => $season, 'status' => 'STATUS_FINAL', 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'home_score' => 27, 'away_score' => 20, 'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(), 'odds_data' => $odds]);
    $metadata = ['analysis_layer' => ['trust_score' => $moneyline['trust'] ?? 90.0, 'reason_codes' => ['market_total_edge_over'], 'bet_rule_evaluation' => ['matched_rules' => [
        ['market' => 'total', 'action' => 'play', 'name' => 'recorded_over', 'label' => 'Recorded over rule'],
    ]]]];
    if ($proLayer !== null) {
        $metadata['analysis_layer']['pro_signal_layer'] = $proLayer;
    }
    Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 99, 'predicted_total' => 888, 'confidence_score' => 55, 'model_metadata' => $metadata]);
    GameOddsSnapshot::create(['sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id, 'captured_at' => $start->copy()->subMinute(),
        'odds_data' => $odds, 'payload_hash' => hash('sha256', json_encode($odds))]);
    $input = EventInputSnapshot::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id, 'phase' => 'pregame', 'captured_at' => $captured, 'cutoff_at' => $start,
        'latest_source_available_at' => $variant === 'future_source' ? $start->copy()->addHour() : $captured, 'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified',
        'inputs' => ['event' => ['starts_at' => match ($variant) {
            'missing_start' => null,'kickoff_mismatch' => $start->copy()->addHour()->toIso8601String(),default => $start->toIso8601String()
        }],
            'recommendation_context' => ['version' => NflRecommendationInputBuilder::VERSION, 'model_version' => $variant === 'wrong_version' ? 'older' : NflModelVersion::current(),
                'outputs' => ['win_probability' => $moneyline['probability'] ?? 0.8, 'predicted_spread' => $variant === 'missing_spread' ? null : 6.5, 'predicted_total' => $variant === 'missing_total' ? null : 49.0, 'confidence_score' => $variant === 'missing_confidence' ? null : 72.0],
                'model_metadata' => $variant === 'missing_rules' ? [] : $metadata],
            'pregame_market' => ['available' => $variant !== 'missing_market', 'consensus' => ['moneyline' => ['market_key' => 'h2h', 'side' => 'home', 'price' => $moneyline['home_price'] ?? -110, 'opposite_quote' => ['market_key' => 'h2h', 'side' => $moneyline['opposite_side'] ?? 'away', 'price' => $moneyline['away_price'] ?? -110]], 'spread' => ['line' => $variant === 'missing_spread_line' ? null : -3.0],
                'total' => ['line' => $variant === 'missing_total_line' ? null : 44.5]]]]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'nfl', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id, 'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id, 'phase' => 'pregame',
        'generated_at' => $generated, 'published_at' => null, 'publication_state' => 'draft', 'output_metadata' => ['analysis_layer' => ['reason_codes' => ['wrong_primary_rule']]]]);
    PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => 'spread', 'selection' => 'home', 'projected_line' => -17, 'confidence_score' => 95]);
    PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => 'total', 'selection' => 'combined', 'projected_line' => 77]);
    if ($variant !== 'draft') {
        $native->update(['publication_state' => 'published', 'published_at' => $variant === 'late_publication' ? $start : $generated]);
    }
    if ($variant === 'withdrawn') {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    return [$game, $native, $input];
}

function nflMarketBacktestRows(string $command): Collection
{
    return (new ReflectionMethod(Artisan::all()[$command], 'canonicalRows'))->invoke(Artisan::all()[$command]);
}

it('uses paired frozen recommendation markets and rules in every canonical switch mode', function (bool $pipeline, bool $reads) {
    [, $native,$input] = nflMarketBacktestFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => $pipeline, 'prediction_lifecycle.canonical_reads.nfl' => $reads]);
    $status = CalculationRelease::pluck('status', 'id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('nfl:backtest-spreads', ['--season' => 2026, '--detailed' => true]))->toBe(0);
        expect(Artisan::output())->toContain('Forecast source: canonical', 'Rows: 1');
        $spreads = nflMarketBacktestRows('nfl:backtest-spreads');
        expect(Artisan::call('nfl:backtest-totals', ['--season' => 2026, '--detailed' => true]))->toBe(0);
        expect(Artisan::output())->toContain('Forecast source: canonical', 'Rows: 1');
        $totals = nflMarketBacktestRows('nfl:backtest-totals');
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($spreads->sole())->toMatchArray(['canonical_prediction_id' => $native->id, 'model_spread' => 6.5, 'market_home_line' => -3.0, 'actual_margin' => 7.0,
        'edge' => 3.5, 'pick' => 'home', 'result' => 'win', 'clv' => null, 'closing_source' => null, 'confidence_score' => 72.0])
        ->and($totals->sole())->toMatchArray(['canonical_prediction_id' => $native->id, 'model_total' => 49.0, 'market_total' => 44.5, 'actual_total' => 47.0,
            'pick' => 'over', 'result' => 'over', 'playable' => true, 'rule_action' => 'play', 'clv' => null, 'closing_total' => null])
        ->and($totals->sole()['canonical_lineage']['event_input_snapshot_id'])->toBe($input->id)
        ->and($queries->implode(' '))->not->toContain('nfl_predictions')->not->toContain('game_odds_snapshots')->not->toContain('odds_data')->not->toContain('prediction_feature_snapshots')
        ->and($queries->filter(fn ($sql) => preg_match('/^(insert|update|delete)/i', $sql)))->toBeEmpty()->and(CalculationRelease::pluck('status', 'id')->all())->toBe($status);
})->with([[true, false], [false, true], [true, true]]);

it('does not fill unsafe or missing native evidence from legacy rows', function (string $variant) {
    nflMarketBacktestFixture($variant);
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    foreach (['nfl:backtest-spreads', 'nfl:backtest-totals'] as $command) {
        $this->artisan($command, ['--season' => 2026])->expectsOutputToContain('No final NFL predictions')->assertSuccessful();
    }
})->with(['unsafe', 'draft', 'withdrawn', 'future_source', 'missing_start', 'kickoff_mismatch', 'late_publication', 'wrong_version', 'missing_market']);

it('keeps native market gaps independent and applies limits after eligibility', function (string $variant, string $usable) {
    [, $older] = nflMarketBacktestFixture(daysAgo: 4);
    [, $newer] = nflMarketBacktestFixture(daysAgo: 3);
    nflMarketBacktestFixture($variant, daysAgo: 2);
    $source = app(NflBacktestForecastSource::class);
    $other = $usable === 'spread' ? 'total' : 'spread';
    expect($source->forMarket($other, season: 2026, limit: 1)->pluck('canonical_prediction_id')->all())->toBe([$newer->id])
        ->and($source->forMarket($other, season: 2026)->pluck('canonical_prediction_id')->all())->toBe([$newer->id, $older->id])
        ->and($source->forMarket($usable, season: 2026))->toHaveCount(3);
})->with([['missing_spread', 'total'], ['missing_spread_line', 'total'], ['missing_total', 'spread'], ['missing_total_line', 'spread']]);

it('does not borrow missing native confidence or total-rule support', function () {
    nflMarketBacktestFixture('missing_confidence');
    Artisan::call('nfl:backtest-spreads', ['--forecast-source' => 'canonical']);
    $rows = nflMarketBacktestRows('nfl:backtest-spreads');
    expect($rows->sole()['confidence_score'])->toBeNull()->and((new ReflectionMethod(Artisan::all()['nfl:backtest-spreads'], 'confidenceBuckets'))->invoke(Artisan::all()['nfl:backtest-spreads'], $rows))->toBeEmpty();
    nflMarketBacktestFixture('missing_rules', daysAgo: 1);
    Artisan::call('nfl:backtest-totals', ['--forecast-source' => 'canonical', '--limit' => 1]);
    expect(nflMarketBacktestRows('nfl:backtest-totals')->sole())->toMatchArray(['rule_support' => null, 'playable' => false]);
});

it('retains historical backtests and pins the readiness replay to legacy inputs', function () {
    nflMarketBacktestFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true, 'prediction_lifecycle.canonical_reads.nfl' => true]);
    foreach (['nfl:backtest-spreads', 'nfl:backtest-totals'] as $command) {
        expect(Artisan::call($command, ['--forecast-source' => 'legacy', '--season' => 2026]))->toBe(0);
        $output = Artisan::output();
        expect($output)->toContain('Forecast source: legacy', 'Rows: 1');
    }
    expect($output)->toContain('888.0', '999.0');
    expect(Artisan::call('nfl:readiness-pass', ['--from-season' => 2026, '--to-season' => 2026, '--skip-backfill' => true, '--skip-analysis' => true,
        '--skip-reason-codes' => true, '--skip-point-audit' => true, '--skip-moneyline' => true, '--skip-sentinel' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Forecast source: legacy')->not->toContain('Forecast source: canonical');
});

it('preserves total play ranges watchlists and direction matching', function () {
    nflMarketBacktestFixture();
    config(['nfl.betting.totals.watchlist_only_rules' => ['recorded_over']]);
    Artisan::call('nfl:backtest-totals', ['--forecast-source' => 'canonical']);
    expect(nflMarketBacktestRows('nfl:backtest-totals')->sole()['playable'])->toBeFalse();
    config(['nfl.betting.totals.watchlist_only_rules' => [], 'nfl.betting.totals.play_min_edge' => 5]);
    Artisan::call('nfl:backtest-totals', ['--forecast-source' => 'canonical']);
    expect(nflMarketBacktestRows('nfl:backtest-totals')->sole()['playable'])->toBeFalse();
    $command = Artisan::all()['nfl:backtest-totals'];
    $metadata = (object) ['model_metadata' => ['analysis_layer' => ['reason_codes' => ['market_total_edge_under']]]];
    $row = (new ReflectionMethod($command, 'totalRow'))->invoke($command, $metadata, Game::firstOrFail(), 49.0, 44.5, null);
    expect($row['rule_support'])->toBeNull()->and($row['playable'])->toBeFalse();
});

it('preserves pushes spread no-pick and totals equality behavior', function () {
    [$game] = nflMarketBacktestFixture();
    $spreads = Artisan::all()['nfl:backtest-spreads'];
    $totals = Artisan::all()['nfl:backtest-totals'];
    $spreadRow = new ReflectionMethod($spreads, 'spreadRow');
    $totalRow = new ReflectionMethod($totals, 'totalRow');
    expect($spreadRow->invoke($spreads, $game, 6.5, -7.0, 72.0, null, 'fixture', null)['result'])->toBe('push')
        ->and($spreadRow->invoke($spreads, $game, 3.0, -3.0, 72.0, null, 'fixture', null)['result'])->toBe('no_pick')
        ->and($totalRow->invoke($totals, (object) ['model_metadata' => []], $game, 49.0, 47.0, null)['result'])->toBe('push')
        ->and($totalRow->invoke($totals, (object) ['model_metadata' => []], $game, 47.0, 47.0, null)['pick'])->toBe('under');
});

it('retains native scope version and invalid-source behavior', function () {
    nflMarketBacktestFixture(season: 2025);
    nflMarketBacktestFixture('wrong_version');
    $source = app(NflBacktestForecastSource::class);
    expect($source->forMarket('total', fromSeason: 2026, toSeason: 2027))->toBeEmpty()->and($source->forMarket('total', season: 2026, version: 'older'))->toHaveCount(1);
    foreach (['nfl:backtest-spreads', 'nfl:backtest-totals'] as $command) {
        $this->artisan($command, ['--forecast-source' => 'invalid'])->assertFailed();
    }
});

it('hydrates native evidence in batches without legacy or mutable odds queries', function () {
    foreach (range(1, 26) as $index) {
        nflMarketBacktestFixture();
    }
    foreach (['nfl:backtest-spreads', 'nfl:backtest-totals'] as $command) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->artisan($command, ['--forecast-source' => 'canonical', '--season' => 2026])->expectsOutputToContain('Rows: 26')->assertSuccessful();
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        expect($queries->filter(fn ($sql) => str_contains($sql, 'as "audit_outputs"')))->toHaveCount(2)
            ->and($queries->implode(' '))->not->toContain('nfl_predictions')->not->toContain('odds_data')->not->toContain('game_odds_snapshots');
    }
});

it('uses frozen moneyline probabilities prices and trust without legacy reads', function (bool $pipeline, bool $reads) {
    [, $native, $input] = nflMarketBacktestFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => $pipeline, 'prediction_lifecycle.canonical_reads.nfl' => $reads]);
    $statuses = CalculationRelease::pluck('status', 'id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('nfl:backtest-moneylines', ['--season' => 2026, '--detailed' => true]))->toBe(0);
        expect(Artisan::output())->toContain('Forecast source: canonical', 'Rows: 1', 'does not grant betting approval');
        $rows = nflMarketBacktestRows('nfl:backtest-moneylines');
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($rows->sole())->toMatchArray(['canonical_prediction_id' => $native->id, 'model_probability' => 80.0, 'odds' => -110.0, 'pick' => 'home', 'won' => true])
        ->and($rows->sole()['profit'])->toEqualWithDelta(100 / 110, 0.00000001)
        ->and($rows->sole()['canonical_lineage']['event_input_snapshot_id'])->toBe($input->id)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'nfl_predictions') || str_contains($sql, 'game_odds_snapshots') || str_contains($sql, 'odds_data')))->toBeEmpty()
        ->and(CalculationRelease::pluck('status', 'id')->all())->toBe($statuses);
})->with([[true, false], [false, true], [true, true]]);

it('excludes unsafe or incomplete native moneyline evidence without fallback', function (string $variant, array $moneyline) {
    nflMarketBacktestFixture($variant, moneyline: $moneyline);
    expect(Artisan::call('nfl:backtest-moneylines', ['--forecast-source' => 'canonical']))->toBe(0);
    expect(nflMarketBacktestRows('nfl:backtest-moneylines'))->toBeEmpty();
})->with([
    ['unsafe', []], ['future_source', []], ['late_publication', []], ['draft', []], ['withdrawn', []], ['wrong_version', []], ['kickoff_mismatch', []], ['missing_market', []],
    ['valid', ['probability' => -0.1]], ['valid', ['probability' => 1.1]], ['valid', ['probability' => 'unknown']],
    ['valid', ['opposite_side' => 'home']], ['valid', ['home_price' => 0]], ['valid', ['away_price' => 'unknown']], ['valid', ['trust' => 84]],
    ['missing_confidence', ['trust' => 'unknown']], ['valid', ['probability' => 0.5]],
]);

it('applies moneyline limits after eligibility and keeps season and version scopes', function () {
    [, $native] = nflMarketBacktestFixture(daysAgo: 3);
    nflMarketBacktestFixture(daysAgo: 2, moneyline: ['probability' => 0.5]);
    nflMarketBacktestFixture(season: 2025, daysAgo: 1);
    Artisan::call('nfl:backtest-moneylines', ['--forecast-source' => 'canonical', '--from-season' => 2026, '--to-season' => 2026, '--limit' => 1]);
    expect(nflMarketBacktestRows('nfl:backtest-moneylines')->sole()['canonical_prediction_id'])->toBe($native->id);
    Artisan::call('nfl:backtest-moneylines', ['--forecast-source' => 'canonical', '--model-version' => 'absent']);
    expect(nflMarketBacktestRows('nfl:backtest-moneylines'))->toBeEmpty();
    $this->artisan('nfl:backtest-moneylines', ['--forecast-source' => 'invalid'])->assertFailed();
});

it('preserves historical moneyline prices scoring and readiness source', function () {
    [$game] = nflMarketBacktestFixture();
    $home = $game->homeTeam;
    $away = $game->awayTeam;
    $home->update(['location' => 'Home', 'name' => 'Home Team']);
    $away->update(['location' => 'Away', 'name' => 'Away Team']);
    $game->update(['odds_data' => ['bookmakers' => [['markets' => [['key' => 'h2h', 'outcomes' => [['name' => 'Home Team', 'price' => 150], ['name' => 'Away Team', 'price' => -170]]]]]]]]);
    Prediction::where('game_id', $game->id)->update(['win_probability' => 0.8, 'confidence_score' => 90]);
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    Artisan::call('nfl:backtest-moneylines', ['--forecast-source' => 'legacy', '--season' => 2026, '--detailed' => true]);
    expect(Artisan::output())->toContain('Forecast source: legacy', 'Rows: 1', '+150', '+1.50');
    expect(Artisan::call('nfl:readiness-pass', ['--from-season' => 2026, '--to-season' => 2026, '--skip-backfill' => true, '--skip-analysis' => true,
        '--skip-reason-codes' => true, '--skip-point-audit' => true, '--skip-sentinel' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Forecast source: legacy')->not->toContain('Forecast source: canonical');
});

it('keeps moneyline away payouts losses and tie behavior in shared scoring', function () {
    [$game] = nflMarketBacktestFixture();
    $command = Artisan::all()['nfl:backtest-moneylines'];
    $score = new ReflectionMethod($command, 'moneylineRow');
    $prices = ['home' => -170.0, 'away' => 150.0];
    expect($score->invoke($command, $game, 0.1, $prices, 90.0))->toMatchArray(['pick' => 'away', 'won' => false, 'profit' => -1.0]);
    $game->away_score = 30;
    expect($score->invoke($command, $game, 0.1, $prices, 90.0))->toMatchArray(['won' => true, 'profit' => 1.5]);
    $game->away_score = $game->home_score;
    expect($score->invoke($command, $game, 0.1, $prices, 90.0))->toMatchArray(['won' => false, 'profit' => -1.0]);
});

it('bounds moneyline snapshot hydration and preserves complete native results', function () {
    for ($i = 0; $i < 26; $i++) {
        nflMarketBacktestFixture(daysAgo: $i + 1);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        Artisan::call('nfl:backtest-moneylines', ['--forecast-source' => 'canonical', '--season' => 2026]);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(nflMarketBacktestRows('nfl:backtest-moneylines'))->toHaveCount(26);
    $snapshots = $queries->filter(fn ($sql) => str_contains($sql, 'as "audit_outputs"'));
    expect($snapshots->count())->toBe(2);
    foreach ($snapshots as $sql) {
        preg_match('/in \(([^)]+)\)/', $sql, $matches);
        expect(count(explode(',', $matches[1])))->toBeLessThanOrEqual(25);
    }
});

it('preserves explicit legacy moneyline snapshot fallback and default compatibility', function () {
    [$game] = nflMarketBacktestFixture();
    $game->homeTeam->update(['location' => 'Home', 'name' => 'Home Team']);
    $game->awayTeam->update(['location' => 'Away', 'name' => 'Away Team']);
    $odds = ['bookmakers' => [['markets' => [['key' => 'h2h', 'outcomes' => [['name' => 'Home Team', 'price' => -110], ['name' => 'Away Team', 'price' => -110]]]]]]];
    GameOddsSnapshot::create(['sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id, 'captured_at' => now(), 'odds_data' => $odds, 'payload_hash' => hash('sha256', json_encode($odds))]);
    Prediction::where('game_id', $game->id)->update(['win_probability' => 0.8, 'confidence_score' => 90]);
    Artisan::call('nfl:backtest-moneylines', ['--season' => 2026]);
    $output = Artisan::output();
    expect($output)->toContain('Forecast source: legacy', 'Rows: 1');
    Artisan::call('nfl:backtest-moneylines', ['--forecast-source' => 'legacy', '--season' => 2026]);
    expect(Artisan::output())->toBe($output);
});

it('reports accuracy from frozen recommendation evidence in each canonical switch mode', function (bool $pipeline, bool $reads) {
    [, $native, $input] = nflMarketBacktestFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => $pipeline, 'prediction_lifecycle.canonical_reads.nfl' => $reads]);
    $status = CalculationRelease::pluck('status', 'id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('nfl:analyze-predictions', ['--season' => 2026, '--detailed' => true]))->toBe(0);
        $output = Artisan::output();
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($output)->toContain('Forecast source: canonical', 'Analyzing 1 predictions', 'HOM vs AWY', '0.04', '0.2231', '0.5', 'does not grant calibration or betting approval');
    $forecasts = app(NflBacktestForecastSource::class)->forAccuracy(season: 2026);
    expect($forecasts->sole()->canonical_prediction_id)->toBe($native->id)
        ->and($forecasts->sole()->lineage['event_input_snapshot_id'])->toBe($input->id)
        ->and($forecasts->sole()->win_probability)->toBe(0.8)
        ->and($forecasts->sole()->predicted_spread)->toBe(6.5)
        ->and($queries->implode(' '))->not->toContain('nfl_predictions', 'odds_data', 'prediction_feature_snapshots', 'game_odds_snapshots')
        ->and(CalculationRelease::pluck('status', 'id')->all())->toBe($status);
})->with([[true, false], [false, true], [true, true]]);

it('excludes missing or unsafe accuracy evidence without legacy fallback', function (string $variant, array $moneyline) {
    nflMarketBacktestFixture($variant, moneyline: $moneyline);
    Artisan::call('nfl:analyze-predictions', ['--forecast-source' => 'canonical', '--season' => 2026]);
    expect(Artisan::output())->toContain('No predictions found');
    expect(app(NflBacktestForecastSource::class)->forAccuracy(season: 2026))->toBeEmpty();
})->with([
    ['unsafe', []], ['future_source', []], ['draft', []], ['withdrawn', []], ['late_publication', []], ['wrong_version', []], ['missing_start', []], ['kickoff_mismatch', []],
    ['missing_spread', []], ['valid', ['probability' => 'unknown']], ['valid', ['probability' => 1.1]], ['valid', ['probability' => -0.1]],
]);

it('does not require market quotes or trust to measure native forecast accuracy', function () {
    nflMarketBacktestFixture('missing_market', moneyline: ['trust' => 'unknown']);
    Artisan::call('nfl:analyze-predictions', ['--forecast-source' => 'canonical', '--season' => 2026, '--detailed' => true]);
    expect(Artisan::output())->toContain('Analyzing 1 predictions', 'unknown', 'n/a');
    $command = Artisan::all()['nfl:analyze-predictions'];
    $forecast = app(NflBacktestForecastSource::class)->forAccuracy(season: 2026)->sole();
    $summary = (new ReflectionMethod($command, 'analysisSummaryRow'))->invoke($command, 'test', collect([$forecast]));
    expect($summary)->toMatchArray(['Avg Trust' => 'n/a', 'Avg Spread Edge' => 'n/a', 'Avg Total Edge' => 'n/a'])
        ->and((new ReflectionMethod($command, 'modelSignalClassificationForPrediction'))->invoke($command, $forecast))->toBe('unknown');
});

it('retains explicit historical accuracy output and scopes canonical seasons and versions', function () {
    nflMarketBacktestFixture();
    Artisan::call('nfl:analyze-predictions', ['--season' => 2026, '--detailed' => true]);
    $historical = Artisan::output();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    Artisan::call('nfl:analyze-predictions', ['--forecast-source' => 'legacy', '--season' => 2026, '--detailed' => true]);
    expect(Artisan::output())->toBe($historical);
    nflMarketBacktestFixture(season: 2025);
    Artisan::call('nfl:analyze-predictions', ['--from-season' => 2025, '--to-season' => 2026]);
    expect(Artisan::output())->toContain('Analyzing 2 predictions', 'seasons 2025-2026');
    Artisan::call('nfl:analyze-predictions', ['--season' => 2026, '--model-version' => 'absent']);
    expect(Artisan::output())->toContain('No predictions found');
    $this->artisan('nfl:analyze-predictions', ['--forecast-source' => 'invalid'])->assertFailed();
    $this->artisan('nfl:analyze-predictions', ['--season' => 2026, '--from-season' => 2025])->assertFailed();
    $this->artisan('nfl:analyze-predictions', ['--from-season' => 2026, '--to-season' => 2025])->assertFailed();
});

it('bounds native accuracy input hydration and retains probability and tie scoring', function () {
    foreach (range(1, 26) as $index) {
        nflMarketBacktestFixture();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        Artisan::call('nfl:analyze-predictions', ['--forecast-source' => 'canonical', '--season' => 2026]);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(Artisan::output())->toContain('Analyzing 26 predictions')
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'as "audit_outputs"')))->toHaveCount(2);
    $command = Artisan::all()['nfl:analyze-predictions'];
    $forecast = app(NflBacktestForecastSource::class)->forAccuracy(season: 2026)->first();
    $forecast->win_probability = 0.5;
    $forecast->game->home_score = $forecast->game->away_score;
    $metrics = (new ReflectionMethod($command, 'calculateMetrics'))->invoke($command, collect([$forecast]));
    expect($metrics)->toMatchArray(['total' => 1, 'correct' => 1, 'accuracy' => 100.0, 'brier_score' => 0.25, 'log_loss' => 0.6931, 'mean_absolute_spread_error' => 6.5]);
});

it('pins historical readiness accuracy analysis despite canonical cutover', function () {
    nflMarketBacktestFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true, 'prediction_lifecycle.canonical_reads.nfl' => true]);
    expect(Artisan::call('nfl:readiness-pass', ['--from-season' => 2026, '--to-season' => 2026, '--skip-backfill' => true,
        '--skip-reason-codes' => true, '--skip-point-audit' => true, '--skip-sentinel' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Forecast source: legacy', 'Analyzing 1 predictions')->not->toContain('Forecast source: canonical');
});

function nflReportProLayer(): array
{
    return ['score' => 80.0, 'tier' => 'candidate', 'market_scores' => ['winner' => ['tier' => 'winner-tier'], 'spread' => ['tier' => 'spread-tier'], 'total' => ['tier' => 'total-tier']],
        'market_context' => ['market_spread' => 3.0, 'market_total' => 44.5, 'total_edge' => 4.5, 'pick_side' => 'home'], 'reason_codes' => ['frozen_pro_reason']];
}

function nflReportProRow(object $forecast): ?array
{
    return (new ReflectionMethod(Artisan::all()['nfl:report-pro-signals'], 'row'))->invoke(Artisan::all()['nfl:report-pro-signals'], $forecast, true);
}

it('reports frozen pro layers with preserved grading and no mutable inputs', function (bool $pipeline, bool $reads) {
    [, $native, $input] = nflMarketBacktestFixture(proLayer: nflReportProLayer());
    config(['prediction_lifecycle.canonical_pipeline.nfl' => $pipeline, 'prediction_lifecycle.canonical_reads.nfl' => $reads]);
    $statuses = CalculationRelease::pluck('status', 'id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('nfl:report-pro-signals', ['--from-season' => 2026, '--to-season' => 2026, '--min-sample' => 1]))->toBe(0);
        $output = Artisan::output();
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($output)->toContain('Forecast source: canonical', 'Rows: 1', 'frozen_pro_reason', 'winner-tier', 'spread-tier', 'total-tier', '0.040', 'n/a');
    $forecast = app(NflBacktestForecastSource::class)->forProSignals(fromSeason: 2026, toSeason: 2026)->sole();
    expect(nflReportProRow($forecast))->toMatchArray(['canonical_prediction_id' => $native->id, 'result' => 'win', 'ou_result' => 'win', 'winner_correct' => true, 'roi' => 0.9091, 'clv' => null])
        ->and($forecast->lineage['event_input_snapshot_id'])->toBe($input->id)
        ->and($queries->implode(' '))->not->toContain('nfl_predictions', 'odds_data', 'prediction_feature_snapshots', 'game_odds_snapshots')
        ->and(CalculationRelease::pluck('status', 'id')->all())->toBe($statuses);
})->with([[true, false], [false, true], [true, true]]);

it('excludes unsafe native pro evidence without legacy fallback', function (string $variant) {
    nflMarketBacktestFixture($variant, proLayer: nflReportProLayer());
    Artisan::call('nfl:report-pro-signals', ['--forecast-source' => 'canonical']);
    expect(Artisan::output())->toContain('No completed NFL predictions with stored pro signal layers');
})->with(['unsafe', 'future_source', 'late_publication', 'draft', 'withdrawn', 'wrong_version', 'missing_start', 'kickoff_mismatch']);

it('keeps missing pro score and optional totals unknown and reports missing market distinctly', function () {
    $layer = nflReportProLayer();
    unset($layer['score'], $layer['market_context']);
    nflMarketBacktestFixture('missing_total', proLayer: $layer);
    $forecast = app(NflBacktestForecastSource::class)->forProSignals()->sole();
    expect(nflReportProRow($forecast))->toMatchArray(['brier' => null, 'clv' => null, 'ou_result' => null]);
    $forecast->market_context = [];
    expect(nflReportProRow($forecast))->toBeNull();
    $forecast->model_metadata = [];
    expect(nflReportProRow($forecast))->toBeNull();
    nflMarketBacktestFixture('missing_market', daysAgo: 1, proLayer: $layer);
    Artisan::call('nfl:report-pro-signals', ['--forecast-source' => 'canonical', '--from-season' => 2027]);
    expect(Artisan::output())->toContain('No completed NFL predictions');
    Artisan::call('nfl:report-pro-signals', ['--forecast-source' => 'canonical', '--model-version' => 'absent']);
    expect(Artisan::output())->toContain('No completed NFL predictions');
    $this->artisan('nfl:report-pro-signals', ['--forecast-source' => 'invalid'])->assertFailed();
});

it('preserves pro report historical output and avoids duplicate empty-report reads', function () {
    nflMarketBacktestFixture(proLayer: nflReportProLayer());
    Artisan::call('nfl:report-pro-signals', ['--min-sample' => 1]);
    $legacy = Artisan::output();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    Artisan::call('nfl:report-pro-signals', ['--forecast-source' => 'legacy', '--min-sample' => 1]);
    expect(Artisan::output())->toBe($legacy);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        Artisan::call('nfl:report-pro-signals', ['--forecast-source' => 'legacy', '--from-season' => 2027]);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "nfl_predictions"')))->toHaveCount(1);
});

it('preserves pro signal pushes away grading and threshold behavior', function () {
    nflMarketBacktestFixture(proLayer: nflReportProLayer());
    $f = app(NflBacktestForecastSource::class)->forProSignals()->sole();
    $f->game->home_score = 23;
    expect(nflReportProRow($f))->toMatchArray(['result' => 'push', 'roi' => 0.0]);
    $f->model_metadata['analysis_layer']['pro_signal_layer']['market_context']['pick_side'] = 'away';
    $f->game->home_score = 17;
    expect(nflReportProRow($f))->toMatchArray(['result' => 'win', 'winner_correct' => true]);
    $f->game->home_score = 20;
    expect(nflReportProRow($f))->toMatchArray(['winner_push' => true]);
    $f->model_metadata['analysis_layer']['pro_signal_layer']['market_context']['total_edge'] = 2.9;
    expect(nflReportProRow($f)['ou_result'])->toBeNull();
});

it('bounds pro report hydration and retains missing-market warnings', function () {
    $layer = nflReportProLayer();
    unset($layer['market_context']);
    nflMarketBacktestFixture('missing_market', proLayer: $layer);
    Artisan::call('nfl:report-pro-signals', ['--forecast-source' => 'canonical']);
    expect(Artisan::output())->toContain('Found 1 completed NFL prediction(s) with pro signal layers, but none had usable spread market data.');
    for ($i = 0; $i < 25; $i++) {
        nflMarketBacktestFixture(proLayer: nflReportProLayer());
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        Artisan::call('nfl:report-pro-signals', ['--forecast-source' => 'canonical', '--min-sample' => 26]);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(Artisan::output())->toContain('Rows: 25')->not->toContain('frozen_pro_reason');
    $snapshots = $queries->filter(fn ($sql) => str_contains($sql, 'as "audit_outputs"'));
    expect($snapshots)->toHaveCount(2);
    foreach ($snapshots as $sql) {
        preg_match('/in \(([^)]+)\)/', $sql, $matches);
        expect(count(explode(',', $matches[1])))->toBeLessThanOrEqual(25);
    }
});
