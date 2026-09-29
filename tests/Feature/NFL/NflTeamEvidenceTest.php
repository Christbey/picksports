<?php

use App\Actions\NFL\CalculateTeamTrends;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Models\User;
use App\Services\NFL\NflTeamEvidenceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function evidenceFixture(): array
{
    $team = Team::factory()->create();
    $away = Team::factory()->create();
    $games = collect();
    for ($i = 0; $i < 15; $i++) {
        $games->push(Game::factory()->create([
            'home_team_id' => $team->id, 'away_team_id' => $away->id,
            'season' => $i < 2 ? 2026 : 2025, 'season_type' => '2',
            'game_date' => $i < 2 ? '2026-09-'.(13 - $i * 7) : '2025-12-'.(30 - $i),
            'game_time' => '17:00:00', 'status' => 'STATUS_FINAL',
            'home_score' => $i < 5 ? 30 : 20, 'away_score' => 20,
        ]));
    }

    return [$team, $away, $games];
}

it('builds distinct windows in one load and compares nonoverlapping samples', function () {
    [$team, , $games] = evidenceFixture();
    $calculator = Mockery::mock(CalculateTeamTrends::class)->makePartial();
    $calculator->shouldReceive('summarizeGames')->times(4)->passthru();
    $this->app->instance(CalculateTeamTrends::class, $calculator);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $profile = app(NflTeamEvidenceService::class)->build($team, 2026, '2026-09-18T00:15:00Z');
        expect(DB::getQueryLog())->toHaveCount(7);
    } finally {
        DB::disableQueryLog();
    }
    expect($profile['windows']['recent_5']['sample_size'])->toBe(5)
        ->and($profile['windows']['recent_10']['sample_size'])->toBe(10)
        ->and($profile['windows']['season']['sample_size'])->toBe(2)
        ->and($profile['windows']['historical']['sample_size'])->toBe(13)
        ->and($profile['windows']['recent_5']['evidence']['seasons'])->toBe([2025, 2026])
        ->and(array_intersect($profile['windows']['recent_5']['evidence']['game_ids'], $profile['comparison_baseline']['game_ids']))->toBe([])
        ->and($profile['changes'][0]['delta'])->toBe(10.0)
        ->and($profile['windows']['historical']['evidence']['record'])->toBe(['wins' => 3, 'losses' => 0, 'ties' => 10]);
});

it('excludes preseason future target and more than three prior seasons using UTC cutoff', function () {
    [$team, $away, $games] = evidenceFixture();
    foreach ([['2026-09-18', '00:15:00', 2026, '2'], ['2026-09-20', '17:00:00', 2026, '2'],
        ['2026-08-20', '17:00:00', 2026, '1'], ['2022-10-20', '17:00:00', 2022, '2']] as [$date, $time, $season, $phase]) {
        Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $away->id,
            'game_date' => $date, 'game_time' => $time, 'season' => $season, 'season_type' => $phase,
            'status' => 'STATUS_FINAL', 'home_score' => 99, 'away_score' => 0]);
    }
    $profile = app(NflTeamEvidenceService::class)->build($team, 2026, '2026-09-17T19:15:00-05:00');
    expect($profile['cutoff_at'])->toBe('2026-09-18T00:15:00+00:00')
        ->and($profile['windows']['season']['sample_size'])->toBe(2)
        ->and($profile['windows']['historical']['sample_size'])->toBe(13);
});

it('reports field level coverage and does not turn absent turnovers into zero', function () {
    [$team, , $games] = evidenceFixture();
    TeamStat::create(['game_id' => $games[0]->id, 'team_id' => $team->id, 'team_type' => 'home',
        'total_yards' => 400, 'passing_attempts' => 35, 'rushing_attempts' => 25, 'sacks_allowed' => 4,
        'third_down_attempts' => 10, 'third_down_conversions' => 4]);
    $profile = app(NflTeamEvidenceService::class)->build($team, 2026, '2026-09-18T00:15:00Z');
    $metrics = $profile['windows']['recent_5']['evidence']['metrics'];
    expect($metrics['yards_per_play']['value'])->toBe(6.25)
        ->and($metrics['yards_per_play']['sample_size'])->toBe(1)
        ->and($metrics['turnovers']['value'])->toBeNull()
        ->and($metrics['turnovers']['sample_size'])->toBe(0)
        ->and($profile['changes'][3]['delta'])->toBeNull();
});

it('rejects invalid event counts but preserves recorded zero counts and per-game rate averages', function () {
    [$team, , $games] = evidenceFixture();
    TeamStat::create(['game_id' => $games[0]->id, 'team_id' => $team->id, 'team_type' => 'home',
        'total_yards' => 100, 'passing_attempts' => 10, 'rushing_attempts' => 10, 'sacks_allowed' => -1,
        'interceptions' => -1, 'fumbles_lost' => 1,
        'third_down_attempts' => 2, 'third_down_conversions' => 3,
        'red_zone_attempts' => 0, 'red_zone_scores' => 0]);
    TeamStat::create(['game_id' => $games[1]->id, 'team_id' => $team->id, 'team_type' => 'home',
        'total_yards' => 100, 'passing_attempts' => 10, 'rushing_attempts' => 10, 'sacks_allowed' => 0,
        'interceptions' => 0, 'fumbles_lost' => 0,
        'third_down_attempts' => 10, 'third_down_conversions' => 0,
        'red_zone_attempts' => 1, 'red_zone_scores' => 0]);
    TeamStat::create(['game_id' => $games[2]->id, 'team_id' => $team->id, 'team_type' => 'home',
        'third_down_attempts' => 2, 'third_down_conversions' => 2]);

    $profile = app(NflTeamEvidenceService::class)->build($team, 2026, '2026-09-18T00:15:00Z');
    $metrics = $profile['windows']['recent_5']['evidence']['metrics'];
    expect($metrics['yards_per_play']['value'])->toBe(5.0)
        ->and($metrics['yards_per_play']['sample_size'])->toBe(1)
        ->and($metrics['turnovers']['value'])->toBe(0.0)
        ->and($metrics['turnovers']['sample_size'])->toBe(1)
        ->and($metrics['third_down_pct']['value'])->toBe(50.0)
        ->and($metrics['third_down_pct']['sample_size'])->toBe(2)
        ->and($metrics['red_zone_pct']['value'])->toBe(0.0)
        ->and($metrics['red_zone_pct']['sample_size'])->toBe(1);
});

it('withholds model context recomputed after source kickoff even before the target game', function () {
    [$team, , $games] = evidenceFixture();
    foreach ($games as $game) {
        Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 10,
            'updated_at' => '2026-09-15 00:00:00']);
    }
    $profile = app(NflTeamEvidenceService::class)->build($team, 2026, '2026-09-18T00:15:00Z');
    expect($profile['windows']['recent_5']['trends'])->not->toHaveKey('advanced');
});

it('returns empty evidence rather than fabricated baselines and caches profile requests separately', function () {
    Cache::flush();
    $team = Team::factory()->create();
    Sanctum::actingAs(User::factory()->create());
    $url = "/api/v2/sports/nfl/teams/{$team->id}/trends?profile=team_analysis&season=2026&before_date=2026-09-18";
    $first = $this->getJson($url)->assertOk()->assertJsonPath('data.sample_size', 0)
        ->assertJsonPath('data.team_id', $team->id)
        ->assertJsonPath('data.team_name', $team->display_name ?? $team->name)
        ->assertJsonStructure(['data' => ['user_tier']])
        ->assertJsonPath('data.team_evidence.windows.season.sample_size', 0)
        ->assertJsonPath('data.team_evidence.changes.0.delta', null);
    expect($first->json('data.team_evidence.windows.recent_5.evidence.metrics.points_for.value'))->toBeNull();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->getJson($url)->assertOk();
        expect(collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'nfl_games')))->toHaveCount(0);
    } finally {
        DB::disableQueryLog();
    }
    $this->getJson("/api/v2/sports/nfl/teams/{$team->id}/trends?season=2026")
        ->assertOk()->assertJsonMissingPath('data.team_evidence');
});

it('ranks each window across the league with defensive direction ties and missing values', function () {
    Cache::flush();
    [$team, $opponent, $games] = evidenceFixture();
    $peer = Team::factory()->create();
    $other = Team::factory()->create();
    Game::factory()->create(['home_team_id' => $peer->id, 'away_team_id' => $other->id,
        'game_date' => '2026-09-13', 'game_time' => '17:00:00', 'season' => 2026,
        'season_type' => '2', 'status' => 'STATUS_FINAL', 'home_score' => 30, 'away_score' => 10]);
    Game::factory()->create(['home_team_id' => $peer->id, 'away_team_id' => $other->id,
        'game_date' => '2025-09-13', 'game_time' => '17:00:00', 'season' => 2025,
        'season_type' => '2', 'status' => 'STATUS_FINAL', 'home_score' => 50, 'away_score' => 0]);
    // Neither the target kickoff nor exhibitions may change the league ranks.
    foreach (['2', '1'] as $phase) {
        Game::factory()->create(['home_team_id' => $other->id, 'away_team_id' => $peer->id,
            'game_date' => $phase === '2' ? '2026-09-18' : '2026-08-20', 'game_time' => '00:15:00',
            'season' => 2026, 'season_type' => $phase, 'status' => 'STATUS_FINAL',
            'home_score' => 100, 'away_score' => 0]);
    }
    TeamStat::create(['game_id' => $games[0]->id, 'team_id' => $team->id,
        'team_type' => 'home', 'interceptions' => 0, 'fumbles_lost' => 0, 'total_yards' => 300]);
    TeamStat::create(['game_id' => $games[0]->id, 'team_id' => $opponent->id,
        'team_type' => 'away', 'interceptions' => 1, 'fumbles_lost' => 0, 'total_yards' => 200]);
    $windows = app(NflTeamEvidenceService::class)->build($team, 2026, '2026-09-18T00:15:00Z')['windows'];
    $season = $windows['season']['evidence']['metrics'];
    expect($season['points_for']['rank'])->toBe(1)
        ->and($season['points_for']['rank_tied'])->toBeTrue()
        ->and($season['points_for']['ranked_teams'])->toBe(4)
        ->and($windows['recent_5']['evidence']['metrics']['points_for']['rank'])->toBe(2)
        ->and($windows['recent_10']['evidence']['metrics']['points_for']['rank'])->toBe(2)
        ->and($windows['historical']['evidence']['metrics']['points_for']['rank'])->toBe(2)
        ->and($season['points_against']['rank'])->toBe(2)
        ->and($season['turnovers']['rank'])->toBe(1)
        ->and($season['turnovers']['ranked_teams'])->toBe(2)
        ->and($season['yards_allowed']['rank'])->toBe(1)
        ->and($season['yards_per_play']['rank'])->toBeNull()
        ->and($season['yards_per_play']['ranked_teams'])->toBe(0);
});
