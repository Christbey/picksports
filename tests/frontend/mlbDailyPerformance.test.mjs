import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

test('MLB tracking distinguishes an observed zero hit rate from an undecided record', async () => {
    const server = await createServer({
        configFile: false,
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
    });
    try {
        const { gameSortKey } = await server.ssrLoadModule(
            '/resources/js/lib/mlbBoardPresentation.ts',
        );
        const early = {
            game: {
                starts_at: '2026-06-03T00:00:00Z',
                game_date: '2026-06-02',
                game_time: '20:00:00',
            },
        };
        const late = {
            game: {
                starts_at: '2026-06-03T01:00:00Z',
                game_date: '2026-06-02',
                game_time: '17:00:00',
            },
        };
        assert.ok(gameSortKey(early) < gameSortKey(late));
        const { periodScore } = await server.ssrLoadModule(
            '/resources/js/lib/mlbMatchupPresentation.ts',
        );
        const game = {
            away_team: { abbreviation: 'AWY' },
            home_team: { abbreviation: 'HME' },
            away_linescores: [0, 1, 0],
            home_linescores: [0, null, 0],
        };
        assert.equal(periodScore({ game }, 3), '-');
        game.home_linescores = [0, 0, 0];
        assert.equal(periodScore({ game }, 3), 'AWY 1 - HME 0');
        game.home_linescores = [0, 'bad', 0];
        assert.equal(periodScore({ game }, 3), '-');
        const { formatHitRate } = await server.ssrLoadModule(
            '/resources/js/lib/mlbDailyPicksPresentation.ts',
        );
        assert.equal(
            formatHitRate({ wins: 0, losses: 3, pushes: 0, hit_rate: 0 }),
            '0.0%',
        );
        assert.equal(
            formatHitRate({ wins: 0, losses: 0, pushes: 1, hit_rate: null }),
            'Unlocking',
        );
        assert.equal(formatHitRate(null), 'Unlocking');
        assert.equal(
            formatHitRate({ wins: 1, losses: 1, pushes: 0, hit_rate: 0.5 }),
            '50.0%',
        );
    } finally {
        await server.close();
    }
});
