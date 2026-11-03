import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'nba-cutover-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__nbaCutoverApi;';
                },
            },
            vue(),
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
});
after(async () => {
    await server?.close();
});

test('MLB archived forecasts disclose timing and never display as an active approved pick', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/HistoricalForecastReview.vue',
    );
    for (const known of [true, false]) {
        const html = await renderToString(
            createSSRApp(component, {
                startLabel: 'first pitch',
                historicalPrediction: {
                    source: known ? 'legacy_archive' : 'legacy_history',
                    known_before_kickoff: known,
                    predicted_spread: 1.5,
                    predicted_total: 8.5,
                },
            }),
        );
        assert.match(html, /Home scoring margin:/);
        assert.match(
            html,
            known
                ? /observed before first pitch/
                : /pregame timing is unverified/,
        );
        assert.match(html, /no betting approval/);
    }
    const empty = await renderToString(
        createSSRApp(component, { historicalPrediction: null }),
    );
    assert.doesNotMatch(empty, /Historical model forecast/);
});
