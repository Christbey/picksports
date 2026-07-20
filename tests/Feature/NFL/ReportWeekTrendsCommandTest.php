<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;

it('reports nfl ats and totals trends by week', function () {
    $home = Team::factory()->create([
        'abbreviation' => 'HME',
        'location' => 'Home',
        'name' => 'Team',
    ]);
    $away = Team::factory()->create([
        'abbreviation' => 'AWY',
        'location' => 'Away',
        'name' => 'Team',
    ]);

    createNflWeekTrendGame($home, $away, 2025, 1, -3.0, 44.0, 24, 17);
    createNflWeekTrendGame($home, $away, 2025, 1, 2.5, 41.0, 20, 21);
    createNflWeekTrendGame($home, $away, 2025, 2, -7.0, 45.0, 21, 20);
    createNflPostseasonTrendGame($home, $away, 2025, 4, 31, 24);

    $this->artisan('nfl:report-week-trends', [
        '--from-season' => 2025,
        '--to-season' => 2025,
        '--season-type' => 'regular',
        '--min-games' => 1,
        '--top' => 5,
    ])
        ->expectsOutputToContain('NFL Week Trends')
        ->expectsOutputToContain('Week 1')
        ->expectsOutputToContain('Champion And Playoff Team Trends')
        ->expectsOutputToContain('QB Experience Trends')
        ->expectsOutputToContain('Top Week Angles')
        ->expectsOutputToContain('Overs')
        ->assertSuccessful();
});

function createNflWeekTrendGame(
    Team $home,
    Team $away,
    int $season,
    int $week,
    float $homeSpread,
    float $total,
    int $homeScore,
    int $awayScore
): Game {
    return Game::query()->create([
        'espn_event_id' => "week-trend-{$season}-{$week}-{$homeScore}-{$awayScore}",
        'espn_uid' => "week-trend-uid-{$season}-{$week}-{$homeScore}-{$awayScore}",
        'season' => $season,
        'week' => $week,
        'season_type' => 'regular',
        'game_date' => "2025-09-0{$week}",
        'game_time' => '12:00:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => $homeScore,
        'away_score' => $awayScore,
        'status' => 'STATUS_FINAL',
        'odds_data' => nflWeekTrendOddsPayload($homeSpread, $total),
        'odds_updated_at' => '2025-09-01 12:00:00',
    ]);
}

function createNflPostseasonTrendGame(
    Team $home,
    Team $away,
    int $season,
    int $week,
    int $homeScore,
    int $awayScore
): Game {
    return Game::query()->create([
        'espn_event_id' => "week-trend-postseason-{$season}-{$week}",
        'espn_uid' => "week-trend-postseason-uid-{$season}-{$week}",
        'season' => $season,
        'week' => $week,
        'season_type' => 3,
        'game_date' => '2026-02-08',
        'game_time' => '17:30:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => $homeScore,
        'away_score' => $awayScore,
        'status' => 'STATUS_FINAL',
    ]);
}

/**
 * @return array<string,mixed>
 */
function nflWeekTrendOddsPayload(float $homeSpread, float $total): array
{
    return [
        'event_id' => 'week-trend-game',
        'commence_time' => '2025-09-01T17:00:00Z',
        'home_team' => 'Home Team',
        'away_team' => 'Away Team',
        'bookmakers' => [[
            'key' => 'draftkings',
            'title' => 'DraftKings',
            'markets' => [
                [
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Home Team', 'price' => -110, 'point' => $homeSpread],
                        ['name' => 'Away Team', 'price' => -110, 'point' => -$homeSpread],
                    ],
                ],
                [
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'price' => -110, 'point' => $total],
                        ['name' => 'Under', 'price' => -110, 'point' => $total],
                    ],
                ],
            ],
        ]],
    ];
}
