<?php

use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamMetricSnapshot;
use App\Models\NFL\EloRating;
use App\Models\NFL\TeamStat;
use Illuminate\Support\Facades\Artisan;

it('captures nfl team metric snapshots for a season', function () {
    $team = Team::factory()->create();

    TeamMetric::query()->create([
        'team_id' => $team->id,
        'season' => 2025,
        'wins' => 9,
        'losses' => 4,
        'predictive_rating' => 1542.5,
        'future_strength_of_schedule' => 1498.0,
        'recent_form_rating' => 0.35,
        'injury_total_adjustment' => -0.1,
        'calculation_date' => '2025-12-01',
    ]);

    Artisan::call('nfl:snapshot-team-metrics', [
        '--season' => 2025,
        '--date' => '2025-12-02T12:00:00Z',
    ]);

    $snapshot = TeamMetricSnapshot::query()->first();

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->season)->toBe(2025)
        ->and($snapshot->team_id)->toBe($team->id)
        ->and($snapshot->wins)->toBe(9)
        ->and($snapshot->losses)->toBe(4)
        ->and($snapshot->captured_at?->toIso8601String())->toContain('2025-12-02T12:00:00');
});

it('can backfill nfl team metric snapshots from game records across dates', function () {
    $team = Team::factory()->create([
        'abbreviation' => 'KC',
    ]);
    $opponent = Team::factory()->create([
        'abbreviation' => 'BUF',
    ]);

    TeamMetric::query()->create([
        'team_id' => $team->id,
        'season' => 2025,
        'wins' => 99,
        'losses' => 99,
        'predictive_rating' => 9999.0,
        'future_strength_of_schedule' => 9999.0,
        'recent_form_rating' => 9999.0,
        'injury_total_adjustment' => -99.0,
        'calculation_date' => '2026-01-10',
    ]);
    TeamMetric::query()->create([
        'team_id' => $opponent->id,
        'season' => 2025,
        'wins' => 10,
        'losses' => 7,
        'predictive_rating' => 1510.0,
        'future_strength_of_schedule' => 1502.0,
        'recent_form_rating' => 0.10,
        'injury_total_adjustment' => 0.0,
        'calculation_date' => '2026-01-10',
    ]);

    foreach ([
        [$team->id, 1510.0],
        [$opponent->id, 1490.0],
    ] as [$teamId, $elo]) {
        EloRating::query()->create([
            'team_id' => $teamId,
            'game_id' => null,
            'season' => 2024,
            'week' => 18,
            'date' => '2025-08-01',
            'elo_rating' => $elo,
            'elo_change' => 0.0,
        ]);
    }

    $firstGame = \App\Models\NFL\Game::factory()->create([
        'season' => 2025,
        'season_type' => config('nfl.season.types.regular'),
        'game_date' => '2025-09-10 12:00:00',
        'status' => config('nfl.statuses.final'),
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'home_score' => 24,
        'away_score' => 17,
    ]);
    TeamStat::query()->create([
        'team_id' => $team->id,
        'game_id' => $firstGame->id,
        'team_type' => 'home',
        'total_yards' => 350,
        'passing_yards' => 220,
        'passing_completions' => 20,
        'passing_attempts' => 31,
        'passing_touchdowns' => 2,
        'interceptions' => 0,
        'rushing_yards' => 130,
        'rushing_attempts' => 25,
        'rushing_touchdowns' => 1,
        'fumbles' => 1,
        'fumbles_lost' => 0,
    ]);
    TeamStat::query()->create([
        'team_id' => $opponent->id,
        'game_id' => $firstGame->id,
        'team_type' => 'away',
        'total_yards' => 300,
        'passing_yards' => 210,
        'passing_completions' => 18,
        'passing_attempts' => 30,
        'passing_touchdowns' => 1,
        'interceptions' => 1,
        'rushing_yards' => 90,
        'rushing_attempts' => 21,
        'rushing_touchdowns' => 1,
        'fumbles' => 2,
        'fumbles_lost' => 1,
    ]);
    EloRating::query()->create([
        'team_id' => $team->id,
        'game_id' => $firstGame->id,
        'season' => 2025,
        'week' => 1,
        'date' => '2025-09-10',
        'elo_rating' => 1520.0,
        'elo_change' => 10.0,
    ]);
    EloRating::query()->create([
        'team_id' => $opponent->id,
        'game_id' => $firstGame->id,
        'season' => 2025,
        'week' => 1,
        'date' => '2025-09-10',
        'elo_rating' => 1480.0,
        'elo_change' => -10.0,
    ]);

    \App\Models\NFL\Game::factory()->create([
        'season' => 2025,
        'season_type' => config('nfl.season.types.regular'),
        'game_date' => '2025-09-20 12:00:00',
        'status' => config('nfl.statuses.final'),
        'home_team_id' => $opponent->id,
        'away_team_id' => $team->id,
        'home_score' => 21,
        'away_score' => 14,
    ]);

    Artisan::call('nfl:snapshot-team-metrics', [
        '--season' => 2025,
        '--from-date' => '2025-09-15',
        '--to-date' => '2025-09-25',
        '--daily' => true,
        '--hour' => 12,
        '--backfill-records' => true,
    ]);

    $firstDateSnapshot = TeamMetricSnapshot::query()
        ->where('team_id', $team->id)
        ->where('captured_at', '2025-09-15 12:00:00')
        ->first();

    $secondDateSnapshot = TeamMetricSnapshot::query()
        ->where('team_id', $team->id)
        ->where('captured_at', '2025-09-25 12:00:00')
        ->first();

    expect($firstDateSnapshot)->not->toBeNull()
        ->and($firstDateSnapshot->wins)->toBe(1)
        ->and($firstDateSnapshot->losses)->toBe(0)
        ->and((float) $firstDateSnapshot->predictive_rating)->not->toBe(9999.0)
        ->and((float) $firstDateSnapshot->recent_form_rating)->toBe(7.0)
        ->and((float) $firstDateSnapshot->injury_total_adjustment)->toBe(0.0)
        ->and($secondDateSnapshot)->not->toBeNull()
        ->and($secondDateSnapshot->wins)->toBe(1)
        ->and($secondDateSnapshot->losses)->toBe(1);
});
