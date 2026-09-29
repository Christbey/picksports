import assert from 'node:assert/strict';
import { test } from 'node:test';
import { apiRoute } from '../../resources/js/lib/apiV2Routes.ts';

test('portable API paths encode identifiers and preserve typed filters', () => {
    assert.equal(
        apiRoute('sports.games.show', { sport: 'nfl', game: 'a/b' }),
        '/api/v2/sports/nfl/games/a%2Fb',
    );
    assert.equal(
        apiRoute('sports.games.page.show', ['nfl', 1753]),
        '/api/v2/sports/nfl/games/1753/page',
    );
    assert.equal(
        apiRoute('sports.index', { query: { active: true, missing: null } }),
        '/api/v2/sports?active=1',
    );
    assert.equal(
        apiRoute(
            'sports.teams.trends.show',
            { sport: 'nfl', team: 6 },
            {
                query: {
                    season: 2026,
                    profile: 'team_analysis',
                    filters: { phases: [1, 2] },
                },
            },
        ),
        '/api/v2/sports/nfl/teams/6/trends?season=2026&profile=team_analysis&filters%5Bphases%5D%5B%5D=1&filters%5Bphases%5D%5B%5D=2',
    );
    assert.throws(
        () => apiRoute('sports.games.show', { sport: 'nfl' }),
        /Missing API parameter: game/,
    );
});
