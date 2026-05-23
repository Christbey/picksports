<?php

use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Player as NflPlayer;
use App\Models\NFL\PlayerStat as NflPlayerStat;
use App\Models\NFL\Team as NflTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns matchup context rows for mlb game detail', function () {
    $homeTeam = MlbTeam::factory()->create([
        'name' => 'Giants',
        'abbreviation' => 'SF',
        'division' => 'West',
    ]);
    $awayTeam = MlbTeam::factory()->create([
        'name' => 'Yankees',
        'abbreviation' => 'NYY',
        'division' => 'East',
    ]);
    $otherTeam = MlbTeam::factory()->create();

    $homePitcher = MlbPlayer::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '5001',
        'full_name' => 'Logan Webb',
    ]);
    $awayPitcher = MlbPlayer::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '5002',
        'full_name' => 'Gerrit Cole',
    ]);

    MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-20',
        'game_time' => '18:05:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 2,
        'away_score' => 4,
    ]);

    MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-22',
        'game_time' => '19:05:00',
        'home_team_id' => $otherTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 1,
        'away_score' => 5,
        'probable_home_pitcher_espn_id' => $homePitcher->espn_id,
    ]);

    MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-23',
        'game_time' => '19:10:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $otherTeam->id,
        'home_score' => 3,
        'away_score' => 6,
        'probable_away_pitcher_espn_id' => $awayPitcher->espn_id,
    ]);

    $currentGame = MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-25',
        'game_time' => '19:05:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => $homePitcher->espn_id,
        'probable_away_pitcher_espn_id' => $awayPitcher->espn_id,
    ]);

    $response = $this->getJson("/api/v1/mlb/games/{$currentGame->id}");

    $response->assertOk();
    expect($response->json('data.probable_home_pitcher_espn_id'))->toBe('5001')
        ->and($response->json('data.probable_away_pitcher_espn_id'))->toBe('5002')
        ->and($response->json('data.home_starting_pitcher.full_name'))->toBe('Logan Webb')
        ->and($response->json('data.away_starting_pitcher.full_name'))->toBe('Gerrit Cole');

    $rows = collect($response->json('data.matchup_context.rows'));

    expect($rows->pluck('key')->all())->toContain('head_to_head', 'overall', 'role_record', 'time_bucket_record', 'starter_matchup');

    $headToHead = $rows->firstWhere('key', 'head_to_head');
    expect($headToHead['away']['display'])->toBe('1-0')
        ->and($headToHead['home']['display'])->toBe('0-1');

    $starterRow = $rows->firstWhere('key', 'starter_matchup');
    expect($starterRow['away']['display'])->toBe('1-0')
        ->and($starterRow['home']['display'])->toBe('0-1');
});

it('returns matchup context rows for nfl game detail', function () {
    $homeTeam = NflTeam::factory()->create([
        'abbreviation' => 'KC',
        'conference' => 'AFC',
        'division' => 'West',
    ]);
    $awayTeam = NflTeam::factory()->create([
        'abbreviation' => 'DEN',
        'conference' => 'AFC',
        'division' => 'West',
    ]);

    $homeQuarterback = NflPlayer::factory()->create([
        'team_id' => $homeTeam->id,
        'position' => 'QB',
        'full_name' => 'Patrick Mahomes',
    ]);
    $awayQuarterback = NflPlayer::factory()->create([
        'team_id' => $awayTeam->id,
        'position' => 'QB',
        'full_name' => 'Bo Nix',
    ]);

    $priorGame = NflGame::factory()->create([
        'season' => 2026,
        'season_type' => 'Regular Season',
        'status' => 'STATUS_FINAL',
        'week' => 1,
        'game_date' => '2026-09-10',
        'game_time' => '19:20:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 31,
        'away_score' => 24,
    ]);

    NflPlayerStat::query()->create([
        'player_id' => $homeQuarterback->id,
        'game_id' => $priorGame->id,
        'team_id' => $homeTeam->id,
        'passing_attempts' => 33,
        'passing_completions' => 24,
        'passing_yards' => 301,
        'passing_touchdowns' => 3,
    ]);

    NflPlayerStat::query()->create([
        'player_id' => $awayQuarterback->id,
        'game_id' => $priorGame->id,
        'team_id' => $awayTeam->id,
        'passing_attempts' => 29,
        'passing_completions' => 18,
        'passing_yards' => 224,
        'passing_touchdowns' => 2,
    ]);

    $currentGame = NflGame::factory()->create([
        'season' => 2026,
        'season_type' => 'Regular Season',
        'status' => 'STATUS_SCHEDULED',
        'week' => 2,
        'game_date' => '2026-09-17',
        'game_time' => '19:20:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $response = $this->getJson("/api/v1/nfl/games/{$currentGame->id}");

    $response->assertOk();
    $rows = collect($response->json('data.matchup_context.rows'));

    expect($rows->pluck('key')->all())->toContain(
        'head_to_head',
        'overall',
        'role_record',
        'conference_record',
        'division_record',
        'time_bucket_record',
        'starter_matchup',
    );

    $starterRow = $rows->firstWhere('key', 'starter_matchup');
    expect($starterRow['away']['display'])->toBe('0-1')
        ->and($starterRow['home']['display'])->toBe('1-0');
});

it('treats equivalent nba season type values as the same matchup bucket', function () {
    $homeTeam = NbaTeam::factory()->create([
        'abbreviation' => 'CHA',
        'conference' => 'Eastern',
        'division' => 'Southeast',
    ]);
    $awayTeam = NbaTeam::factory()->create([
        'abbreviation' => 'PHI',
        'conference' => 'Eastern',
        'division' => 'Atlantic',
    ]);
    $otherEastTeam = NbaTeam::factory()->create([
        'conference' => 'Eastern',
        'division' => 'Central',
    ]);
    $otherWestTeam = NbaTeam::factory()->create([
        'conference' => 'Western',
        'division' => 'Pacific',
    ]);

    NbaGame::factory()->create([
        'season' => 2026,
        'season_type' => 'Regular Season',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-02-19',
        'game_time' => '18:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 110,
        'away_score' => 104,
    ]);

    NbaGame::factory()->create([
        'season' => 2026,
        'season_type' => 'Regular Season',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-02-21',
        'game_time' => '18:00:00',
        'home_team_id' => $otherEastTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 99,
        'away_score' => 108,
    ]);

    NbaGame::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-01',
        'game_time' => '18:30:00',
        'home_team_id' => $awayTeam->id,
        'away_team_id' => $otherWestTeam->id,
        'home_score' => 112,
        'away_score' => 101,
    ]);

    NbaGame::factory()->create([
        'season' => 2026,
        'season_type' => 'Regular Season',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-02',
        'game_time' => '18:30:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $otherEastTeam->id,
        'home_score' => 115,
        'away_score' => 109,
    ]);

    $currentGame = NbaGame::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-28',
        'game_time' => '17:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $response = $this->getJson("/api/v1/nba/games/{$currentGame->id}");

    $response->assertOk();
    $rows = collect($response->json('data.matchup_context.rows'));

    $headToHead = $rows->firstWhere('key', 'head_to_head');
    expect($headToHead['away']['display'])->toBe('0-1')
        ->and($headToHead['home']['display'])->toBe('1-0');

    $overall = $rows->firstWhere('key', 'overall');
    expect($overall['away']['display'])->toBe('2-1')
        ->and($overall['home']['display'])->toBe('2-0');

    $role = $rows->firstWhere('key', 'role_record');
    expect($role['away']['display'])->toBe('1-1')
        ->and($role['home']['display'])->toBe('2-0');

    $conference = $rows->firstWhere('key', 'conference_record');
    expect($conference['away']['display'])->toBe('1-1')
        ->and($conference['home']['display'])->toBe('2-0');

    $division = $rows->firstWhere('key', 'division_record');
    expect($division['away']['display'])->toBe('0-0')
        ->and($division['home']['display'])->toBe('0-0');

    $timeBucket = $rows->firstWhere('key', 'time_bucket_record');
    expect($timeBucket['label'])->toBe('Night record')
        ->and($timeBucket['away']['display'])->toBe('2-1')
        ->and($timeBucket['home']['display'])->toBe('2-0');
});

it('includes regular season matchup records when the nba game is postseason', function () {
    $homeTeam = NbaTeam::factory()->create([
        'abbreviation' => 'CLE',
        'conference' => 'Eastern',
        'division' => 'Central',
    ]);
    $awayTeam = NbaTeam::factory()->create([
        'abbreviation' => 'NY',
        'conference' => 'Eastern',
        'division' => 'Atlantic',
    ]);

    NbaGame::factory()->create([
        'season' => 2026,
        'season_type' => 'Preseason',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-10-10',
        'game_time' => '18:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 118,
        'away_score' => 101,
    ]);

    NbaGame::factory()->create([
        'season' => 2026,
        'season_type' => 'Regular Season',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-10-22',
        'game_time' => '18:00:00',
        'home_team_id' => $awayTeam->id,
        'away_team_id' => $homeTeam->id,
        'home_score' => 119,
        'away_score' => 111,
    ]);

    NbaGame::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-02-24',
        'game_time' => '18:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 109,
        'away_score' => 94,
    ]);

    NbaGame::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-05-20',
        'game_time' => '19:00:00',
        'home_team_id' => $awayTeam->id,
        'away_team_id' => $homeTeam->id,
        'home_score' => 104,
        'away_score' => 97,
    ]);

    $currentGame = NbaGame::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-05-23',
        'game_time' => '19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $response = $this->getJson("/api/v1/nba/games/{$currentGame->id}");

    $response->assertOk();
    $rows = collect($response->json('data.matchup_context.rows'));

    $headToHead = $rows->firstWhere('key', 'head_to_head');
    expect($headToHead['subtitle'])->toBe('Current season series before game')
        ->and($headToHead['away']['display'])->toBe('2-1')
        ->and($headToHead['home']['display'])->toBe('1-2');

    $overall = $rows->firstWhere('key', 'overall');
    expect($overall['subtitle'])->toBe('Regular + postseason before game')
        ->and($overall['away']['display'])->toBe('2-1')
        ->and($overall['home']['display'])->toBe('1-2');
});
