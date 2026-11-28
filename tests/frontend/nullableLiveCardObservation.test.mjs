import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server;
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
});
after(async () => {
    await server?.close();
});
function prediction(sport, dashboard, prior = null, liveTotal = null) {
    return {
        sport,
        predicted_total: prior,
        predicted_spread: prior === null ? null : prior === 0 ? 0 : 4,
        win_probability: prior === null ? null : prior === 0 ? 0 : 0.6,
        live_win_probability: 0.65,
        live_predicted_total: liveTotal,
        live_predicted_spread: null,
        is_live: true,
        status: 'STATUS_IN_PROGRESS',
        game: dashboard ? null : { status: 'STATUS_IN_PROGRESS' },
    };
}
for (const sport of ['wnba', 'wcbb'])
    for (const dashboard of [true, false]) {
        const label = `${sport} ${dashboard ? 'dashboard' : 'list'}`;
        test(`${label} actual adapter preserves unknown priors`, async () => {
            const { buildPredictionLiveData, hasPredictionLiveData } =
                await server.ssrLoadModule(
                    '/resources/js/composables/usePredictionLiveData.ts',
                );
            const input = prediction(sport, dashboard);
            assert.equal(hasPredictionLiveData(input), true);
            const live = buildPredictionLiveData(input);
            assert.deepEqual(
                [
                    live.preGamePredictedTotal,
                    live.preGamePredictedSpread,
                    live.preGameWinProbability,
                ],
                [null, null, null],
            );
        });
        test(`${label} compiled card keeps missing total and prior markets unavailable`, async () => {
            const { buildPredictionLiveData } = await server.ssrLoadModule(
                '/resources/js/composables/usePredictionLiveData.ts',
            );
            const { default: Card } = await server.ssrLoadModule(
                '/resources/js/components/BettingAnalysisCard.vue',
            );
            const html = await renderToString(
                createSSRApp(Card, {
                    livePrediction: buildPredictionLiveData(
                        prediction(sport, dashboard),
                    ),
                }),
            );
            const text = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
            assert.match(text, /Total:\s+Unavailable/);
            assert.match(text, /Home spread:\s+Unavailable/);
            assert.doesNotMatch(text, /was 0\.0/);
            assert.match(text, /65\.0%/);
        });
    }
test('known zero prior and live totals remain zero in actual adapter and compiled card', async () => {
    const { buildPredictionLiveData } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionLiveData.ts',
    );
    const { default: Card } = await server.ssrLoadModule(
        '/resources/js/components/BettingAnalysisCard.vue',
    );
    const live = buildPredictionLiveData(prediction('wnba', true, 0, 0));
    assert.equal(live.preGamePredictedTotal, 0);
    assert.equal(live.preGameWinProbability, 0);
    assert.equal(live.preGamePredictedSpread, 0);
    const text = (
        await renderToString(createSSRApp(Card, { livePrediction: live }))
    )
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ');
    assert.match(text, /Total:\s+0\.0/);
    assert.match(text, /was 0\.0%/);
});
test('known pregame fallback is explicitly labelled and not represented as a live total', async () => {
    const { buildPredictionLiveData } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionLiveData.ts',
    );
    const { default: Card } = await server.ssrLoadModule(
        '/resources/js/components/BettingAnalysisCard.vue',
    );
    const live = buildPredictionLiveData(prediction('wcbb', false, 150, null));
    const text = (
        await renderToString(createSSRApp(Card, { livePrediction: live }))
    )
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ');
    assert.match(text, /Pregame total:\s+150\.0\s+\(live unavailable\)/);
});
test('existing live gates remain explicit for inactive games and unavailable live probability', async () => {
    const { buildPredictionLiveData, hasPredictionLiveData } =
        await server.ssrLoadModule(
            '/resources/js/composables/usePredictionLiveData.ts',
        );
    const { default: Card } = await server.ssrLoadModule(
        '/resources/js/components/BettingAnalysisCard.vue',
    );
    const inactive = {
        ...prediction('wcbb', true),
        is_live: false,
        status: 'STATUS_SCHEDULED',
    };
    assert.equal(buildPredictionLiveData(inactive), undefined);
    assert.equal(hasPredictionLiveData(inactive), false);
    const missingLive = {
        ...prediction('wnba', true),
        live_win_probability: null,
    };
    assert.equal(hasPredictionLiveData(missingLive), false);
    const html = await renderToString(
        createSSRApp(Card, {
            livePrediction: buildPredictionLiveData(missingLive),
        }),
    );
    assert.doesNotMatch(
        html,
        /Real-time prediction updates|Win Prob:|Home spread:|Total:/,
    );
});
test('known live markets remain visible while missing historical priors stay unavailable in compact mode', async () => {
    const { buildPredictionLiveData } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionLiveData.ts',
    );
    const { default: Card } = await server.ssrLoadModule(
        '/resources/js/components/BettingAnalysisCard.vue',
    );
    const input = {
        ...prediction('wnba', false),
        live_predicted_spread: 3.5,
        live_predicted_total: 151,
    };
    const text = (
        await renderToString(
            createSSRApp(Card, {
                compact: true,
                livePrediction: buildPredictionLiveData(input),
            }),
        )
    )
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ');
    assert.match(text, /Total:\s+151\.0\s+\(pregame unavailable\)/);
    assert.match(text, /Home spread:\s+-3\.5\s+\(pregame unavailable\)/);
    assert.match(text, /65\.0%\s+\(pregame unavailable\)/);
});
