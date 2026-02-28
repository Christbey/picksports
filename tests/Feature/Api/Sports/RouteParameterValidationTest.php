<?php

it('returns 404 for non-numeric identifiers on numeric sports endpoints', function (string $path) {
    $this->getJson($path)->assertNotFound();
})->with([
    '/api/v1/nba/teams/not-a-number',
    '/api/v1/nba/teams/not-a-number/trends',
    '/api/v1/nba/players/not-a-number',
    '/api/v1/nba/teams/not-a-number/players',
    '/api/v1/nba/games/not-a-number',
    '/api/v1/nba/teams/not-a-number/games',
    '/api/v1/nba/games/season/not-a-number',
    '/api/v1/nba/games/season/2025/week/not-a-number',
    '/api/v1/nba/plays/not-a-number',
    '/api/v1/nba/games/not-a-number/plays',
    '/api/v1/nba/player-stats/not-a-number',
    '/api/v1/nba/games/not-a-number/player-stats',
    '/api/v1/nba/players/not-a-number/stats',
    '/api/v1/nba/team-stats/not-a-number',
    '/api/v1/nba/games/not-a-number/team-stats',
    '/api/v1/nba/teams/not-a-number/stats',
    '/api/v1/nba/elo-ratings/not-a-number',
    '/api/v1/nba/teams/not-a-number/elo-ratings',
    '/api/v1/nba/elo-ratings/season/not-a-number',
]);
