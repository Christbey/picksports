<?php

use App\Models\MLB\DepthChartEntry as MlbDepthChartEntry;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\PlayerStat as MlbPlayerStat;
use App\Models\MLB\Team as MlbTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('requires authenticated access for v2 game depth charts', function () {
    $this->getJson('/api/v2/sports/mlb/teams/1/depth-charts')
        ->assertUnauthorized();

    $this->getJson('/api/v2/sports/mlb/games/1/depth-charts')
        ->assertUnauthorized();
});

it('returns v2 team depth charts with stable metadata, filters, and stat summaries', function () {
    Sanctum::actingAs(User::factory()->create());

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

    $this->getJson("/api/v2/sports/mlb/teams/{$awayTeam->id}/depth-charts?season=2026")
        ->assertOk()
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', 'mlb')
        ->assertJsonPath('meta.team_id', $awayTeam->id)
        ->assertJsonPath('meta.contract', 'sports.teams.depth-charts.show')
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('data.team.id', $awayTeam->id)
        ->assertJsonPath('data.entries.0.full_name', 'Miles Example')
        ->assertJsonPath('data.entries.0.stats.metrics.0.label', 'IP')
        ->assertJsonPath('data.entries.0.stats.metrics.1.label', 'ERA')
        ->assertJsonPath('data.entries.0.stats.metrics.2.value', '8')
        ->assertJsonStructure([
            'data' => [
                'team',
                'season',
                'season_type',
                'before_date',
                'entries',
            ],
            'meta' => [
                'version',
                'sport',
                'team_id',
                'contract',
                'filters',
                'tier',
                'freshness',
                'warnings',
            ],
        ]);
});

it('returns v2 game depth charts with stable metadata and stat summaries', function () {
    Sanctum::actingAs(User::factory()->create());

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

    $this->getJson("/api/v2/sports/mlb/games/{$targetGame->id}/depth-charts")
        ->assertOk()
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', 'mlb')
        ->assertJsonPath('meta.game_id', $targetGame->id)
        ->assertJsonPath('meta.contract', 'sports.games.depth-charts.show')
        ->assertJsonPath('data.game_id', $targetGame->id)
        ->assertJsonPath('data.away_team.entries.0.full_name', 'Miles Example')
        ->assertJsonPath('data.away_team.entries.0.stats.metrics.0.label', 'IP')
        ->assertJsonPath('data.away_team.entries.0.stats.metrics.1.label', 'ERA')
        ->assertJsonPath('data.away_team.entries.0.stats.metrics.2.value', '8')
        ->assertJsonStructure([
            'data' => [
                'game_id',
                'season',
                'season_type',
                'game_date',
                'away_team' => [
                    'team',
                    'season',
                    'season_type',
                    'before_date',
                    'entries',
                ],
                'home_team',
            ],
            'meta' => [
                'version',
                'sport',
                'game_id',
                'contract',
                'tier',
                'freshness',
                'warnings',
            ],
        ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $this->getJson("/api/v2/sports/mlb/games/{$targetGame->id}/page")
        ->assertOk()
        ->assertJsonPath('data.depth_charts_available', true)
        ->assertJsonMissingPath('data.depth_charts');

    expect(collect($queries)->contains(
        fn (string $sql): bool => str_contains($sql, 'mlb_player_stats'),
    ))->toBeFalse();
});

it('returns a clean json 404 for v2 sports without depth chart support', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/cfb/games/1/depth-charts')
        ->assertNotFound()
        ->assertJsonPath('message', 'Depth charts are not supported for sport: cfb');
});
