<?php

use App\Models\NFL\Team;
use App\Models\Sports\FuturesOddsSnapshot;
use App\Services\Sports\FuturesOddsLookupService;
use Carbon\Carbon;

it('returns the latest team futures snapshot at or before a timestamp', function () {
    $team = Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);

    FuturesOddsSnapshot::create([
        'snapshot_key' => sha1('old'),
        'row_key' => sha1('old-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl_super_bowl_winner',
        'bookmaker' => 'draftkings',
        'market_key' => 'outrights',
        'outcome_name' => 'Chiefs',
        'price' => 700,
        'implied_probability' => 0.125,
        'captured_at' => Carbon::parse('2025-08-01T12:00:00Z'),
        'nfl_team_id' => $team->id,
    ]);

    FuturesOddsSnapshot::create([
        'snapshot_key' => sha1('new'),
        'row_key' => sha1('new-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl_super_bowl_winner',
        'bookmaker' => 'draftkings',
        'market_key' => 'outrights',
        'outcome_name' => 'Chiefs',
        'price' => 650,
        'implied_probability' => 0.133333,
        'captured_at' => Carbon::parse('2025-08-03T12:00:00Z'),
        'nfl_team_id' => $team->id,
    ]);

    $lookup = app(FuturesOddsLookupService::class);

    $rows = $lookup->byTeamForSeasonAt('nfl', 2025, Carbon::parse('2025-08-02T12:00:00Z'));

    expect($rows[$team->id]['price'])->toBe(700)
        ->and($rows[$team->id]['captured_at'])->toContain('2025-08-01');
});

it('returns the latest nfl team win totals market at or before a timestamp', function () {
    $team = Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);

    FuturesOddsSnapshot::create([
        'snapshot_key' => sha1('wins-old-over'),
        'row_key' => sha1('wins-old-over-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl_super_bowl_winner',
        'bookmaker' => 'draftkings',
        'market_key' => 'season_wins',
        'outcome_name' => 'Over',
        'outcome_description' => 'Chiefs',
        'outcome_point' => 11.5,
        'price' => -110,
        'implied_probability' => 0.5238,
        'captured_at' => Carbon::parse('2025-08-01T12:00:00Z'),
        'nfl_team_id' => $team->id,
    ]);

    FuturesOddsSnapshot::create([
        'snapshot_key' => sha1('wins-old-under'),
        'row_key' => sha1('wins-old-under-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl_super_bowl_winner',
        'bookmaker' => 'draftkings',
        'market_key' => 'season_wins',
        'outcome_name' => 'Under',
        'outcome_description' => 'Chiefs',
        'outcome_point' => 11.5,
        'price' => -110,
        'implied_probability' => 0.5238,
        'captured_at' => Carbon::parse('2025-08-01T12:00:00Z'),
        'nfl_team_id' => $team->id,
    ]);

    FuturesOddsSnapshot::create([
        'snapshot_key' => sha1('wins-new-over'),
        'row_key' => sha1('wins-new-over-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl_super_bowl_winner',
        'bookmaker' => 'draftkings',
        'market_key' => 'season_wins',
        'outcome_name' => 'Over',
        'outcome_description' => 'Chiefs',
        'outcome_point' => 12.5,
        'price' => 100,
        'implied_probability' => 0.5,
        'captured_at' => Carbon::parse('2025-08-03T12:00:00Z'),
        'nfl_team_id' => $team->id,
    ]);

    $lookup = app(FuturesOddsLookupService::class);

    $rows = $lookup->nflTeamWinTotalsBySeasonAt(2025, Carbon::parse('2025-08-02T12:00:00Z'));

    expect($rows[$team->id]['line'])->toBe(11.5)
        ->and($rows[$team->id]['over_price'])->toBe(-110)
        ->and($rows[$team->id]['under_price'])->toBe(-110)
        ->and($rows[$team->id]['captured_at'])->toContain('2025-08-01');
});
