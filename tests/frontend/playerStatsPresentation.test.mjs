import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
let server, stats;
before(async () => {
    server = await createServer({
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
    stats = await server.ssrLoadModule(
        '/resources/js/lib/playerStatsPresentation.ts',
    );
});
after(async () => {
    await server?.close();
});
test('leaderboard custom formatters retain raw entry access and rank colors retain small-sample behavior', () => {
    assert.equal(
        stats.formatColumnValue(
            { points_per_game: 0 },
            { key: 'points_per_game' },
        ),
        '0.0',
    );
    assert.equal(
        stats.formatColumnValue(
            { points_per_game: 2 },
            {
                key: 'points_per_game',
                format: (value, row) => `${value}:${row.points_per_game}`,
            },
        ),
        '2:2',
    );
    assert.equal(stats.rankColorClass(0, 2), '');
    assert.match(stats.rankColorClass(0, 10), /emerald/);
    assert.match(stats.rankColorClass(9, 10), /rose/);
    assert.equal(
        stats
            .buildDefaultPlayerStatColumns(true)
            .filter((x) => x.label.startsWith('EPA')).length,
        2,
    );
});
