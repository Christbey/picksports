<?php

use App\Console\Commands\NFL\AnalyzeReasonCodesCommand;
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
use Illuminate\Support\Facades\DB;

it('analyzes required reason-code sets and generated combinations', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $createPrediction = function (array $gameOverrides, float $winProbability, array $reasonCodes) use ($homeTeam, $awayTeam): void {
        $game = Game::factory()->create(array_merge([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'season' => 2025,
            'status' => 'STATUS_FINAL',
            'home_score' => 24,
            'away_score' => 17,
        ], $gameOverrides));

        Prediction::factory()->create([
            'game_id' => $game->id,
            'predicted_spread' => $winProbability > 0.5 ? 4.5 : -4.5,
            'predicted_total' => 44.5,
            'win_probability' => $winProbability,
            'confidence_score' => 68,
            'model_metadata' => [
                'analysis_layer' => [
                    'applied' => true,
                    'trust_score' => 68,
                    'reason_codes' => $reasonCodes,
                ],
            ],
        ]);
    };

    $createPrediction([], 0.7, ['strong_model_signal', 'qb_form_signal', 'rolling_efficiency_home_edge']);
    $createPrediction(['home_score' => 14, 'away_score' => 21], 0.4, ['strong_model_signal', 'qb_form_signal']);
    $createPrediction(['home_score' => 14, 'away_score' => 21], 0.8, ['strong_model_signal']);

    $this->artisan('nfl:analyze-reason-codes', [
        '--season' => 2025,
        '--codes' => ['strong_model_signal', 'qb_form_signal'],
        '--min-games' => 1,
        '--top' => 5,
    ])
        ->expectsOutputToContain('Analyzing 3 predictions with reason codes from season 2025')
        ->expectsOutputToContain('strong_model_signal + qb_form_signal')
        ->expectsOutputToContain('100%')
        ->assertSuccessful();
});

it('filters broad background reason codes from generated reports by default', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $createPrediction = function (array $gameOverrides, float $winProbability, array $reasonCodes) use ($homeTeam, $awayTeam): void {
        $game = Game::factory()->create(array_merge([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'season' => 2025,
            'status' => 'STATUS_FINAL',
            'home_score' => 24,
            'away_score' => 17,
        ], $gameOverrides));

        Prediction::factory()->create([
            'game_id' => $game->id,
            'predicted_spread' => $winProbability > 0.5 ? 4.5 : -4.5,
            'predicted_total' => 44.5,
            'win_probability' => $winProbability,
            'confidence_score' => 68,
            'model_metadata' => [
                'analysis_layer' => [
                    'applied' => true,
                    'trust_score' => 68,
                    'reason_codes' => $reasonCodes,
                ],
            ],
        ]);
    };

    $createPrediction([], 0.7, ['rolling_efficiency_signal', 'ol_dl_matchup_signal', 'qb_form_improving']);
    $createPrediction(['home_score' => 31, 'away_score' => 17], 0.7, ['rolling_efficiency_signal', 'ol_dl_matchup_signal', 'qb_form_improving']);
    $createPrediction(['home_score' => 14, 'away_score' => 21], 0.7, ['rolling_efficiency_signal', 'ol_dl_matchup_signal', 'weather_wind_edge']);

    $this->artisan('nfl:analyze-reason-codes', [
        '--season' => 2025,
        '--min-games' => 1,
        '--top' => 10,
    ])
        ->expectsOutputToContain('Filtering')
        ->expectsOutputToContain('Top Actionable Standalone Reason Codes')
        ->expectsOutputToContain('qb_form_improving')
        ->doesntExpectOutputToContain('rolling_efficiency_signal + ol_dl_matchup_signal')
        ->assertSuccessful();
});

it('treats diagnostic and non-actionable catalog codes as background', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2025,
        'status' => 'STATUS_FINAL',
        'home_score' => 24,
        'away_score' => 17,
    ]);

    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 4.5,
        'predicted_total' => 44.5,
        'win_probability' => 0.7,
        'confidence_score' => 68,
        'model_metadata' => [
            'analysis_layer' => [
                'applied' => true,
                'trust_score' => 68,
                'reason_codes' => [
                    'strong_model_signal',
                    'cold_outdoor_total_proxy',
                    'turnover_regression_risk',
                    'high_favorite_confidence',
                ],
            ],
        ],
    ]);

    $command = app(AnalyzeReasonCodesCommand::class);
    $method = new ReflectionMethod($command, 'backgroundReasonCodes');
    $method->setAccessible(true);

    $backgroundCodes = $method->invoke($command, collect([$prediction]), 2.0);

    expect($backgroundCodes->all())
        ->toContain('cold_outdoor_total_proxy')
        ->toContain('turnover_regression_risk')
        ->toContain('high_favorite_confidence')
        ->not->toContain('strong_model_signal');
});

function reasonCodeCanonicalFixture(string $variant = 'valid', int $season = 2026): array
{
    $start = now()->subDay();
    $generated = $start->copy()->subHours(2);
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'season' => $season, 'starts_at' => $start]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => $season,
        'status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 17,
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    Prediction::factory()->create(['game_id' => $game->id, 'model_metadata' => ['analysis_layer' => ['reason_codes' => ['legacy_only']]]]);
    $input = EventInputSnapshot::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified',
        'captured_at' => $generated->copy()->subHour(), 'cutoff_at' => $generated->copy()->subHour(),
        'latest_source_available_at' => $generated->copy()->subHour(),
        'inputs' => ['event' => ['starts_at' => ($variant === 'mismatched_kickoff' ? $start->copy()->addHour() : $start)->toIso8601String()],
            'recommendation_context' => [
                'version' => NflRecommendationInputBuilder::VERSION,
                'model_version' => $variant === 'wrong_version' ? 'other' : NflModelVersion::current(),
                'outputs' => ['win_probability' => $variant === 'missing_probability' ? null : 0.7, 'predicted_spread' => 4.5],
                'model_metadata' => $variant === 'missing_metadata' ? [] : ['analysis_layer' => ['trust_score' => 68, 'reason_codes' => ['qb_form_improving']]],
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

it('audits frozen canonical reasons and projections without legacy reads in either switch mode', function (bool $pipeline, bool $reads) {
    reasonCodeCanonicalFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => $pipeline, 'prediction_lifecycle.canonical_reads.nfl' => $reads]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->artisan('nfl:analyze-reason-codes', ['--season' => 2026, '--min-games' => 1, '--codes' => ['qb_form_improving']])
            ->expectsOutputToContain('Forecast source: canonical')
            ->expectsOutputToContain('Analyzing 1 predictions')
            ->expectsOutputToContain('100%')
            ->doesntExpectOutputToContain('legacy_only')->assertSuccessful();
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($sql)->not->toContain('nfl_predictions')->not->toContain('prediction_feature_snapshots')->not->toContain('odds_data');
})->with([[true, false], [false, true], [true, true]]);

it('keeps missing or ineligible native reason evidence out of the report without fallback', function (string $variant) {
    reasonCodeCanonicalFixture($variant);
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    $this->artisan('nfl:analyze-reason-codes', ['--season' => 2026])
        ->expectsOutputToContain('No predictions with reason codes found')
        ->doesntExpectOutputToContain('legacy_only')->assertSuccessful();
})->with(['unsafe', 'draft', 'withdrawn', 'mismatched_kickoff', 'wrong_version', 'missing_metadata', 'missing_probability']);

it('preserves explicit historical reason reports after canonical cutover and validates source selection', function () {
    reasonCodeCanonicalFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    $this->artisan('nfl:analyze-reason-codes', ['--season' => 2026, '--forecast-source' => 'legacy', '--include-background-codes' => true, '--min-games' => 1])
        ->expectsOutputToContain('Forecast source: legacy')->expectsOutputToContain('legacy_only')->assertSuccessful();
    $this->artisan('nfl:analyze-reason-codes', ['--forecast-source' => 'invalid'])
        ->expectsOutputToContain('Forecast source must be canonical or legacy')->assertFailed();
});

it('preserves canonical season ranges and rejects ambiguous scope', function () {
    reasonCodeCanonicalFixture(season: 2025);
    reasonCodeCanonicalFixture(season: 2026);
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    $this->artisan('nfl:analyze-reason-codes', ['--from-season' => 2025, '--to-season' => 2026, '--min-games' => 1])
        ->expectsOutputToContain('Analyzing 2 predictions')->assertSuccessful();
    $this->artisan('nfl:analyze-reason-codes', ['--season' => 2026])
        ->expectsOutputToContain('Analyzing 1 predictions')->assertSuccessful();
    $this->artisan('nfl:analyze-reason-codes', ['--season' => 2026, '--from-season' => 2025])->assertFailed();
});

it('loads canonical reason evidence in bounded batches without per-game snapshot reads', function () {
    foreach (range(1, 26) as $index) {
        reasonCodeCanonicalFixture();
    }
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->artisan('nfl:analyze-reason-codes', ['--season' => 2026, '--min-games' => 1])
            ->expectsOutputToContain('Analyzing 26 predictions')->assertSuccessful();
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'as "capture_metadata"'))->count())->toBe(2);
});

it('keeps the historical readiness replay on its historical reason corpus after cutover', function () {
    reasonCodeCanonicalFixture();
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true, 'prediction_lifecycle.canonical_reads.nfl' => true]);
    $this->artisan('nfl:readiness-pass', [
        '--from-season' => 2026, '--to-season' => 2026,
        '--skip-backfill' => true, '--skip-analysis' => true, '--skip-point-audit' => true,
        '--skip-moneyline' => true, '--skip-spreads' => true, '--skip-totals' => true, '--skip-sentinel' => true,
        '--reason-code-min-games' => 1,
    ])->expectsOutputToContain('Forecast source: legacy')
        ->expectsOutputToContain('Analyzing 1 predictions')->assertSuccessful();
});
