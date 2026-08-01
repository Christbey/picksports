<?php

use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use Illuminate\Support\Facades\Artisan;

it('reports wnba team ats records from stored odds data', function () {
    $aces = Team::factory()->create([
        'location' => 'Las Vegas',
        'name' => 'Aces',
        'abbreviation' => 'LV',
    ]);
    $liberty = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Liberty',
        'abbreviation' => 'NY',
    ]);
    $storm = Team::factory()->create([
        'location' => 'Seattle',
        'name' => 'Storm',
        'abbreviation' => 'SEA',
    ]);

    Game::factory()->create([
        'home_team_id' => $aces->id,
        'away_team_id' => $liberty->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 86,
        'away_score' => 78,
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'New York Liberty', 'point' => 3.5, 'price' => -108],
                        ['name' => 'Las Vegas Aces', 'point' => -3.5, 'price' => -112],
                    ],
                ]],
            ]],
        ],
    ]);

    Game::factory()->create([
        'home_team_id' => $liberty->id,
        'away_team_id' => $aces->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 72,
        'away_score' => 76,
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'fanduel',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'LV Aces', 'point' => -2.5, 'price' => -110],
                        ['name' => 'NY Liberty', 'point' => 2.5, 'price' => -110],
                    ],
                ]],
            ]],
        ],
    ]);

    Game::factory()->create([
        'home_team_id' => $storm->id,
        'away_team_id' => $aces->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 77,
        'odds_data' => ['bookmakers' => []],
    ]);

    Game::factory()->create([
        'home_team_id' => $storm->id,
        'away_team_id' => $liberty->id,
        'season' => 2025,
        'status' => 'STATUS_FINAL',
        'home_score' => 90,
        'away_score' => 82,
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Seattle Storm', 'point' => -4.5],
                        ['name' => 'New York Liberty', 'point' => 4.5],
                    ],
                ]],
            ]],
        ],
    ]);

    $exitCode = Artisan::call('wnba:team-ats-report', [
        '--season' => 2026,
        '--json' => true,
    ]);
    $report = json_decode(Artisan::output(), true);

    $teams = collect($report['teams'])->keyBy('team');

    expect($exitCode)->toBe(0)
        ->and($report['summary'])->toMatchArray([
            'season' => 2026,
            'final_games' => 3,
            'games_with_line' => 2,
            'missing_line_count' => 1,
        ])
        ->and($teams['Las Vegas Aces'])->toMatchArray([
            'ats' => '2-0-0',
            'home_ats' => '1-0-0',
            'away_ats' => '1-0-0',
            'ats_pct' => 100.0,
            'avg_cover_margin' => 3.0,
            'games_with_line' => 2,
            'missing_line_games' => 1,
        ])
        ->and($teams['New York Liberty'])->toMatchArray([
            'ats' => '0-2-0',
            'home_ats' => '0-1-0',
            'away_ats' => '0-1-0',
            'ats_pct' => 0.0,
            'avg_cover_margin' => -3.0,
            'games_with_line' => 2,
            'missing_line_games' => 0,
        ])
        ->and($teams['Seattle Storm'])->toMatchArray([
            'ats' => '0-0-0',
            'home_ats' => '0-0-0',
            'away_ats' => '0-0-0',
            'ats_pct' => 0.0,
            'avg_cover_margin' => 0.0,
            'games_with_line' => 0,
            'missing_line_games' => 1,
        ]);
});
