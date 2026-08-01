<?php

use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\PreseasonTeamSignal;
use App\Models\CFB\Team;
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $game = cfbReadinessGame($homeTeam, $awayTeam, [
        'season' => 2026,
        'week' => 1,
        'status' => 'STATUS_SCHEDULED',
    ]);

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
        ->and((float) $report['signal_coverage']['2026']['families']['coaching_continuity']['coverage_pct'])->toBe(100.0);
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
        ]);
    }

    $game = cfbReadinessGame($homeTeam, $awayTeam, [
        'season' => 2026,
        'week' => 0,
        'status' => 'STATUS_SCHEDULED',
    ]);

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
        ->and((float) $report['signal_coverage']['2026']['families']['coaching_continuity']['coverage_pct'])->toBe(100.0);
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
        ->and($unifiedCard)->toContain('return spread !== null ? Number((-spread).toFixed(1)) : null;')
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
