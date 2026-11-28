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
            '/resources/js/components/BettingAnalysisCard.vue',
        )
    ).default;
});
after(async () => {
    await server?.close();
});

test('legacy static combo fields never render unsupported validation rates or an upgraded signal', async () => {
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(Card, {
                    predictionAnalysis: {
                        risk_flags: [],
                        reason_codes: [],
                        best_validated_signal: {
                            name: 'historical',
                            label: 'Historical pressure pattern',
                            market: 'winner',
                            tier: 'strong',
                            sample_size: 127,
                            winner_hit_rate: 73.2,
                            spread_mae: 10.1,
                            codes: [],
                            note: 'Large-sample winner signal.',
                        },
                    },
                }),
        }),
    );
    assert.doesNotMatch(
        html,
        /73\.2% winner|127 games|10\.10 spread MAE|Large-sample winner signal/,
    );
    assert.match(html, /Unverified historical pattern/);
    assert.match(html, /Current validation unavailable/);
});
