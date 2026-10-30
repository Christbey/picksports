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
use App\Support\NflModelVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses()->group('nfl', 'commands');

it('audits point projections without loading model layers by default', function () {
    $home = Team::factory()->create(['abbreviation' => 'SEA']);
    $away = Team::factory()->create(['abbreviation' => 'NE']);
    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'game_date' => '2026-09-08',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 27,
        'away_score' => 20,
        'odds_data' => [
            'home_team' => 'Seattle Seahawks',
            'bookmakers' => [[
                'markets' => [
                    ['key' => 'spreads', 'outcomes' => [['name' => 'Seattle Seahawks', 'point' => -3.5]]],
                    ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 44.5]]],
                ],
            ]],
        ],
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 4.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.62,
        'model_metadata' => [
            'true_epa' => ['applied' => true],
            'large_unused_payload' => array_fill(0, 1000, 'not-needed-without-layers'),
        ],
    ]);

    $this->artisan('nfl:analyze-point-projections', ['--season' => 2026])
        ->expectsOutputToContain('NFL Point Projection Audit')
        ->expectsOutputToContain('Rows: 1')
        ->expectsOutputToContain('Model vs Market')
        ->doesntExpectOutputToContain('By Active Model Layer')
        ->assertSuccessful();
});

it('loads model metadata when the point projection layer audit is requested', function () {
    $home = Team::factory()->create(['abbreviation' => 'SEA']);
    $away = Team::factory()->create(['abbreviation' => 'NE']);
    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'game_date' => '2026-09-08',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 27,
        'away_score' => 20,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 4.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.62,
        'model_metadata' => ['true_epa' => ['applied' => true]],
    ]);

    $this->artisan('nfl:analyze-point-projections', [
        '--season' => 2026,
        '--layers' => true,
    ])
        ->expectsOutputToContain('By Active Model Layer')
        ->expectsOutputToContain('true_epa')
        ->assertSuccessful();
});

it('keeps the prediction accuracy audit on its minimal game projection', function () {
    $home = Team::factory()->create(['abbreviation' => 'SEA']);
    $away = Team::factory()->create(['abbreviation' => 'NE']);
    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'game_date' => '2026-09-08',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 27,
        'away_score' => 20,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 4.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.62,
        'model_metadata' => [
            'analysis_layer' => [
                'bet_classification' => 'bet',
                'trust_score' => 80,
                'reason_codes' => ['test_signal'],
            ],
        ],
    ]);

    $this->artisan('nfl:analyze-predictions', [
        '--season' => 2026,
        '--detailed' => true,
    ])
        ->expectsOutputToContain('Total Predictions')
        ->expectsOutputToContain('Analysis Layer Breakdown')
        ->expectsOutputToContain('SEA vs NE')
        ->assertSuccessful();
});

function pointAuditCanonicalFixture(string $variant = 'valid', int $season = 2026, int $daysAgo = 1): array
{
    $start = now()->subDays($daysAgo);
    $generated = $start->copy()->subHours(2);
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'season' => $season, 'starts_at' => $start]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => $season,
        'status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 17,
        'home_team_id' => (Team::where('abbreviation', 'SEA')->first() ?? Team::factory()->create(['abbreviation' => 'SEA']))->id, 'away_team_id' => (Team::where('abbreviation', 'NE')->first() ?? Team::factory()->create(['abbreviation' => 'NE']))->id, 'game_date' => $start->toDateString(), 'week' => 1]);
    Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => -14, 'predicted_total' => 70, 'model_metadata' => ['analysis_layer' => ['reason_codes' => ['legacy_only']]]]);
    $input = EventInputSnapshot::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified',
        'captured_at' => $generated->copy()->subHour(), 'cutoff_at' => $generated->copy()->subHour(),
        'latest_source_available_at' => $generated->copy()->subHour(),
        'inputs' => ['event' => ['starts_at' => ($variant === 'mismatched_kickoff' ? $start->copy()->addHour() : $start)->toIso8601String()],
            'pregame_market' => ['available' => $variant !== 'missing_market', 'consensus' => ['spread' => ['line' => -3.5], 'total' => ['line' => 44.5]]],
            'recommendation_context' => [
                'version' => NflRecommendationInputBuilder::VERSION,
                'model_version' => $variant === 'wrong_version' ? 'other' : NflModelVersion::current(),
                'outputs' => ['win_probability' => 0.7, 'predicted_spread' => $variant === 'missing_spread' ? null : 4.5, 'predicted_total' => $variant === 'missing_total' ? null : 45.0],
                'model_metadata' => $variant === 'missing_metadata' ? [] : ['true_epa' => ['applied' => true], 'analysis_layer' => ['trust_score' => 68, 'reason_codes' => ['qb_form_improving']]],
            ]]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'nfl', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id,
        'event_input_snapshot_id' => $input->id, 'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'generated_at' => $generated, 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    // Primary markets deliberately disagree with the frozen recommendation being audited.
    PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => 'moneyline', 'selection' => 'home', 'probability' => 0.2]);
    PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => 'spread', 'selection' => 'home', 'projected_line' => 14]);
    if ($variant !== 'draft') {
        $native->update(['publication_state' => 'published', 'published_at' => $generated]);
    }
    if ($variant === 'withdrawn') {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    return [$game, $native];
}

it('audits canonical recommendation points and frozen markets in either switch mode', function (bool $pipeline, bool $reads) {
    pointAuditCanonicalFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => $pipeline, 'prediction_lifecycle.canonical_reads.nfl' => $reads]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('nfl:analyze-point-projections', ['--season' => 2026, '--layers' => true, '--detailed' => true]))->toBe(0);
        expect(Artisan::output())->toContain('Forecast source: canonical', 'Rows: 1', '100.0%', 'Model vs Market', 'true_epa', 'SEA', '2.50', '4.00', '0.75', '3.25');
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->not->toContain('nfl_predictions')->not->toContain('prediction_feature_snapshots')
        ->not->toContain('game_odds_snapshots')->not->toContain('odds_data');
})->with([[true, false], [false, true], [true, true]]);

it('keeps absent or invalid canonical point evidence empty without historical fallback', function (string $variant) {
    pointAuditCanonicalFixture($variant);
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    $this->artisan('nfl:analyze-point-projections', ['--season' => 2026])
        ->expectsOutputToContain('No final NFL predictions with point projections found')->assertSuccessful();
})->with(['unsafe', 'draft', 'withdrawn', 'mismatched_kickoff', 'wrong_version', 'missing_spread', 'missing_total']);

it('keeps missing frozen markets missing and avoids metadata reads without layers', function () {
    pointAuditCanonicalFixture('missing_market');
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->artisan('nfl:analyze-point-projections', ['--season' => 2026])
            ->expectsOutputToContain('Rows: 1')->doesntExpectOutputToContain('Model vs Market')->assertSuccessful();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->not->toContain('capture_metadata')->not->toContain('odds_data')->not->toContain('game_odds_snapshots');
});

it('preserves historical point reports and their readiness replay after cutover', function () {
    pointAuditCanonicalFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true, 'prediction_lifecycle.canonical_reads.nfl' => true]);
    $this->artisan('nfl:analyze-point-projections', ['--season' => 2026, '--forecast-source' => 'legacy'])
        ->expectsOutputToContain('Forecast source: legacy')->expectsOutputToContain('Rows: 1')->assertSuccessful();
    $this->artisan('nfl:readiness-pass', [
        '--from-season' => 2026, '--to-season' => 2026, '--skip-backfill' => true, '--skip-analysis' => true,
        '--skip-reason-codes' => true, '--skip-moneyline' => true, '--skip-spreads' => true, '--skip-totals' => true, '--skip-sentinel' => true,
    ])->expectsOutputToContain('Forecast source: legacy')->expectsOutputToContain('Rows: 1')->assertSuccessful();
});

it('preserves canonical point scope filters and applies limits after eligibility', function () {
    pointAuditCanonicalFixture(season: 2025);
    pointAuditCanonicalFixture(season: 2026);
    pointAuditCanonicalFixture('missing_total', season: 2026);
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    $this->artisan('nfl:analyze-point-projections', ['--from-season' => 2025, '--to-season' => 2026])->expectsOutputToContain('Rows: 2')->assertSuccessful();
    $this->artisan('nfl:analyze-point-projections', ['--season' => 2026, '--limit' => 1])->expectsOutputToContain('Rows: 1')->assertSuccessful();
    $this->artisan('nfl:analyze-point-projections', ['--forecast-source' => 'invalid'])->assertFailed();
    $this->artisan('nfl:analyze-point-projections', ['--season' => 2026, '--from-season' => 2025])->assertFailed();
});

it('reads canonical point evidence in batches of 25', function () {
    foreach (range(1, 26) as $index) {
        pointAuditCanonicalFixture();
    }
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->artisan('nfl:analyze-point-projections', ['--season' => 2026])
            ->expectsOutputToContain('Rows: 26')->assertSuccessful();
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'as "audit_outputs"'))->count())->toBe(2);
});

it('limits canonical point reports to the latest eligible generated forecast', function () {
    $this->travelTo('2026-10-30 12:00:00');
    try {
        pointAuditCanonicalFixture(daysAgo: 3);
        pointAuditCanonicalFixture(daysAgo: 2);
        pointAuditCanonicalFixture('missing_total', daysAgo: 1);
        config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
        $this->artisan('nfl:analyze-point-projections', ['--season' => 2026, '--limit' => 1, '--detailed' => true])
            ->expectsOutputToContain('Rows: 1')->expectsOutputToContain('2026-10-28')
            ->doesntExpectOutputToContain('2026-10-27')->assertSuccessful();
    } finally {
        $this->travelBack();
    }
});
