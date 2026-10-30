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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-30 10:00:00');
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true, 'prediction_lifecycle.canonical_reads.mlb' => false]);
});
afterEach(fn () => $this->travelBack());

function calculationAuditRetirementFixture(string $variant = 'valid'): array
{
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'season' => 2026, 'starts_at' => now()->addDay()]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'season_type' => 2,
        'status' => 'STATUS_SCHEDULED', 'game_date' => now()->addDay()->toDateString(), 'game_time' => '17:00:00',
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $start = $event->refresh()->starts_at;
    $legacy = Prediction::query()->create(['game_id' => $game->id, 'predicted_spread' => -8, 'predicted_total' => 18,
        'win_probability' => 0.1, 'confidence_score' => 90, 'model_version' => 'historical', 'feature_version' => 'historical', 'blend_version' => 'historical']);
    $input = EventInputSnapshot::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id,
        'captured_at' => now()->subMinutes(10), 'cutoff_at' => now()->subMinutes(10),
        'latest_source_available_at' => now()->subMinutes(12), 'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified',
        'inputs' => ['event' => ['starts_at' => ($variant === 'kickoff_mismatch' ? $start->copy()->addHour() : $start)->toIso8601String()],
            'home' => ['elo' => 1510, 'metrics' => ['runs_per_game' => 4.8]], 'away' => ['elo' => 1480],
            'pitching' => ['home' => ['elo' => 1600], 'away' => ['elo' => 1500]],
            'weather' => ['temperature_f' => 77, 'observed_at' => now()->subHour()->toIso8601String()],
            'pregame_market' => ['available' => true, 'consensus' => ['spread' => ['line' => -1.5, 'captured_at' => now()->subMinutes(15)->toIso8601String()]]]]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'mlb', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded', 'diagnostics' => ['stored_adjustment' => 0.4]]);
    $native = CanonicalPrediction::query()->create(['sport' => 'mlb', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'generated_at' => now()->subMinutes(5), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.65], ['moneyline', 'away', null, $variant === 'probability_sum' ? 0.4 : 0.35],
        ['spread', 'home', -2, null], ['total', 'combined', $variant === 'negative_score' ? 1 : 9, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability, 'confidence_score' => null]);
    }
    if ($variant !== 'draft') {
        $native->update(['publication_state' => 'published', 'published_at' => now()->subMinutes(5)]);
    }
    if ($variant === 'withdrawn') {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    return [$game, $native, $legacy];
}

it('explains canonical outputs and frozen inputs without reading colliding legacy models', function (bool $pipeline, bool $reads) {
    [$game, $native, $legacy] = calculationAuditRetirementFixture();
    config(['prediction_lifecycle.canonical_pipeline.mlb' => $pipeline, 'prediction_lifecycle.canonical_reads.mlb' => $reads]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $exit = Artisan::call('mlb:explain-prediction', ['game_id' => $game->id, '--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($native->id)->toBe($legacy->id)->and($exit)->toBe(0)->and($report['forecast_source'])->toBe('canonical')
        ->and($report['prediction_public_id'])->toBe($native->public_id)
        ->and($report['canonical_lineage']['event_input_snapshot_id'])->toBe($native->calculationRun->event_input_snapshot_id)
        ->and($report['outputs'])->toMatchArray(['predicted_spread' => 2, 'predicted_total' => 9, 'home_win_probability' => 0.65,
            'away_win_probability' => 0.35, 'confidence' => null, 'confidence_label' => 'unknown'])
        ->and($report['inputs']['weather']['temperature_f'])->toBe(77)
        ->and($report['inputs']['team_elo']['home'])->toBe(1510)
        ->and($report['safety'])->toMatchArray(['point_in_time_safe' => true, 'hard_failures' => [], 'calibrated' => false, 'bet_eligible' => false])
        ->and($sql)->not->toContain('mlb_predictions')->not->toContain('prediction_feature_snapshots')->not->toContain('mlb_game_weather')
        ->and(BetDecision::count())->toBe(0);
    Artisan::call('mlb:explain-prediction', ['game_id' => $game->id, '--json' => true, '--forecast-source' => 'legacy']);
    $old = json_decode(Artisan::output(), true);
    expect($old['prediction_id'])->toBe($legacy->id)->and($old['outputs']['predicted_spread'])->toBe(-8);
})->with(['pipeline' => [true, false], 'reads' => [false, true], 'both' => [true, true]]);

it('does not fall back to legacy forecasts when selected native evidence is absent', function (string $variant) {
    [$game] = calculationAuditRetirementFixture($variant);
    $this->artisan('mlb:explain-prediction', ['game_id' => $game->id, '--json' => true])->assertFailed();
    Artisan::call('mlb:audit-prediction-calculations', ['--season' => 2026, '--json' => true]);
    $report = json_decode(Artisan::output(), true);
    expect($report['forecast_source'])->toBe('canonical')->and($report['summary']['predictions_scanned'])->toBe(0);
})->with(['draft', 'withdrawn', 'unsafe']);

it('keeps canonical hard invariants and kickoff diagnostics', function (string $variant, string $reason) {
    [$game] = calculationAuditRetirementFixture($variant);
    $exit = Artisan::call('mlb:explain-prediction', ['game_id' => $game->id, '--json' => true]);
    $report = json_decode(Artisan::output(), true);
    if ($variant === 'kickoff_mismatch') {
        expect($report['safety']['point_in_time_safe'])->toBeFalse()->and($report['safety']['warnings'])->toContain($reason);
    } else {
        expect($exit)->toBe(0)->and($report['safety']['hard_failures'])->toContain($reason);
        $this->artisan('mlb:explain-prediction', ['game_id' => $game->id])->assertFailed();
        expect(Artisan::call('mlb:audit-prediction-calculations', ['--season' => 2026, '--json' => true]))->toBe(1);
    }
})->with(['probability_sum' => ['probability_sum', 'probabilities_do_not_sum_to_one'],
    'negative_score' => ['negative_score', 'derived_team_score_would_be_negative'],
    'kickoff' => ['kickoff_mismatch', 'missing_canonical_input_evidence']]);

it('validates diagnostic source overrides', function () {
    $this->artisan('mlb:explain-prediction', ['game_id' => 1, '--forecast-source' => 'invalid'])->assertExitCode(2);
    $this->artisan('mlb:audit-prediction-calculations', ['--forecast-source' => 'invalid'])->assertExitCode(2);
});

it('streams canonical audits in bounded batches and preserves diagnostic filters', function () {
    for ($index = 0; $index < 26; $index++) {
        calculationAuditRetirementFixture();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $exit = Artisan::call('mlb:audit-prediction-calculations', ['--season' => 2026, '--json' => true]);
        $report = json_decode(Artisan::output(), true);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($exit)->toBe(0)->and($report['summary']['predictions_scanned'])->toBe(26)
        ->and($report['forecast_source'])->toBe('canonical')
        ->and($queries->implode(' '))->not->toContain('mlb_predictions')->not->toContain('prediction_feature_snapshots');
    $batches = $queries->filter(fn ($sql) => str_starts_with($sql, 'select "predictions".*') && str_contains($sql, 'limit 25'));
    expect($batches->count())->toBeGreaterThanOrEqual(2);
    Artisan::call('mlb:audit-prediction-calculations', ['--season' => 2025, '--json' => true]);
    expect(json_decode(Artisan::output(), true)['summary']['predictions_scanned'])->toBe(0);
});

it('marks forecasts unavailable before their generation and publication time', function () {
    [$game] = calculationAuditRetirementFixture();
    Artisan::call('mlb:explain-prediction', ['game_id' => $game->id, '--json' => true, '--as-of' => now()->subHour()->toIso8601String()]);
    $report = json_decode(Artisan::output(), true);
    expect($report['safety']['point_in_time_safe'])->toBeFalse()
        ->and($report['safety']['warnings'])->toContain('missing_canonical_input_evidence');
});

it('preserves native canceled and incomplete-final exclusions', function (string $status, string $counter) {
    [$game] = calculationAuditRetirementFixture();
    $game->forceFill(['status' => $status, 'home_score' => null, 'away_score' => null])->saveQuietly();
    expect(Artisan::call('mlb:audit-prediction-calculations', ['--season' => 2026, '--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true);
    expect($report['summary'][$counter])->toBe(1)->and($report['summary']['predictions_scanned'])->toBe(1);
})->with([
    'postponed' => ['STATUS_POSTPONED', 'games_excluded_due_postponed_or_suspended_status'],
    'suspended' => ['STATUS_SUSPENDED', 'games_excluded_due_postponed_or_suspended_status'],
    'incomplete final' => ['STATUS_FINAL', 'games_excluded_due_missing_final_scores'],
]);
