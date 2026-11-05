import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { effectScope, ref, nextTick } from 'vue';
let server, useGameViewTracking, games;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'game-tracking-fixture',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/lib/analytics.ts'))
                        return 'export const trackViewItem = event => globalThis.__gameViewEvents.push(event);';
                },
            },
        ],
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
    ({ useGameViewTracking } = await server.ssrLoadModule(
        '/resources/js/composables/useGameViewTracking.ts',
    ));
    games = await server.ssrLoadModule(
        '/resources/js/composables/useGameDataUtils.ts',
    );
});
after(async () => {
    await server?.close();
});
test('game tracking emits immediately, keeps label precedence and stops after scope disposal', async () => {
    const events = (globalThis.__gameViewEvents = []);
    const id = ref(1),
        sport = ref('nfl'),
        away = ref(null),
        home = ref(null),
        scope = effectScope();
    scope.run(() =>
        useGameViewTracking({
            gameId: () => id.value,
            sport: () => sport.value,
            awayTeam: () => away.value,
            homeTeam: () => home.value,
        }),
    );
    try {
        assert.deepEqual(events[0], {
            itemId: 1,
            itemName: 'Away @ Home',
            sport: 'nfl',
            homeTeam: 'Home',
            awayTeam: 'Away',
        });
        away.value = { abbreviation: 'AWY', name: 'Away name' };
        home.value = { name: 'Home name' };
        await nextTick();
        assert.equal(events.at(-1).itemName, 'AWY @ Home name');
        const count = events.length;
        sport.value = 'mlb';
        await nextTick();
        assert.equal(events.length, count);
        id.value = 2;
        await nextTick();
        assert.equal(events.at(-1).sport, 'mlb');
        assert.equal(events.at(-1).itemId, 2);
        away.value = { abbreviation: '', name: 'Ignored' };
        await nextTick();
        assert.equal(events.at(-1).itemName, ' @ Home name');
        scope.stop();
        const stopped = events.length;
        id.value = 3;
        await nextTick();
        assert.equal(events.length, stopped);
    } finally {
        scope.stop();
        delete globalThis.__gameViewEvents;
    }
});
test('recent form and record share strict score handling while retaining ties and zero scores', () => {
    const row = (home_score, away_score) => ({
        home_team_id: 1,
        away_team_id: 2,
        home_score,
        away_score,
    });
    const fixtures = [
        row(0, 0),
        row(1, 0),
        row(0, 1),
        row(null, 1),
        row(NaN, 1),
        row(Infinity, 0),
        row('3', 1),
    ];
    assert.equal(games.getRecentForm(fixtures, 1), 'T-W-L');
    assert.equal(games.getWinLossRecord(fixtures, 1), '1-1-1');
    assert.equal(games.getRecentForm(fixtures, 2), 'T-L-W');
    assert.equal(games.getWinLossRecord([], 1), '0-0');
    assert.equal(games.getWinLossRecord([row(1, 0)], 1), '1-0');
});
