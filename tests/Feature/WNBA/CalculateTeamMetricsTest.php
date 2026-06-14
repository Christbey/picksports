<?php

use App\Actions\WNBA\CalculateTeamMetrics;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamMetric;
use App\Models\WNBA\TeamStat;

uses()->group('wnba', 'team-metrics');

beforeEach(function () {
    $this->team = Team::factory()->create(['elo_rating' => 1500]);
    $this->opponent1 = Team::factory()->create(['elo_rating' => 1510]);
    $this->opponent2 = Team::factory()->create(['elo_rating' => 1490]);
});

it('stores regular season and postseason metrics separately', function () {
    $regularGame = createWnbaCompletedGameWithStats(
        team: $this->team,
        opponent: $this->opponent1,
        seasonType: config('wnba.season.types.regular', 2),
        teamPoints: 84,
        opponentPoints: 78,
        teamPossessions: 82.0,
        opponentPossessions: 83.0,
    );

    $postseasonGame = createWnbaCompletedGameWithStats(
        team: $this->team,
        opponent: $this->opponent2,
        seasonType: config('wnba.season.types.postseason', 3),
        teamPoints: 98,
        opponentPoints: 86,
        teamPossessions: 80.0,
        opponentPossessions: 81.0,
    );

    expect($regularGame->season_type)->not->toBe($postseasonGame->season_type);

    $action = new CalculateTeamMetrics;
    $regularMetric = $action->execute($this->team, 2026, config('wnba.season.types.regular', 2));
    $postseasonMetric = $action->execute($this->team, 2026, config('wnba.season.types.postseason', 3));

    expect($regularMetric)->not->toBeNull()
        ->and($regularMetric->season_type)->toBe((string) config('wnba.season.types.regular', 2))
        ->and($postseasonMetric)->not->toBeNull()
        ->and($postseasonMetric->season_type)->toBe((string) config('wnba.season.types.postseason', 3))
        ->and(TeamMetric::query()->where('team_id', $this->team->id)->where('season', 2026)->count())->toBe(2)
        ->and((float) $postseasonMetric->offensive_efficiency)->toBeGreaterThan((float) $regularMetric->offensive_efficiency);
});

it('defaults untyped calculations to regular season instead of blending postseason games', function () {
    createWnbaCompletedGameWithStats(
        team: $this->team,
        opponent: $this->opponent1,
        seasonType: config('wnba.season.types.regular', 2),
        teamPoints: 80,
        opponentPoints: 76,
        teamPossessions: 80.0,
        opponentPossessions: 81.0,
    );

    createWnbaCompletedGameWithStats(
        team: $this->team,
        opponent: $this->opponent2,
        seasonType: config('wnba.season.types.postseason', 3),
        teamPoints: 110,
        opponentPoints: 90,
        teamPossessions: 80.0,
        opponentPossessions: 81.0,
    );

    $metric = (new CalculateTeamMetrics)->execute($this->team, 2026);

    expect($metric)->not->toBeNull()
        ->and($metric->season_type)->toBe((string) config('wnba.season.types.regular', 2))
        ->and((float) $metric->offensive_efficiency)->toBe(100.0)
        ->and(TeamMetric::query()->where('team_id', $this->team->id)->where('season', 2026)->count())->toBe(1);
});

function createWnbaCompletedGameWithStats(
    Team $team,
    Team $opponent,
    int|string $seasonType,
    int $teamPoints,
    int $opponentPoints,
    float $teamPossessions,
    float $opponentPossessions,
): Game {
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) $seasonType,
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'status' => 'STATUS_FINAL',
        'home_score' => $teamPoints,
        'away_score' => $opponentPoints,
    ]);

    TeamStat::factory()->create([
        'team_id' => $team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'points' => $teamPoints,
        'possessions' => $teamPossessions,
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $game->id,
        'team_type' => 'away',
        'points' => $opponentPoints,
        'possessions' => $opponentPossessions,
    ]);

    return $game;
}
