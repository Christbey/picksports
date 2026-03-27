<?php

use App\Models\MLB\DepthChartEntry as MlbDepthChartEntry;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\PlayerStat as MlbPlayerStat;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\DepthChartEntry as NbaDepthChartEntry;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Player as NbaPlayer;
use App\Models\NBA\PlayerStat as NbaPlayerStat;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\DepthChartEntry as NflDepthChartEntry;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Player as NflPlayer;
use App\Models\NFL\PlayerStat as NflPlayerStat;
use App\Models\NFL\Team as NflTeam;

uses()->group('sports');

it('returns nfl game depth charts with position-aware stat summaries', function () {
    $homeTeam = NflTeam::factory()->create(['abbreviation' => 'CHI']);
    $awayTeam = NflTeam::factory()->create(['abbreviation' => 'GB']);
    $quarterback = NflPlayer::factory()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '1200',
        'full_name' => 'Caleb Example',
        'position' => 'QB',
    ]);

    NflDepthChartEntry::create([
        'team_id' => $homeTeam->id,
        'player_id' => $quarterback->id,
        'season' => 2025,
        'espn_depth_chart_id' => '1',
        'depth_chart_name' => 'Depth Chart',
        'position_slot_key' => 'qb',
        'position_code' => 'QB',
        'position_name' => 'Quarterback',
        'position_display_name' => 'Quarterback',
        'espn_athlete_id' => '1200',
        'depth_rank' => 1,
        'is_starter' => true,
        'source_updated_at' => now(),
    ]);

    $priorGame = NflGame::factory()->create([
        'season' => 2025,
        'season_type' => 'regular',
        'game_date' => '2025-09-01',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    NflPlayerStat::create([
        'player_id' => $quarterback->id,
        'game_id' => $priorGame->id,
        'team_id' => $homeTeam->id,
        'passing_yards' => 275,
        'passing_touchdowns' => 2,
        'interceptions_thrown' => 1,
        'rushing_yards' => 31,
        'rushing_touchdowns' => 0,
    ]);

    $targetGame = NflGame::factory()->create([
        'season' => 2025,
        'season_type' => 'regular',
        'game_date' => '2025-09-10',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $response = $this->getJson("/api/v1/nfl/games/{$targetGame->id}/depth-charts");

    $response->assertOk()
        ->assertJsonPath('data.home_team.entries.0.full_name', 'Caleb Example')
        ->assertJsonPath('data.home_team.entries.0.stats.games_played', 1)
        ->assertJsonPath('data.home_team.entries.0.stats.metrics.0.label', 'Pass Yds')
        ->assertJsonPath('data.home_team.entries.0.stats.metrics.0.value', '275');
});

it('returns nba game depth charts with per-game stat summaries', function () {
    $homeTeam = NbaTeam::factory()->create(['abbreviation' => 'BOS']);
    $awayTeam = NbaTeam::factory()->create(['abbreviation' => 'NYK']);
    $guard = NbaPlayer::factory()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '2200',
        'full_name' => 'Jalen Example',
        'position' => 'PG',
    ]);

    NbaDepthChartEntry::create([
        'team_id' => $awayTeam->id,
        'player_id' => $guard->id,
        'season' => 2025,
        'espn_depth_chart_id' => '1',
        'depth_chart_name' => 'Depth Chart',
        'position_slot_key' => 'pg',
        'position_code' => 'PG',
        'position_name' => 'Point Guard',
        'position_display_name' => 'Point Guard',
        'espn_athlete_id' => '2200',
        'depth_rank' => 1,
        'is_starter' => true,
        'source_updated_at' => now(),
    ]);

    $priorGame = NbaGame::factory()->create([
        'season' => 2025,
        'season_type' => 2,
        'game_date' => '2025-11-01',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $awayTeam->id,
        'away_team_id' => $homeTeam->id,
    ]);

    NbaPlayerStat::factory()->create([
        'player_id' => $guard->id,
        'game_id' => $priorGame->id,
        'team_id' => $awayTeam->id,
        'points' => 30,
        'rebounds_total' => 5,
        'assists' => 7,
        'steals' => 2,
        'blocks' => 1,
    ]);

    $targetGame = NbaGame::factory()->create([
        'season' => 2025,
        'season_type' => 2,
        'game_date' => '2025-11-10',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $response = $this->getJson("/api/v1/nba/games/{$targetGame->id}/depth-charts");

    $response->assertOk()
        ->assertJsonPath('data.away_team.entries.0.full_name', 'Jalen Example')
        ->assertJsonPath('data.away_team.entries.0.stats.metrics.0.label', 'PPG')
        ->assertJsonPath('data.away_team.entries.0.stats.metrics.0.value', '30.0');
});

it('returns mlb game depth charts with pitcher stat summaries', function () {
    $homeTeam = MlbTeam::factory()->create(['abbreviation' => 'CHC']);
    $awayTeam = MlbTeam::factory()->create(['abbreviation' => 'STL']);
    $pitcher = MlbPlayer::factory()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '3200',
        'full_name' => 'Miles Example',
        'position' => 'P',
    ]);

    MlbDepthChartEntry::create([
        'team_id' => $awayTeam->id,
        'player_id' => $pitcher->id,
        'season' => 2026,
        'espn_depth_chart_id' => '1',
        'depth_chart_name' => 'Depth Chart',
        'position_slot_key' => 'sp',
        'position_code' => 'SP',
        'position_name' => 'Starting Pitcher',
        'position_display_name' => 'Starting Pitcher',
        'espn_athlete_id' => '3200',
        'depth_rank' => 1,
        'is_starter' => true,
        'source_updated_at' => now(),
    ]);

    $priorGame = MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'game_date' => '2026-03-20',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $awayTeam->id,
        'away_team_id' => $homeTeam->id,
    ]);

    MlbPlayerStat::factory()->pitching()->create([
        'player_id' => $pitcher->id,
        'game_id' => $priorGame->id,
        'team_id' => $awayTeam->id,
        'innings_pitched' => 6.0,
        'earned_runs' => 2,
        'walks_allowed' => 1,
        'hits_allowed' => 4,
        'strikeouts_pitched' => 8,
    ]);

    $targetGame = MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'game_date' => '2026-03-25',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $response = $this->getJson("/api/v1/mlb/games/{$targetGame->id}/depth-charts");

    $response->assertOk()
        ->assertJsonPath('data.away_team.entries.0.full_name', 'Miles Example')
        ->assertJsonPath('data.away_team.entries.0.stats.metrics.0.label', 'IP')
        ->assertJsonPath('data.away_team.entries.0.stats.metrics.1.label', 'ERA')
        ->assertJsonPath('data.away_team.entries.0.stats.metrics.2.value', '8');
});
