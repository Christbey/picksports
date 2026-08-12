<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\Sports\FuturesOddsSnapshot;
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

    foreach (range(1, 9) as $index) {
        Game::factory()->create([
            'season' => 2025,
            'season_type' => config('nfl.season.types.regular'),
            'game_date' => "2025-10-0{$index} 12:00:00",
            'status' => config('nfl.statuses.final'),
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'home_score' => $index <= 6 ? 24 : 17,
            'away_score' => $index <= 6 ? 17 : 24,
        ]);
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
        '--from-date' => '2025-10-15T00:00:00Z',
        '--to-date' => '2025-10-15T23:59:59Z',
        '--output' => $output,
    ]);

    $report = json_decode(file_get_contents($output), true);

    expect($report)->toBeArray()
        ->and($report['report_type'])->toBe('nfl_team_futures_backtest')
        ->and($report['season'])->toBe(2025)
        ->and($report['market'])->toBe('season_wins')
        ->and($report['summary']['count'])->toBe(1)
        ->and($report['summary']['line_count'])->toBe(1)
        ->and($report['dates'][0]['summary']['count'])->toBe(1);
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
