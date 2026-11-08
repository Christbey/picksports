import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

test('compact board JSON renders the same summary and expanded forecast evidence', async () => {
    const fixture = JSON.parse(
        readFileSync(
            new URL('../Fixtures/cfb-board-delivery.json', import.meta.url),
            'utf8',
        ),
    );
    const server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'expanded-board-fixture',
                enforce: 'pre',
                load(id) {
                    if (
                        id.endsWith(
                            '/components/predictions/UnifiedPredictionCard.vue',
                        )
                    )
                        return readFileSync(id, 'utf8').replace(
                            'const expanded = ref(false);',
                            'const expanded = ref(true);',
                        );
                    if (
                        id.endsWith(
                            '/components/predictions/PredictionSaveActions.vue',
                        )
                    )
                        return '<template><div /></template>';
                    if (id.endsWith('/composables/usePredictionTracking.ts'))
                        return 'export const usePredictionTracking=()=>({canSavePick:false});';
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
    try {
        const { default: Card } = await server.ssrLoadModule(
            '/resources/js/components/predictions/UnifiedPredictionCard.vue',
        );
        const { mapV2Prediction } = await server.ssrLoadModule(
            '/resources/js/lib/predictionBoardPresentation.ts',
        );
        const render = (prediction) =>
            renderToString(
                createSSRApp(Card, {
                    prediction: mapV2Prediction(prediction),
                    sport: 'cfb',
                    compact: true,
                    href: '/cfb/games/644',
                }),
            );
        const full = await render(fixture.full),
            compact = await render(fixture.compact);
        assert.equal(compact, full);
        assert.match(compact, /KU to win/);
        assert.match(compact, /Matched signal/);
        assert.doesNotMatch(
            compact,
            /unmatched|large_unused_detail|not rendered/,
        );
        assert.match(compact, /400/);
    } finally {
        await server.close();
    }
});
