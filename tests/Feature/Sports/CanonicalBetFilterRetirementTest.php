<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\BetFilterResult;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use App\Services\MLB\MlbBettingSignalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-29 12:00:00');
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function betFilterRetirementFixture(string $sport, ?CalculationRelease $release = null): array
{
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $context = app(SportContextResolver::class)->resolve($sport);
    $teamClass = $context->models['team'];
    $gameClass = $context->models['game'];
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => now()->addDay()]);
    $game = $gameClass::factory()->create(['season' => 2026, 'season_type' => '2', 'sport_event_id' => $event->id,
        'status' => 'STATUS_SCHEDULED', 'game_date' => $event->starts_at->toDateString(), 'game_time' => $event->starts_at->format('H:i:s'),
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory()]);
    $release ??= CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $input = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => 'verified', 'captured_at' => now(), 'cutoff_at' => now(),
        'inputs' => ['pregame_market' => ['available' => true, 'captured_at' => now()->toIso8601String(),
            'consensus' => ['spread' => ['line' => -2.5], 'total' => ['line' => 8.0]]], 'home' => ['elo' => 1550], 'away' => ['elo' => 1490],
            'pitching' => ['home' => ['elo' => 1600, 'espn_id' => '100'], 'away' => ['elo' => 1450, 'espn_id' => '200']]]]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'publication_state' => 'draft', 'phase' => 'pregame',
        'generated_at' => now(), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -4.0, null], ['total', 'combined', 9.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $native->refresh(), $input];
}

function betFilterLegacyPrediction(Game $game): Prediction
{
    return Prediction::create(['game_id' => $game->id, 'season' => 2026, 'season_type' => '2',
        'home_team_elo' => 1500, 'away_team_elo' => 1500, 'predicted_spread' => -20, 'predicted_total' => 200,
        'win_probability' => 0.3, 'vegas_spread' => -100]);
}

function betFilterSnapshotOptions(Game $game): array
{
    return ['--snapshot' => true, '--season' => 2026, '--date' => $game->game_date->toDateString(),
        '--as-of-date' => now()->toDateString(), '--include-disabled-markets' => true];
}

it('snapshots native MLB observations without legacy reads or id collisions', function () {
    [$game, $native, $input] = betFilterRetirementFixture('mlb');
    $legacy = betFilterLegacyPrediction($game);
    expect($legacy->id)->toBe($native->id);
    $historical = BetFilterResult::create(['game_id' => $game->id, 'prediction_id' => $legacy->id,
        'season' => 2026, 'as_of_date' => now()->toDateString(), 'market' => 'moneyline', 'pick_side' => 'home',
        'score' => 70, 'classification' => 'lean']);
    $before = $native->getRawOriginal();
    DB::enableQueryLog();
    try {
        $this->artisan('mlb:snapshot-bet-filter', betFilterSnapshotOptions($game))->assertSuccessful();
        $this->artisan('mlb:snapshot-bet-filter', betFilterSnapshotOptions($game))->assertSuccessful();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    $rows = BetFilterResult::where('canonical_prediction_id', $native->id)->get()->keyBy('market');
    expect($rows)->toHaveCount(3)
        ->and(BetFilterResult::count())->toBe(4)
        ->and($queries)->not->toContain('"mlb_predictions"')
        ->and($historical->fresh()->classification)->toBe('lean')
        ->and((float) $rows['run_line']->market_line)->toBe(2.5)
        ->and((float) $rows['total']->market_line)->toBe(8.0)
        ->and((float) $rows['total']->model_line)->toBe(9.0)
        ->and($native->fresh()->getRawOriginal())->toBe($before);
    foreach ($rows as $row) {
        expect($row->prediction_id)->toBeNull()->and($row->classification)->toBe('pass')
            ->and($row->risk_flags)->toContain('canonical_approval_required')
            ->and($row->metadata['is_tracking_only'])->toBeTrue()
            ->and($row->metadata['canonical_lineage']['event_input_snapshot_id'])->toBe($input->id);
    }
    $migration = require database_path('migrations/2026_10_29_153220_add_canonical_prediction_to_mlb_bet_filter_results.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Preserve native bet-filter lineage');
    $this->artisan('mlb:snapshot-bet-filter', ['--season' => 2026, '--report' => true])
        ->expectsOutputToContain('canonical')->expectsOutputToContain('legacy')->assertSuccessful();
});

it('grades native frozen lines after withdrawal while retaining legacy grading behavior', function () {
    [$game, $native] = betFilterRetirementFixture('mlb');
    $legacy = betFilterLegacyPrediction($game);
    $historical = BetFilterResult::create(['game_id' => $game->id, 'prediction_id' => $legacy->id,
        'season' => 2026, 'as_of_date' => now()->toDateString(), 'market' => 'run_line', 'pick_side' => 'home',
        'market_line' => 2.5, 'score' => 70, 'classification' => 'lean']);
    $this->artisan('mlb:snapshot-bet-filter', betFilterSnapshotOptions($game))->assertSuccessful();
    BetFilterResult::where('canonical_prediction_id', $native->id)->where('market', 'moneyline')->sole()->update(['market_price' => -110]);
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 8, 'away_score' => 4,
        'odds_data' => ['bookmakers' => [['markets' => [
            ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 100]]],
            ['key' => 'h2h', 'outcomes' => [['name' => $game->homeTeam->name, 'price' => -125]]],
        ]]]]]);
    $this->artisan('mlb:snapshot-bet-filter', ['--grade' => true, '--season' => 2026])->assertSuccessful();
    foreach (BetFilterResult::where('canonical_prediction_id', $native->id)->get() as $row) {
        expect($row->graded_at)->not->toBeNull()->and($row->result_hit)->toBeTrue();
    }
    expect($historical->fresh()->result_hit)->toBeFalse()
        ->and((float) BetFilterResult::where('canonical_prediction_id', $native->id)->where('market', 'moneyline')->sole()->clv)->toBe(0.032);
    $this->artisan('mlb:snapshot-bet-filter', ['--grade' => true, '--season' => 2026])
        ->expectsOutputToContain('Graded 0')->assertSuccessful();
});

it('does not fall back to legacy forecasts after canonical withdrawal', function () {
    [$game, $native] = betFilterRetirementFixture('mlb');
    betFilterLegacyPrediction($game);
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    $this->artisan('mlb:snapshot-bet-filter', betFilterSnapshotOptions($game))->assertSuccessful();
    expect(BetFilterResult::count())->toBe(0);
});

it('rechecks canonical selection before storing observations', function () {
    [$game, $native] = betFilterRetirementFixture('mlb');
    $this->mock(MlbBettingSignalService::class)->shouldReceive('betCandidatesForPrediction')->once()->andReturnUsing(function () use ($native) {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);

        return [['type' => 'moneyline', 'pick_side' => 'home', 'score' => 70, 'classification' => 'pass']];
    });
    $this->artisan('mlb:snapshot-bet-filter', betFilterSnapshotOptions($game))->assertSuccessful();
    expect(BetFilterResult::count())->toBe(0);
});

it('keeps snapshotting legacy observations when canonical reads are disabled', function () {
    [$game] = betFilterRetirementFixture('mlb');
    config(['prediction_lifecycle.canonical_reads.mlb' => false]);
    $legacy = betFilterLegacyPrediction($game);
    $this->artisan('mlb:snapshot-bet-filter', betFilterSnapshotOptions($game))->assertSuccessful();
    $count = BetFilterResult::count();
    $this->artisan('mlb:snapshot-bet-filter', betFilterSnapshotOptions($game))->assertSuccessful();
    expect(BetFilterResult::count())->toBe($count);
    expect(BetFilterResult::where('prediction_id', $legacy->id)->count())->toBeGreaterThan(0)
        ->and(BetFilterResult::whereNotNull('canonical_prediction_id')->count())->toBe(0);
});

it('retains market enablement and refuses new native observations after kickoff', function () {
    [$game, $native] = betFilterRetirementFixture('mlb');
    config(['mlb.signals.bet_filter.moneyline_enabled' => false,
        'mlb.signals.bet_filter.run_line_enabled' => false, 'mlb.signals.bet_filter.total_enabled' => true]);
    $options = betFilterSnapshotOptions($game);
    unset($options['--include-disabled-markets']);
    $this->artisan('mlb:snapshot-bet-filter', $options)->assertSuccessful();
    expect(BetFilterResult::all()->pluck('market')->all())->toBe(['total']);
    $this->travelTo($native->sportEvent->starts_at->copy()->addMinute());
    $this->artisan('mlb:snapshot-bet-filter', [...$options, '--as-of-date' => now()->toDateString()])->assertSuccessful();
    expect(BetFilterResult::count())->toBe(1);
});
