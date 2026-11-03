import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, createRenderer, nextTick } from 'vue';
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

test('MLB page keeps the established winner and home run line for native and legacy forecasts', async () => {
    const { useMlbGamePage } = await server.ssrLoadModule(
        '/resources/js/composables/useMlbGamePage.ts',
    );
    for (const [raw, expected] of [
        [{ phase: 'pregame', revision: 1, predicted_spread: -1.5 }, 1.5],
        [{ phase: 'pregame', revision: 1, predicted_spread: 1.5 }, -1.5],
        [{ predicted_spread: 1.5 }, 1.5],
    ]) {
        globalThis.__nbaCutoverApi = {
            games: {
                page: async () => ({
                    data: {
                        game: {
                            id: 1,
                            status: 'STATUS_SCHEDULED',
                            home_team_id: 1,
                            away_team_id: 2,
                        },
                        prediction: {
                            ...raw,
                            predicted_total: 8.5,
                            home_win_probability: 0.6,
                            away_win_probability: 0.4,
                            confidence_score: 60,
                        },
                        recent_games: { home: [], away: [] },
                        metrics: { home: null, away: null },
                        depth_charts_available: false,
                    },
                }),
            },
        };
        let state;
        const renderer = createRenderer({
            createElement: () => ({}),
            createText: () => ({}),
            createComment: () => ({}),
            insert() {},
            remove() {},
            setText() {},
            setElementText() {},
            patchProp() {},
            parentNode: () => null,
            nextSibling: () => null,
        });
        const app = renderer.createApp({
            setup() {
                state = useMlbGamePage(1);
                return () => null;
            },
        });
        app.mount({});
        await new Promise((resolve) => setImmediate(resolve));
        await nextTick();
        assert.equal(state.error.value, null);
        assert.equal(state.prediction.value.predicted_spread, expected);
        const { default: card } = await server.ssrLoadModule(
            '/resources/js/components/game-page/PredictionSummaryCard.vue',
        );
        const html = await renderToString(
            createSSRApp(card, {
                prediction: state.prediction.value,
                homeLabel: 'Home Club',
                awayLabel: 'Away Club',
                formatNumber: String,
                awayBarClass: '',
                homeBarClass: '',
            }),
        );
        const text = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
        assert.ok(
            text.includes(
                expected > 0
                    ? 'Home Spread -1.5 Home Club favored'
                    : 'Home Spread +1.5 Away Club favored',
            ),
        );
        assert.equal(state.prediction.value.predicted_total, 8.5);
        assert.equal(state.prediction.value.home_win_probability, 0.6);
        app.unmount();
    }
});
