<?php

use App\Models\NFL\Team;
use App\Models\NFL\TeamMetricSnapshot;
use App\Models\Sports\FuturesOddsSnapshot;
use App\Services\NFL\TeamFuturesBettingReportService;
use Illuminate\Support\Facades\Artisan;

it('writes an nfl team futures bets report with ranked edges', function () {
    config()->set('nfl.team_futures.betting_probability_calibration.min_sample', 99);
    config()->set('nfl.team_futures.betting_probability_calibration.default_shrink', 0.5);

    $team = Team::factory()->create([
        'name' => 'Lions',
        'location' => 'Detroit',
        'abbreviation' => 'DET',
    ]);

    TeamMetricSnapshot::query()->create([
        'snapshot_key' => sha1('bets-snapshot'),
        'team_id' => $team->id,
        'season' => 2025,
        'wins' => 0,
        'losses' => 0,
        'predictive_rating' => 12.0,
        'future_strength_of_schedule' => 1498.0,
        'recent_form_rating' => 4.0,
        'injury_total_adjustment' => 0.0,
        'calculation_date' => '2025-08-01',
        'captured_at' => '2025-08-01T12:00:00Z',
    ]);

    FuturesOddsSnapshot::query()->create([
        'snapshot_key' => sha1('bets-over'),
        'row_key' => sha1('bets-over-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'sportsoddshistory_nfl_team',
        'bookmaker' => 'sportsoddshistory',
        'market_key' => 'season_wins',
        'outcome_name' => 'Over',
        'outcome_description' => 'Lions',
        'outcome_point' => 7.5,
        'price' => -110,
        'implied_probability' => 0.5238,
        'captured_at' => '2025-08-01T12:00:00Z',
        'nfl_team_id' => $team->id,
    ]);

    FuturesOddsSnapshot::query()->create([
        'snapshot_key' => sha1('bets-under'),
        'row_key' => sha1('bets-under-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'sportsoddshistory_nfl_team',
        'bookmaker' => 'sportsoddshistory',
        'market_key' => 'season_wins',
        'outcome_name' => 'Under',
        'outcome_description' => 'Lions',
        'outcome_point' => 7.5,
        'price' => -110,
        'implied_probability' => 0.5238,
        'captured_at' => '2025-08-01T12:00:00Z',
        'nfl_team_id' => $team->id,
    ]);

    $output = storage_path('app/ml/reports/nfl_team_futures_bets_test.json');
    @unlink($output);

    Artisan::call('nfl:report-team-futures-bets', [
        '--season' => 2025,
        '--market' => 'season_wins',
        '--as-of-date' => '2025-08-01 12:00:00',
        '--require-historical-metrics' => true,
        '--min-edge' => 0.01,
        '--output' => $output,
    ]);

    $report = json_decode(file_get_contents($output), true);

    expect($report)->toBeArray()
        ->and($report['report_type'])->toBe('nfl_team_futures_bets')
        ->and($report['calibration']['method'])->toBe('default_shrink')
        ->and($report['summary']['count'])->toBe(1)
        ->and($report['bets'][0]['team_name'])->toBe('Lions')
        ->and($report['bets'][0]['side'])->toBe('over')
        ->and($report['bets'][0]['edge'])->toBeGreaterThan(0.01)
        ->and($report['bets'][0]['model_probability'])->not->toBe($report['bets'][0]['raw_model_probability'])
        ->and($report['bets'][0]['market_probability'])->toEqualWithDelta(0.5, 0.0001)
        ->and($report['bets'][0]['raw_market_probability'])->toEqualWithDelta(0.5238, 0.0001)
        ->and($report['bets'][0]['market_overround'])->toBeGreaterThan(1.0)
        ->and($report['bets'][0]['expected_value'])->toBeGreaterThan(0.0)
        ->and($report['bets'][0]['fair_price'])->toBeInt();

    $service = app(TeamFuturesBettingReportService::class);
    $this->travelTo(now()->parse('2025-08-03 00:00:00'));
    expect($service->generate(2025)['summary']['count'])->toBe(1)
        ->and($service->generate(2025, asOfDate: '2025-08-03 00:00:01')['bets'])->toBe([])
        ->and($service->generate(2025, asOfDate: '2025-08-01 11:59:59')['bets'])->toBe([]);

    FuturesOddsSnapshot::query()->update(['outcome_point' => 8.0]);
    [$wholeLineBet] = $service->generate(2025, asOfDate: '2025-08-01 12:00:00')['bets'];
    $win = $wholeLineBet['model_probability'];
    $push = $wholeLineBet['push_probability'];
    expect($push)->toBeGreaterThan(0.0)
        ->and($wholeLineBet['expected_value'])->toEqualWithDelta($win * (100 / 110) - (1 - $win - $push), 0.0002);
    FuturesOddsSnapshot::query()->update(['outcome_point' => 7.5]);

    FuturesOddsSnapshot::query()->update(['market_last_update' => '2025-07-30 12:00:00']);
    expect($service->generate(2025, asOfDate: '2025-08-01 12:00:00')['bets'])->toBe([]);

    FuturesOddsSnapshot::query()->update([
        'market_last_update' => null,
        'price' => -10000,
        'implied_probability' => 0.990099,
    ]);
    expect($service->generate(2025, asOfDate: '2025-08-01 12:00:00')['bets'])->toBe([]);

    config()->set('nfl.team_futures.betting_probability_calibration.default_shrink', 0.0);
    FuturesOddsSnapshot::query()->where('outcome_name', 'Over')->update(['price' => 100, 'implied_probability' => 0.5]);
    expect($service->generate(2025, asOfDate: '2025-08-01 12:00:00')['bets'])->toBe([]);
});
