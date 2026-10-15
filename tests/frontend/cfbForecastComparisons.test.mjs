import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { fileURLToPath } from 'node:url';

test('experimental comparisons retain home-line signs and disclose market influence and approval', async () => {
    const server = await createServer({
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
    try {
        const { default: Component } = await server.ssrLoadModule(
            '/resources/js/components/game-page/CfbForecastComparisons.vue',
        );
        const html = await renderToString(
            createSSRApp(Component, {
                forecasts: {
                    status: 'shadow_only',
                    betting_approved: false,
                    models: {
                        independent: {
                            home_line: -6,
                            total: 50,
                            uses_market: false,
                        },
                        market_half_blend: {
                            home_line: -4.5,
                            total: 47.5,
                            uses_market: true,
                        },
                        result_rating: {
                            home_line: 2,
                            total: null,
                            uses_market: false,
                        },
                    },
                },
            }),
        );
        assert.match(html, /not approved bets/);
        assert.match(html, /stored sportsbook lines/);
        assert.match(html, /-6\.0/);
        assert.match(html, /-4\.5/);
        assert.match(html, /\+2\.0/);
        assert.match(html, /Unavailable/);
        const empty = await renderToString(createSSRApp(Component));
        assert.doesNotMatch(empty, /Experimental forecast comparisons/);
    } finally {
        await server.close();
    }
});
