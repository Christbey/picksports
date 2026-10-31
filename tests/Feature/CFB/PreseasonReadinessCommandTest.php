<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\FpiRating;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\PreseasonTeamSignal;
use App\Models\CFB\Team;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses()->group('cfb', 'commands', 'readiness');

it('warns and can fail when week zero and one predictions are missing preseason signals', function () {
    [$homeTeam, $awayTeam] = cfbReadinessTeams();
    $game = cfbReadinessGame($homeTeam, $awayTeam, [
        'season' => 2026,
        'week' => 0,
        'status' => 'STATUS_SCHEDULED',
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1600,
        'away_elo' => 1500,
        'predicted_spread' => 7.5,
        'predicted_total' => 52.0,
        'win_probability' => 0.68,
        'confidence_score' => 68.0,
    ]);

    $exitCode = Artisan::call('cfb:report-preseason-readiness', [
        '--season' => 2026,
        '--json' => true,
        '--fail-on-warnings' => true,
    ]);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and($report['report_type'])->toBe('cfb_preseason_readiness')
        ->and((float) $report['signal_coverage']['2026']['families']['returning_production']['coverage_pct'])->toBe(0.0)
        ->and($report['early_week_readiness']['warning_count'])->toBe(1)
        ->and($report['early_week_readiness']['elo_only_count'])->toBe(1)
        ->and($report['early_week_readiness']['warnings'][0]['reason'])->toBe('week_0_1_prediction_relies_only_on_elo')
        ->and($report['early_week_readiness']['warnings'][0]['missing_preseason_signal_families'])->toContain('returning_production')
        ->and($report['early_week_readiness']['warnings'][0]['missing_preseason_signal_families'])->toContain('portal_talent')
        ->and($report['spread_convention']['samples'][0]['predicted_spread_home_margin'])->toBe(7.5)
        ->and($report['spread_convention']['samples'][0]['ui_home_line'])->toBe(-7.5);
});

it('passes early-week readiness when all preseason signal families are covered', function () {
    Schema::create('cfb_team_preseason_signals', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->unsignedSmallInteger('season');
        $table->decimal('returning_production_share', 6, 3)->nullable();
        $table->decimal('portal_net_rating', 6, 3)->nullable();
        $table->decimal('qb_continuity_signal', 6, 3)->nullable();
        $table->decimal('coach_continuity_signal', 6, 3)->nullable();
        $table->decimal('scheme_change_score', 6, 3)->nullable();
        $table->timestamps();
    });

    [$homeTeam, $awayTeam] = cfbReadinessTeams();
    foreach ([$homeTeam, $awayTeam] as $team) {
        DB::table('cfb_team_preseason_signals')->insert([
            'team_id' => $team->id,
            'season' => 2026,
            'returning_production_share' => 0.72,
            'portal_net_rating' => 1.25,
            'qb_continuity_signal' => 0.80,
            'coach_continuity_signal' => 1.00,
            'scheme_change_score' => 0.15,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        FpiRating::factory()->create([
            'team_id' => $team->id,
            'season' => 2026,
            'week' => 1,
            'special_teams' => 1.5,
        ]);
    }

    $game = cfbReadinessGame($homeTeam, $awayTeam, [
        'season' => 2026,
        'week' => 1,
        'status' => 'STATUS_SCHEDULED',
    ]);
    cfbReadinessContextSignal($game);

    Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1600,
        'away_elo' => 1500,
        'predicted_spread' => 3.0,
        'predicted_total' => 51.0,
        'win_probability' => 0.60,
        'confidence_score' => 60.0,
    ]);

    $exitCode = Artisan::call('cfb:report-preseason-readiness', [
        '--season' => 2026,
        '--json' => true,
        '--fail-on-warnings' => true,
    ]);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($report['early_week_readiness']['warning_count'])->toBe(0)
        ->and((float) $report['signal_coverage']['2026']['families']['returning_production']['coverage_pct'])->toBe(100.0)
        ->and((float) $report['signal_coverage']['2026']['families']['portal_talent']['coverage_pct'])->toBe(100.0)
        ->and((float) $report['signal_coverage']['2026']['families']['qb_continuity']['coverage_pct'])->toBe(100.0)
        ->and((float) $report['signal_coverage']['2026']['families']['coaching_continuity']['coverage_pct'])->toBe(100.0)
        ->and((float) $report['signal_coverage']['2026']['families']['coaching_scheme_detail']['coverage_pct'])->toBe(100.0)
        ->and((float) $report['signal_coverage']['2026']['families']['special_teams']['coverage_pct'])->toBe(100.0);
});

it('detects canonical synced preseason signal table coverage', function () {
    [$homeTeam, $awayTeam] = cfbReadinessTeams();

    foreach ([$homeTeam, $awayTeam] as $team) {
        PreseasonTeamSignal::factory()->create([
            'team_id' => $team->id,
            'season' => 2026,
            'returning_percent_ppa' => 0.720,
            'transfer_portal_payload' => [['position' => 'QB', 'rating' => 0.94]],
            'talent_composite' => 850.000,
            'qb_continuity_classification' => PreseasonTeamSignal::QB_RETURNING_STARTER,
            'new_head_coach' => false,
            'coaching_continuity_payload' => ['scheme_change_score' => 0.10],
        ]);
        FpiRating::factory()->create([
            'team_id' => $team->id,
            'season' => 2026,
            'week' => 1,
            'special_teams' => 1.5,
        ]);
    }

    $game = cfbReadinessGame($homeTeam, $awayTeam, [
        'season' => 2026,
        'week' => 0,
        'status' => 'STATUS_SCHEDULED',
    ]);
    cfbReadinessContextSignal($game);

    Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1600,
        'away_elo' => 1500,
        'predicted_spread' => 4.0,
        'predicted_total' => 50.0,
        'win_probability' => 0.62,
        'confidence_score' => 62.0,
    ]);

    $exitCode = Artisan::call('cfb:report-preseason-readiness', [
        '--season' => 2026,
        '--json' => true,
        '--fail-on-warnings' => true,
    ]);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($report['early_week_readiness']['warning_count'])->toBe(0)
        ->and($report['signal_coverage']['2026']['families']['returning_production']['detected_sources'][0]['table'])->toBe('cfb_preseason_team_signals')
        ->and((float) $report['signal_coverage']['2026']['families']['returning_production']['coverage_pct'])->toBe(100.0)
        ->and((float) $report['signal_coverage']['2026']['families']['portal_talent']['coverage_pct'])->toBe(100.0)
        ->and((float) $report['signal_coverage']['2026']['families']['qb_continuity']['coverage_pct'])->toBe(100.0)
        ->and((float) $report['signal_coverage']['2026']['families']['coaching_continuity']['coverage_pct'])->toBe(100.0)
        ->and((float) $report['signal_coverage']['2026']['families']['coaching_scheme_detail']['coverage_pct'])->toBe(100.0)
        ->and((float) $report['signal_coverage']['2026']['families']['special_teams']['coverage_pct'])->toBe(100.0);
});

it('backtests cfb prediction performance by week buckets with calibration buckets', function () {
    [$homeTeam, $awayTeam] = cfbReadinessTeams();

    cfbReadinessFinalPrediction($homeTeam, $awayTeam, week: 0, predictedSpread: 7.0, winProbability: 0.65, homeScore: 31, awayScore: 21);
    cfbReadinessFinalPrediction($homeTeam, $awayTeam, week: 3, predictedSpread: -4.0, winProbability: 0.40, homeScore: 17, awayScore: 24);
    cfbReadinessFinalPrediction($homeTeam, $awayTeam, week: 6, predictedSpread: 10.0, winProbability: 0.76, homeScore: 20, awayScore: 27, winnerCorrect: false);
    cfbReadinessFinalPrediction($homeTeam, $awayTeam, week: 10, predictedSpread: 14.0, winProbability: 0.85, homeScore: 35, awayScore: 10);

    $exitCode = Artisan::call('cfb:report-preseason-readiness', [
        '--season' => 2025,
        '--from-season' => 2025,
        '--to-season' => 2025,
        '--json' => true,
    ]);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($report['backtest']['count'])->toBe(4)
        ->and($report['backtest']['week_buckets']['week_0_1']['count'])->toBe(1)
        ->and($report['backtest']['week_buckets']['week_2_4']['count'])->toBe(1)
        ->and($report['backtest']['week_buckets']['week_5_8']['count'])->toBe(1)
        ->and($report['backtest']['week_buckets']['week_9_plus']['count'])->toBe(1)
        ->and((float) $report['backtest']['week_buckets']['week_0_1']['winner_accuracy'])->toBe(100.0)
        ->and((float) $report['backtest']['week_buckets']['week_5_8']['winner_accuracy'])->toBe(0.0)
        ->and((float) $report['backtest']['week_buckets']['week_9_plus']['spread_mae'])->toBe(11.0)
        ->and($report['backtest']['week_buckets']['week_0_1']['calibration_buckets'][0]['bucket'])->toBe('60-69');
});

it('pins the backend and frontend home-spread convention', function () {
    $predictionSummary = File::get(resource_path('js/components/game-page/PredictionSummaryCard.vue'));
    $unifiedCard = File::get(resource_path('js/components/predictions/UnifiedPredictionCard.vue'));
    $publicSport = File::get(resource_path('js/pages/PublicSport.vue'));
    $bettingAnalysis = File::get(resource_path('js/components/BettingAnalysisCard.vue'));

    expect($predictionSummary)->toContain('-Number(prediction.predicted_spread)')
        ->and(File::get(resource_path('js/lib/pregameBetCalls.ts')))->toContain('modelLine !== null ? -modelLine : number(assessment.model_home_margin)')
        ->and($unifiedCard)->toContain('defaultLine: spread !== null ? Number((-spread).toFixed(1)) : null,')
        ->and($publicSport)->toContain('const homeLine = -numeric;')
        ->and($bettingAnalysis)->toContain('return formatHomeSpreadLine(bet.model_home_line ?? bet.model_line);');
});

function cfbReadinessTeams(): array
{
    return [
        Team::factory()->create([
            'school' => 'Home',
            'mascot' => 'State',
            'abbreviation' => 'HST',
            'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        ]),
        Team::factory()->create([
            'school' => 'Away',
            'mascot' => 'Tech',
            'abbreviation' => 'AT',
            'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        ]),
    ];
}

function cfbReadinessGame(Team $homeTeam, Team $awayTeam, array $attributes = []): Game
{
    return Game::factory()->create(array_merge([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'week' => 0,
        'season_type' => 'regular',
        'game_date' => '2026-08-29',
        'game_time' => '19:00:00',
        'status' => 'STATUS_SCHEDULED',
        'home_score' => null,
        'away_score' => null,
    ], $attributes));
}

function cfbReadinessContextSignal(Game $game): void
{
    DB::table('cfb_game_context_signals')->insert([
        'game_id' => $game->id,
        'home_team_id' => $game->home_team_id,
        'away_team_id' => $game->away_team_id,
        'season' => $game->season,
        'week' => $game->week,
        'home_player_availability_score' => 0.0,
        'away_player_availability_score' => 0.0,
        'home_qb_availability_score' => 1.0,
        'away_qb_availability_score' => 1.0,
        'player_availability_payload' => json_encode(['available' => true]),
        'temperature_f' => 70.0,
        'wind_speed_mph' => 5.0,
        'wind_gust_mph' => 8.0,
        'precipitation_inches' => 0.0,
        'weather_condition' => 'clear',
        'weather_payload' => json_encode(['available' => true]),
        'home_rating_consensus' => 5.0,
        'away_rating_consensus' => 4.0,
        'rating_consensus_payload' => json_encode(['available' => true]),
        'home_explosiveness_score' => 0.15,
        'away_explosiveness_score' => 0.12,
        'explosiveness_payload' => json_encode(['available' => true]),
        'home_line_qb_score' => 1.0,
        'away_line_qb_score' => 0.8,
        'line_qb_payload' => json_encode(['available' => true]),
        'opening_home_spread' => -3.0,
        'current_home_spread' => -3.0,
        'consensus_home_spread' => -3.0,
        'market_movement_payload' => json_encode(['available' => true]),
        'home_rest_days' => 7,
        'away_rest_days' => 7,
        'schedule_context_payload' => json_encode(['available' => true]),
        'synced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cfbReadinessFinalPrediction(
    Team $homeTeam,
    Team $awayTeam,
    int $week,
    float $predictedSpread,
    float $winProbability,
    int $homeScore,
    int $awayScore,
    ?bool $winnerCorrect = null,
): Prediction {
    $game = cfbReadinessGame($homeTeam, $awayTeam, [
        'season' => 2025,
        'week' => $week,
        'game_date' => '2025-09-01',
        'status' => 'STATUS_FINAL',
        'home_score' => $homeScore,
        'away_score' => $awayScore,
    ]);

    $actualMargin = (float) $homeScore - (float) $awayScore;
    $computedWinnerCorrect = $predictedSpread >= 0.0 ? $actualMargin > 0.0 : $actualMargin < 0.0;

    return Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1500,
        'away_elo' => 1500,
        'predicted_spread' => $predictedSpread,
        'predicted_total' => 52.0,
        'win_probability' => $winProbability,
        'confidence_score' => max($winProbability, 1 - $winProbability) * 100,
        'actual_spread' => $actualMargin,
        'actual_total' => $homeScore + $awayScore,
        'spread_error' => abs($actualMargin - $predictedSpread),
        'winner_correct' => $winnerCorrect ?? $computedWinnerCorrect,
        'graded_at' => now(),
    ]);
}

function cfbNativeReadinessFixture(string $variant = 'valid', bool $final = false, int $season = 2026, int $week = 0): array
{
    $home = Team::where('abbreviation', 'HST')->first();
    $away = Team::where('abbreviation', 'AT')->first();
    if (! $home || ! $away) {
        [$home, $away] = cfbReadinessTeams();
    }
    $start = $final ? now()->subDays(2) : now()->addDay();
    $generated = $final ? $start->copy()->subHours(2) : now()->subHour();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => $season, 'starts_at' => $start]);
    $game = cfbReadinessGame($home, $away, ['sport_event_id' => $event->id, 'season' => $season, 'week' => $week,
        'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(),
        'status' => $final ? 'STATUS_FINAL' : 'STATUS_SCHEDULED', 'home_score' => $final ? 31 : null, 'away_score' => $final ? 21 : null]);
    Prediction::create(['game_id' => $game->id, 'home_elo' => 1600, 'away_elo' => 1500,
        'predicted_spread' => 25, 'win_probability' => 0.9, 'winner_correct' => false, 'spread_error' => 99]);
    $input = EventInputSnapshot::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified',
        'captured_at' => $generated->copy()->subHour(), 'cutoff_at' => $generated->copy()->subHour(),
        'latest_source_available_at' => $generated->copy()->subHour(),
        'inputs' => ['event' => ['starts_at' => ($variant === 'kickoff_mismatch' ? $start->copy()->addHour() : $start)->toIso8601String()],
            'home' => ['elo' => 1600, 'metrics' => ['fpi' => $variant === 'core_metrics' ? 8.0 : null]], 'away' => ['elo' => 1500]]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'cfb', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'generated_at' => $generated, 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    if ($variant !== 'missing_spread') {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => 'spread', 'selection' => 'home', 'projected_line' => -7.0]);
    }
    if ($variant !== 'missing_probability') {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => 'moneyline', 'selection' => 'home', 'probability' => 0.65]);
    }
    if ($variant !== 'draft') {
        $native->update(['publication_state' => 'published', 'published_at' => $generated]);
    }
    if ($variant === 'withdrawn') {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    return [$game, $native];
}

it('uses native CFB outputs for week backtests and spread signs without legacy reads in either switch mode', function (bool $pipeline, bool $reads) {
    cfbNativeReadinessFixture(final: true);
    cfbNativeReadinessFixture();
    config(['prediction_lifecycle.canonical_pipeline.cfb' => $pipeline, 'prediction_lifecycle.canonical_reads.cfb' => $reads]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(Artisan::call('cfb:report-preseason-readiness', ['--season' => 2026, '--json' => true, '--fail-on-warnings' => true]))->toBe(1);
        $report = json_decode(Artisan::output(), true);
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($report['forecast_source'])->toBe('canonical')->and($report['backtest']['count'])->toBe(1)
        ->and((float) $report['backtest']['spread_mae'])->toBe(3.0)->and((float) $report['backtest']['winner_accuracy'])->toBe(100.0)
        ->and($report['early_week_readiness']['elo_only_count'])->toBe(1)
        ->and($report['early_week_readiness']['warnings'][0]['reason'])->toBe('week_0_1_prediction_relies_only_on_elo')
        ->and((float) $report['spread_convention']['samples'][0]['predicted_spread_home_margin'])->toBe(7.0)
        ->and((float) $report['spread_convention']['samples'][0]['ui_home_line'])->toBe(-7.0)
        ->and($sql)->not->toContain('cfb_predictions')->not->toContain('prediction_feature_snapshots')->not->toContain('odds_data');
})->with([[true, false], [false, true], [true, true]]);

it('warns about unavailable native early-week forecasts without borrowing legacy evidence', function (string $variant) {
    cfbNativeReadinessFixture($variant);
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    expect(Artisan::call('cfb:report-preseason-readiness', ['--season' => 2026, '--json' => true, '--fail-on-warnings' => true]))->toBe(1);
    $report = json_decode(Artisan::output(), true);
    expect($report['early_week_readiness']['warning_count'])->toBe(1)
        ->and($report['early_week_readiness']['warnings'][0]['reason'])->toBe('week_0_1_canonical_forecast_unavailable')
        ->and($report['spread_convention']['samples'])->toBeEmpty()->and($report['backtest']['count'])->toBe(0);
})->with(['draft', 'withdrawn', 'unsafe', 'missing_spread', 'kickoff_mismatch']);

it('preserves explicit historical readiness and rejects invalid sources', function () {
    cfbNativeReadinessFixture(final: true);
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true, 'prediction_lifecycle.canonical_reads.cfb' => true]);
    expect(Artisan::call('cfb:report-preseason-readiness', ['--season' => 2026, '--forecast-source' => 'legacy', '--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true);
    expect($report['forecast_source'])->toBe('legacy')->and((float) $report['backtest']['spread_mae'])->toBe(99.0)
        ->and((float) $report['backtest']['winner_accuracy'])->toBe(0.0)
        ->and((float) $report['spread_convention']['samples'][0]['predicted_spread_home_margin'])->toBe(25.0);
    $this->artisan('cfb:report-preseason-readiness', ['--forecast-source' => 'invalid'])->assertFailed();
});

it('uses native frozen core metrics without removing missing-family warnings', function () {
    cfbNativeReadinessFixture('core_metrics');
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    Artisan::call('cfb:report-preseason-readiness', ['--season' => 2026, '--json' => true]);
    $report = json_decode(Artisan::output(), true);
    expect($report['early_week_readiness']['elo_only_count'])->toBe(0)
        ->and($report['early_week_readiness']['warning_count'])->toBe(1)
        ->and($report['early_week_readiness']['warnings'][0]['reason'])->toBe('week_0_1_prediction_missing_preseason_signal_family');
});

it('preserves successful native readiness when every required signal family is covered', function () {
    [$game] = cfbNativeReadinessFixture();
    foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
        PreseasonTeamSignal::factory()->create(['team_id' => $teamId, 'season' => 2026,
            'returning_percent_ppa' => 0.72, 'transfer_portal_payload' => [['position' => 'QB', 'rating' => 0.94]],
            'talent_composite' => 850, 'qb_continuity_classification' => PreseasonTeamSignal::QB_RETURNING_STARTER,
            'new_head_coach' => false, 'coaching_continuity_payload' => ['scheme_change_score' => 0.1]]);
        FpiRating::factory()->create(['team_id' => $teamId, 'season' => 2026, 'week' => 0, 'special_teams' => 1.5]);
    }
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    expect(Artisan::call('cfb:report-preseason-readiness', ['--season' => 2026, '--json' => true, '--fail-on-warnings' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['early_week_readiness']['warning_count'])->toBe(0);
});

it('keeps canonical history scope separate from the target season and limits only eligible backtests', function () {
    cfbNativeReadinessFixture(final: true, season: 2025, week: 3);
    cfbNativeReadinessFixture(final: true, season: 2026, week: 6);
    cfbNativeReadinessFixture('missing_probability', final: true, season: 2026, week: 10);
    cfbNativeReadinessFixture(season: 2027);
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    Artisan::call('cfb:report-preseason-readiness', ['--season' => 2027, '--from-season' => 2025, '--to-season' => 2026, '--json' => true]);
    $report = json_decode(Artisan::output(), true);
    expect($report['backtest']['count'])->toBe(2)->and($report['backtest']['week_buckets']['week_2_4']['count'])->toBe(1)
        ->and($report['backtest']['week_buckets']['week_5_8']['count'])->toBe(1)
        ->and($report['early_week_readiness']['warning_count'])->toBe(1);
    Artisan::call('cfb:report-preseason-readiness', ['--season' => 2027, '--from-season' => 2025, '--to-season' => 2026, '--limit' => 1, '--json' => true]);
    $limited = json_decode(Artisan::output(), true);
    expect($limited['backtest']['count'])->toBe(1)->and($limited['backtest']['week_buckets']['week_5_8']['count'])->toBe(1);
    $this->artisan('cfb:report-preseason-readiness', ['--from-season' => 2027, '--to-season' => 2025])->assertFailed();
});

it('reuses one batched native forecast corpus across all report sections', function () {
    foreach (range(1, 26) as $index) {
        cfbNativeReadinessFixture(final: true);
    }
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        Artisan::call('cfb:report-preseason-readiness', ['--season' => 2026, '--json' => true]);
        $report = json_decode(Artisan::output(), true);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($report['backtest']['count'])->toBe(26)
        ->and($queries->filter(fn (string $sql): bool => str_starts_with($sql, 'select * from "event_input_snapshots"'))->count())->toBe(2)
        ->and($queries->implode(' '))->not->toContain('cfb_predictions');
});

it('does not create missing-forecast warnings for cancelled early-week games', function () {
    [$game] = cfbNativeReadinessFixture('draft');
    $game->update(['status' => 'STATUS_CANCELED']);
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    expect(Artisan::call('cfb:report-preseason-readiness', ['--season' => 2026, '--json' => true, '--fail-on-warnings' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['early_week_readiness']['warning_count'])->toBe(0);
});

it('preserves historical sample ordering when legacy timestamps are tied', function () {
    $this->travelTo('2026-10-30 12:00:00');
    try {
        cfbNativeReadinessFixture();
        cfbNativeReadinessFixture();
        $expected = Prediction::whereNotNull('predicted_spread')->whereHas('game', fn ($query) => $query->where('season', 2026))
            ->latest()->limit(5)->pluck('game_id')->all();
        Artisan::call('cfb:report-preseason-readiness', ['--season' => 2026, '--forecast-source' => 'legacy', '--json' => true]);
        $report = json_decode(Artisan::output(), true);
        expect(array_column($report['spread_convention']['samples'], 'game_id'))->toBe($expected);
    } finally {
        $this->travelBack();
    }
});
