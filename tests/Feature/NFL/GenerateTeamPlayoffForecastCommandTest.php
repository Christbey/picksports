<?php

use App\Models\NFL\Team;
use App\Models\NFL\TeamMetricSnapshot;
use Illuminate\Support\Facades\Artisan;

it('generates nfl playoff forecast probabilities from preseason team snapshots', function () {
    $teams = collect([
        ['BUF', 'Bills', 'AFC', 'East', 11.5],
        ['NYJ', 'Jets', 'AFC', 'East', 8.0],
        ['BAL', 'Ravens', 'AFC', 'North', 10.8],
        ['PIT', 'Steelers', 'AFC', 'North', 8.2],
        ['HOU', 'Texans', 'AFC', 'South', 9.8],
        ['IND', 'Colts', 'AFC', 'South', 7.6],
        ['KC', 'Chiefs', 'AFC', 'West', 10.6],
        ['LAC', 'Chargers', 'AFC', 'West', 9.5],
        ['PHI', 'Eagles', 'NFC', 'East', 10.9],
        ['DAL', 'Cowboys', 'NFC', 'East', 8.0],
        ['DET', 'Lions', 'NFC', 'North', 10.7],
        ['GB', 'Packers', 'NFC', 'North', 9.4],
        ['TB', 'Buccaneers', 'NFC', 'South', 9.6],
        ['ATL', 'Falcons', 'NFC', 'South', 8.1],
        ['SF', '49ers', 'NFC', 'West', 10.3],
        ['SEA', 'Seahawks', 'NFC', 'West', 8.7],
    ])->map(function (array $row, int $index) {
        [$abbr, $name, $conference, $division, $projectedWins] = $row;

        $team = Team::factory()->create([
            'abbreviation' => $abbr,
            'name' => $name,
            'location' => $abbr,
            'conference' => $conference,
            'division' => $division,
        ]);

        TeamMetricSnapshot::query()->create([
            'snapshot_key' => sha1('playoff-'.$abbr),
            'team_id' => $team->id,
            'season' => 2025,
            'wins' => 0,
            'losses' => 0,
            'predictive_rating' => ($projectedWins - 8.5) * 2.5,
            'future_strength_of_schedule' => 1500.0 + ($index % 4),
            'recent_form_rating' => ($projectedWins - 8.5) * 0.7,
            'injury_total_adjustment' => 0.0,
            'calculation_date' => '2025-08-01',
            'captured_at' => '2025-08-01T12:00:00Z',
        ]);

        return $team;
    });

    $output = storage_path('app/ml/reports/nfl_team_playoff_forecast_test.json');
    @unlink($output);

    Artisan::call('nfl:generate-team-playoff-forecast', [
        '--season' => 2025,
        '--as-of-date' => '2025-08-01T12:00:00Z',
        '--require-historical-metrics' => true,
        '--simulations' => 500,
        '--seed' => 12345,
        '--output' => $output,
    ]);

    $report = json_decode(file_get_contents($output), true);

    expect($report)->toBeArray()
        ->and($report['report_type'])->toBe('nfl_team_playoff_forecast')
        ->and($report['summary']['teams'])->toBe(16)
        ->and(count($report['division_leaders']))->toBe(8)
        ->and(count($report['conference_leaders']))->toBe(2)
        ->and($report['super_bowl_leaders'][0]['super_bowl_champion_probability'])->toBeGreaterThan(0.0)
        ->and(collect($report['teams'])->firstWhere('team_name', 'BUF Bills')['make_playoffs_probability'])->toBeGreaterThan(0.5);
});
