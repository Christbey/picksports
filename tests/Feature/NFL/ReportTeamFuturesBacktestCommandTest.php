<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamMetricSnapshot;
use App\Models\Sports\FuturesOddsSnapshot;
use App\Services\NFL\TeamFuturesBacktestService;
use App\Services\NFL\TeamFuturesBettingReportService;
use App\Services\NFL\TeamFuturesProjectionService;
use App\Services\NFL\TeamMetricSnapshotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

it('writes an nfl team futures backtest report', function () {
    $team = Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);
    $opponent = Team::factory()->create([
        'name' => 'Bills',
        'location' => 'Buffalo',
        'abbreviation' => 'BUF',
    ]);

    // Explicit synthetic source observation through the real capture writer.
    $historicalMetric = $this->travelTo(Carbon::parse('2025-10-15 12:00:00', 'UTC'), function () use ($team) {
        $metric = TeamMetric::query()->create([
            'team_id' => $team->id,
            'season' => 2025,
            'wins' => 9,
            'losses' => 0,
            'predictive_rating' => 1540,
            'future_strength_of_schedule' => 1505,
            'recent_form_rating' => 0.2,
            'injury_total_adjustment' => 0.0,
            'calculation_date' => '2025-10-15',
        ]);
        app(TeamMetricSnapshotService::class)->capture(2025);

        return $metric;
    });

    $snapshot = TeamMetricSnapshot::query()->firstOrFail();

    $historicalMetric->update([
        'team_id' => $team->id,
        'season' => 2025,
        'wins' => 12,
        'losses' => 5,
        'predictive_rating' => 1560,
        'future_strength_of_schedule' => 1490,
        'recent_form_rating' => 0.4,
        'injury_total_adjustment' => 0.1,
        'calculation_date' => '2026-01-10',
    ]);

    foreach (range(1, 17) as $index) {
        $date = $index <= 9 ? sprintf('2025-10-%02d', $index) : sprintf('2025-11-%02d', $index);
        $this->travelTo("{$date} 16:00:00", fn () => Game::factory()->create([
            'season' => 2025,
            'season_type' => config('nfl.season.types.regular'),
            'game_date' => "{$date} 12:00:00",
            'status' => config('nfl.statuses.final'),
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'home_score' => $index <= 12 ? 24 : 17,
            'away_score' => $index <= 12 || $index === 17 ? 17 : 24,
        ]));
    }

    FuturesOddsSnapshot::query()->create([
        'snapshot_key' => sha1('team-wins-over'),
        'row_key' => sha1('team-wins-over-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl_super_bowl_winner',
        'bookmaker' => 'draftkings',
        'market_key' => 'season_wins',
        'outcome_name' => 'Over',
        'outcome_description' => 'Chiefs',
        'outcome_point' => 11.5,
        'price' => -105,
        'implied_probability' => 0.5122,
        'captured_at' => '2025-10-15T12:00:00Z',
        'nfl_team_id' => $team->id,
    ]);

    FuturesOddsSnapshot::query()->create([
        'snapshot_key' => sha1('team-wins-under'),
        'row_key' => sha1('team-wins-under-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl_super_bowl_winner',
        'bookmaker' => 'draftkings',
        'market_key' => 'season_wins',
        'outcome_name' => 'Under',
        'outcome_description' => 'Chiefs',
        'outcome_point' => 11.5,
        'price' => -115,
        'implied_probability' => 0.5349,
        'captured_at' => '2025-10-15T12:00:00Z',
        'nfl_team_id' => $team->id,
    ]);

    $output = storage_path('app/ml/reports/nfl_team_futures_backtest_test.json');
    @unlink($output);

    Artisan::call('nfl:report-team-futures-backtest', [
        '--season' => 2025,
        '--market' => 'season_wins',
        '--from-date' => '2025-10-15 00:00:00',
        '--to-date' => '2025-10-15 23:59:59',
        '--as-of-date' => '2025-11-17 16:00:00',
        '--output' => $output,
    ]);

    $report = json_decode(file_get_contents($output), true);

    expect($report)->toBeArray()
        ->and($report['report_type'])->toBe('nfl_team_futures_backtest')
        ->and($report['season'])->toBe(2025)
        ->and($report['market'])->toBe('season_wins')
        ->and($report['metric_evidence']['mode_counts']['current_capture'])->toBe(1)
        ->and($report['summary']['count'])->toBe(1)
        ->and($report['summary']['line_count'])->toBe(1)
        ->and($report['dates'][0]['summary']['count'])->toBe(1);

    $backtest = app(TeamFuturesBacktestService::class);
    $beforeSettlement = $backtest->evaluate(2025, asOfDate: '2025-11-17 15:59:59');
    expect($beforeSettlement['summary']['count'])->toBe(0);

    config()->set('nfl.team_futures.betting_probability_calibration.min_sample', 1);
    $bets = app(TeamFuturesBettingReportService::class);
    expect($bets->generate(2026, asOfDate: '2025-11-17 15:59:59')['calibration']['sample_size'])->toBe(0)
        ->and($bets->generate(2026, asOfDate: '2025-11-17 16:00:00')['calibration']['sample_size'])->toBe(1);

    FuturesOddsSnapshot::query()->update(['outcome_point' => 12.0]);
    $projection = app(TeamFuturesProjectionService::class);
    [$beforeTie] = $projection->projections(2025, asOfDate: '2025-11-17 15:59:59', onlyWithOdds: true);
    expect($beforeTie['remaining_games'])->toBe(1)
        ->and($beforeTie['under_probability'])->toBe(0.0)
        ->and($beforeTie['push_probability'])->toBeGreaterThan(0.0)
        ->and($beforeTie['over_probability'] + $beforeTie['push_probability'])->toEqualWithDelta(1.0, 0.000001);

    [$afterTie] = $projection->projections(2025, asOfDate: '2025-11-17 16:00:00', onlyWithOdds: true);
    expect($afterTie['ties'])->toBe(1)
        ->and($afterTie['games_played'])->toBe(17)
        ->and($afterTie['projected_total'])->toBe(12.0)
        ->and($afterTie['projection_factors']['wins_stddev'])->toBe(0.0)
        ->and($afterTie['over_probability'])->toBe(0.0)
        ->and($afterTie['under_probability'])->toBe(0.0)
        ->and($afterTie['push_probability'])->toBe(1.0);

    $push = $backtest->evaluate(2025, asOfDate: '2025-11-17 16:00:00');
    expect($push['summary']['count'])->toBe(1)
        ->and($push['summary']['line_count'])->toBe(0)
        ->and($bets->generate(2026, asOfDate: '2025-11-17 16:00:00')['calibration']['sample_size'])->toBe(0);

    $snapshot->update(['captured_at' => '2025-10-15 12:00:01']);
    expect($backtest->evaluate(2025, asOfDate: '2025-11-17 16:00:00')['summary']['count'])->toBe(0);
});

it('can require historical team metric snapshots in the backtest command', function () {
    $team = Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);

    TeamMetric::query()->create([
        'team_id' => $team->id,
        'season' => 2025,
        'wins' => 12,
        'losses' => 5,
        'predictive_rating' => 1560,
        'future_strength_of_schedule' => 1490,
        'recent_form_rating' => 0.4,
        'injury_total_adjustment' => 0.1,
        'calculation_date' => '2026-01-10',
    ]);

    FuturesOddsSnapshot::query()->create([
        'snapshot_key' => sha1('strict-report-over'),
        'row_key' => sha1('strict-report-over-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'sportsoddshistory_nfl_team',
        'bookmaker' => 'sportsoddshistory',
        'market_key' => 'season_wins',
        'outcome_name' => 'Over',
        'outcome_description' => 'Chiefs',
        'outcome_point' => 11.5,
        'price' => -105,
        'implied_probability' => 0.5122,
        'captured_at' => '2025-08-01T12:00:00Z',
        'nfl_team_id' => $team->id,
    ]);

    FuturesOddsSnapshot::query()->create([
        'snapshot_key' => sha1('strict-report-under'),
        'row_key' => sha1('strict-report-under-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'sportsoddshistory_nfl_team',
        'bookmaker' => 'sportsoddshistory',
        'market_key' => 'season_wins',
        'outcome_name' => 'Under',
        'outcome_description' => 'Chiefs',
        'outcome_point' => 11.5,
        'price' => -115,
        'implied_probability' => 0.5349,
        'captured_at' => '2025-08-01T12:00:00Z',
        'nfl_team_id' => $team->id,
    ]);

    $output = storage_path('app/ml/reports/nfl_team_futures_backtest_strict_test.json');
    @unlink($output);

    Artisan::call('nfl:report-team-futures-backtest', [
        '--season' => 2025,
        '--market' => 'season_wins',
        '--from-date' => '2025-08-01T00:00:00Z',
        '--to-date' => '2025-08-01T23:59:59Z',
        '--require-historical-metrics' => true,
        '--output' => $output,
    ]);

    $report = json_decode(file_get_contents($output), true);

    expect($report)->toBeArray()
        ->and($report['require_historical_metrics'])->toBeTrue()
        ->and($report['summary']['count'])->toBe(0)
        ->and($report['summary']['line_count'])->toBe(0);
});
