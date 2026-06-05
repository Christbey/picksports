<?php

use App\Support\Api\V1ReplacementPathResolver;

it('maps legacy app api paths to their v2 aliases', function (string $legacyPath, string $replacementPath) {
    expect(app(V1ReplacementPathResolver::class)->resolve($legacyPath))
        ->toBe($replacementPath);
})->with([
    ['api/v1/user-bets', '/api/v2/user-bets'],
    ['api/v1/user-bets/123', '/api/v2/user-bets/123'],
    ['api/v1/cbb-brackets/current', '/api/v2/cbb-brackets/current'],
    ['api/v1/groups/abc123', '/api/v2/groups/abc123'],
    ['api/v1/alert-preferences', '/api/v2/alert-preferences'],
]);

it('maps legacy sport api paths to their v2 equivalents', function (string $legacyPath, string $replacementPath) {
    expect(app(V1ReplacementPathResolver::class)->resolve($legacyPath))
        ->toBe($replacementPath);
})->with([
    ['api/v1/mlb/teams', '/api/v2/sports/mlb/teams'],
    ['api/v1/mlb/team-metrics', '/api/v2/sports/mlb/metrics/teams'],
    ['api/v1/mlb/team-metrics/45', '/api/v2/sports/mlb/metrics/teams/45'],
    ['api/v1/mlb/player-stats', '/api/v2/sports/mlb/stats/player'],
    ['api/v1/mlb/player-stats/leaderboard', '/api/v2/sports/mlb/leaderboards/players'],
    ['api/v1/mlb/team-stats', '/api/v2/sports/mlb/stats/team'],
    ['api/v1/mlb/games/99/player-stats', '/api/v2/sports/mlb/stats/player?game_id=99'],
    ['api/v1/mlb/games/99/team-stats', '/api/v2/sports/mlb/stats/team?game_id=99'],
    ['api/v1/mlb/players/12/stats', '/api/v2/sports/mlb/stats/player?player_id=12'],
    ['api/v1/mlb/teams/34/stats', '/api/v2/sports/mlb/stats/team?team_id=34'],
    ['api/v1/mlb/teams/34/stats/season-averages', '/api/v2/sports/mlb/teams/34/stats/season-averages'],
    ['api/v1/mlb/player-props', '/api/v2/sports/mlb/markets/player-props'],
    ['api/v1/mlb/playoff-forecasts', '/api/v2/sports/mlb/forecasts'],
    ['api/v1/cbb/tournament-forecasts', '/api/v2/sports/cbb/forecasts'],
]);

it('falls back to api v2 for unknown legacy paths', function () {
    expect(app(V1ReplacementPathResolver::class)->resolve('api/v1/not-real'))
        ->toBe('/api/v2');
});
