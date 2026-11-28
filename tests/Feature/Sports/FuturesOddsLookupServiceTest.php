<?php

use App\Models\NFL\Team;
use App\Models\Sports\FuturesOdd;
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
        ->and($rows[$team->id]['is_complete_market'])->toBeTrue()
        ->and($rows[$team->id]['over_no_vig_probability'])->toEqualWithDelta(0.5, 0.0001)
        ->and($rows[$team->id]['under_no_vig_probability'])->toEqualWithDelta(0.5, 0.0001)
        ->and($rows[$team->id]['overround'])->toBeGreaterThan(1.0)
        ->and($rows[$team->id]['captured_at'])->toContain('2025-08-01');
});

it('does not pair nfl team win total sides from different snapshots', function () {
    $team = Team::factory()->create([
        'name' => 'Lions',
        'location' => 'Detroit',
        'abbreviation' => 'DET',
    ]);

    FuturesOddsSnapshot::create([
        'snapshot_key' => sha1('mixed-old-under'),
        'row_key' => sha1('mixed-old-under-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'sportsoddshistory_nfl_team',
        'bookmaker' => 'draftkings',
        'market_key' => 'season_wins',
        'outcome_name' => 'Under',
        'outcome_description' => 'Lions',
        'outcome_point' => 9.5,
        'price' => -110,
        'implied_probability' => 0.5238,
        'captured_at' => Carbon::parse('2025-08-01T12:00:00Z'),
        'nfl_team_id' => $team->id,
    ]);

    FuturesOddsSnapshot::create([
        'snapshot_key' => sha1('mixed-new-over'),
        'row_key' => sha1('mixed-new-over-row'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'sportsoddshistory_nfl_team',
        'bookmaker' => 'draftkings',
        'market_key' => 'season_wins',
        'outcome_name' => 'Over',
        'outcome_description' => 'Lions',
        'outcome_point' => 9.5,
        'price' => 100,
        'implied_probability' => 0.5,
        'captured_at' => Carbon::parse('2025-08-03T12:00:00Z'),
        'nfl_team_id' => $team->id,
    ]);

    $rows = app(FuturesOddsLookupService::class)
        ->nflTeamWinTotalsBySeasonAt(2025, Carbon::parse('2025-08-04T12:00:00Z'));

    expect($rows[$team->id]['line'])->toBe(9.5)
        ->and($rows[$team->id]['over_price'])->toBe(100)
        ->and($rows[$team->id]['under_price'])->toBeNull()
        ->and($rows[$team->id]['is_complete_market'])->toBeFalse()
        ->and($rows[$team->id]['over_no_vig_probability'])->toBeNull()
        ->and($rows[$team->id]['captured_at'])->toContain('2025-08-03');
});

it('preserves newest quote selection, market filters and timestamp ties while iterating futures', function () {
    $teams = Team::factory()->count(2)->create();
    $capturedAt = Carbon::parse('2025-08-01T12:00:00Z');

    foreach ($teams as $team) {
        foreach ([['outrights', 700], ['outrights', 650], ['season_wins', -110]] as $index => [$market, $price]) {
            $attributes = [
                'row_key' => sha1("{$team->id}-{$index}"),
                'sport' => 'nfl', 'season' => 2025,
                'odds_api_sport_key' => 'americanfootball_nfl_super_bowl_winner',
                'bookmaker' => 'draftkings', 'market_key' => $market,
                'outcome_name' => $team->name, 'price' => $price,
                'implied_probability' => 0.125, 'nfl_team_id' => $team->id,
            ];
            FuturesOdd::create([...$attributes, 'fetched_at' => $capturedAt]);
            FuturesOddsSnapshot::create([
                ...$attributes, 'snapshot_key' => sha1("snapshot-{$team->id}-{$index}"),
                'captured_at' => $capturedAt,
            ]);
        }
    }

    $lookup = app(FuturesOddsLookupService::class);
    $current = $lookup->byTeamForSeason('nfl', 2025, 'outrights');
    $historical = $lookup->byTeamForSeasonAt('nfl', 2025, $capturedAt);

    expect($current)->toHaveCount(2)
        ->and($historical)->toHaveCount(2);

    foreach ($teams as $team) {
        expect($current[$team->id]['price'])->toBe(650)
            ->and($current[$team->id]['market_key'])->toBe('outrights')
            ->and($historical[$team->id]['price'])->toBe(-110);
    }

    expect($lookup->byTeamForSeason('nfl', 2024))->toBe([])
        ->and($lookup->byTeamForSeasonAt('nfl', 2025, $capturedAt->copy()->subSecond()))->toBe([])
        ->and($lookup->byTeamForSeason('unsupported', 2025))->toBe([]);
});
