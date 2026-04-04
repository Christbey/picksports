<?php

use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamMetricSnapshot;
use App\Models\Sports\FuturesOddsSnapshot;
use App\Services\NFL\TeamFuturesProjectionService;

it('prefers historical team metric snapshots for as-of team futures projections', function () {
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

    TeamMetricSnapshot::query()->create([
        'snapshot_key' => sha1('metric-snapshot'),
        'team_id' => $team->id,
        'season' => 2025,
        'wins' => 6,
        'losses' => 3,
        'predictive_rating' => 1540,
        'future_strength_of_schedule' => 1505,
        'recent_form_rating' => 0.2,
        'injury_total_adjustment' => 0.0,
        'calculation_date' => '2025-10-15',
        'captured_at' => '2025-10-15T12:00:00Z',
    ]);

    FuturesOddsSnapshot::query()->create([
        'snapshot_key' => sha1('season-wins-over'),
        'row_key' => sha1('season-wins-over-row'),
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
        'snapshot_key' => sha1('season-wins-under'),
        'row_key' => sha1('season-wins-under-row'),
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

    $rows = app(TeamFuturesProjectionService::class)->projections(
        season: 2025,
        market: 'season_wins',
        asOfDate: '2025-10-15T12:00:00Z',
        onlyWithOdds: true,
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['wins'])->toBe(6)
        ->and($rows[0]['losses'])->toBe(3)
        ->and($rows[0]['market_odds']['line'])->toBe(11.5);
});

it('can require historical team metric snapshots for as-of projections', function () {
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
        'snapshot_key' => sha1('strict-season-wins-over'),
        'row_key' => sha1('strict-season-wins-over-row'),
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
        'snapshot_key' => sha1('strict-season-wins-under'),
        'row_key' => sha1('strict-season-wins-under-row'),
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

    $rows = app(TeamFuturesProjectionService::class)->projections(
        season: 2025,
        market: 'season_wins',
        asOfDate: '2025-08-01T12:00:00Z',
        requireHistoricalMetrics: true,
        onlyWithOdds: true,
    );

    expect($rows)->toBe([]);
});

it('produces different preseason win projections for teams with different historical strengths', function () {
    $strongTeam = Team::factory()->create([
        'name' => 'Lions',
        'location' => 'Detroit',
        'abbreviation' => 'DET',
    ]);
    $weakTeam = Team::factory()->create([
        'name' => 'Panthers',
        'location' => 'Carolina',
        'abbreviation' => 'CAR',
    ]);

    foreach ([
        [$strongTeam->id, 11.0, 4.0, 1498.0],
        [$weakTeam->id, -9.0, -3.0, 1502.0],
    ] as [$teamId, $predictive, $recent, $futureSos]) {
        TeamMetricSnapshot::query()->create([
            'snapshot_key' => sha1('preseason-metric-'.$teamId),
            'team_id' => $teamId,
            'season' => 2025,
            'wins' => 0,
            'losses' => 0,
            'predictive_rating' => $predictive,
            'future_strength_of_schedule' => $futureSos,
            'recent_form_rating' => $recent,
            'injury_total_adjustment' => 0.0,
            'calculation_date' => '2025-08-01',
            'captured_at' => '2025-08-01T12:00:00Z',
        ]);

        FuturesOddsSnapshot::query()->create([
            'snapshot_key' => sha1('preseason-over-'.$teamId),
            'row_key' => sha1('preseason-over-row-'.$teamId),
            'sport' => 'nfl',
            'season' => 2025,
            'odds_api_sport_key' => 'sportsoddshistory_nfl_team',
            'bookmaker' => 'sportsoddshistory',
            'market_key' => 'season_wins',
            'outcome_name' => 'Over',
            'outcome_description' => 'Team '.$teamId,
            'outcome_point' => 8.5,
            'price' => -110,
            'implied_probability' => 0.5238,
            'captured_at' => '2025-08-01T12:00:00Z',
            'nfl_team_id' => $teamId,
        ]);

        FuturesOddsSnapshot::query()->create([
            'snapshot_key' => sha1('preseason-under-'.$teamId),
            'row_key' => sha1('preseason-under-row-'.$teamId),
            'sport' => 'nfl',
            'season' => 2025,
            'odds_api_sport_key' => 'sportsoddshistory_nfl_team',
            'bookmaker' => 'sportsoddshistory',
            'market_key' => 'season_wins',
            'outcome_name' => 'Under',
            'outcome_description' => 'Team '.$teamId,
            'outcome_point' => 8.5,
            'price' => -110,
            'implied_probability' => 0.5238,
            'captured_at' => '2025-08-01T12:00:00Z',
            'nfl_team_id' => $teamId,
        ]);
    }

    $rows = app(TeamFuturesProjectionService::class)->projections(
        season: 2025,
        market: 'season_wins',
        asOfDate: '2025-08-01T12:00:00Z',
        requireHistoricalMetrics: true,
        onlyWithOdds: true,
        sortBy: 'team_id',
        direction: 'asc',
        limit: 32,
    );

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['projected_total'])->not->toBe($rows[1]['projected_total'])
        ->and(max($rows[0]['projected_total'], $rows[1]['projected_total']))->toBeGreaterThan(9.0)
        ->and(min($rows[0]['projected_total'], $rows[1]['projected_total']))->toBeLessThan(8.0);
});
