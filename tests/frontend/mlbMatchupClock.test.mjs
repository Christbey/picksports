import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let Card;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [vue()],
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
    Card = (
        await server.ssrLoadModule(
            '/resources/js/components/mlb/MlbMatchupCard.vue',
        )
    ).default;
});
after(async () => {
    await server?.close();
});

test('MLB card never turns unverified mixed wall fields into a kickoff instant', async () => {
    const prediction = {
        id: 1,
        game: {
            id: 1,
            game_date: '2026-11-26',
            game_time: '19:00:00',
            status: 'STATUS_SCHEDULED',
            home_team: { abbreviation: 'HME' },
            away_team: { abbreviation: 'AWY' },
        },
    };
    const render = () =>
        renderToString(createSSRApp({ render: () => h(Card, { prediction }) }));
    assert.match(await render(), /Time unavailable/);
    prediction.game.starts_at = '2026-11-27T00:00:00Z';
    assert.doesNotMatch(await render(), /Time unavailable/);
    prediction.game.starts_at = 'not-an-instant';
    assert.match(await render(), /Time unavailable/);
});
