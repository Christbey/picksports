import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { predictionHomeMargin } from '../../resources/js/lib/predictionSpread.ts';
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

test('native home handicaps become margins while dashboards and legacy records retain their convention', () => {
    const native = {
        phase: 'pregame',
        revision: 1,
        game: { status: 'STATUS_SCHEDULED' },
        predicted_spread: -6.5,
    };
    assert.equal(predictionHomeMargin(native), 6.5);
    assert.equal(
        predictionHomeMargin({ ...native, predicted_spread: 6.5 }),
        -6.5,
    );
    assert.equal(
        predictionHomeMargin({
            ...native,
            game: 'Away at Home',
            predicted_spread: 6.5,
        }),
        6.5,
    );
    assert.equal(predictionHomeMargin({ predicted_spread: 6.5 }), 6.5);
    assert.equal(
        predictionHomeMargin({ ...native, predicted_spread: null }),
        null,
    );
});

test('NBA CBB WNBA and WCBB card and game-page adapters preserve the same native margin', async () => {
    const { normalizePrediction } = await server.ssrLoadModule(
        '/resources/js/composables/useBasketballGamePage.ts',
    );
    const { normalizePredictionLiveState } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionLiveData.ts',
    );
    for (const sport of ['nba', 'cbb', 'wnba', 'wcbb']) {
        const native = {
            sport,
            phase: 'pregame',
            revision: 1,
            home_win_probability: 0.6,
            away_win_probability: 0.4,
            predicted_spread: -6.5,
            predicted_total: 220,
            game: { status: 'STATUS_SCHEDULED' },
        };
        assert.equal(normalizePrediction(native).predicted_spread, 6.5);
        assert.equal(
            normalizePredictionLiveState({
                ...native,
                game: null,
                predicted_spread: 6.5,
            }).preGamePredictedSpread,
            6.5,
        );
        assert.equal(
            normalizePredictionLiveState(native).preGamePredictedSpread,
            6.5,
        );
        assert.equal(
            normalizePredictionLiveState({
                ...native,
                game: 'Away at Home',
                predicted_spread: 6.5,
            }).preGamePredictedSpread,
            6.5,
        );
    }
});

test('NBA historical review labels distinguish observed pregame archives from unverified stored values', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/BasketballGameInsights.vue',
    );
    for (const known of [true, false]) {
        const html = await renderToString(
            createSSRApp(component, {
                gameStatus: 'STATUS_FINAL',
                homeTeamId: 1,
                showRecap: false,
                showMetrics: false,
                formatNumber: String,
                getBetterValue: () => null,
                historicalPrediction: {
                    source: known ? 'legacy_archive' : 'legacy_history',
                    known_before_kickoff: known,
                    predicted_spread: 3.5,
                    predicted_total: 210,
                },
            }),
        );
        assert.match(
            html,
            known ? /observed before kickoff/ : /pregame timing is unverified/,
        );
        assert.match(html, /no betting approval/);
    }
    const active = await renderToString(
        createSSRApp(component, {
            gameStatus: 'STATUS_SCHEDULED',
            homeTeamId: 1,
            showRecap: false,
            showMetrics: false,
            formatNumber: String,
            getBetterValue: () => null,
            historicalPrediction: {
                source: 'legacy_history',
                known_before_kickoff: false,
            },
        }),
    );
    assert.doesNotMatch(active, /Historical model forecast/);
});

test('missing native forecasts retain historical game loading while authentication and permissions still fail', async () => {
    const { useDetailedGameData } = await server.ssrLoadModule(
        '/resources/js/composables/useDetailedGameData.ts',
    );
    const { ApiError } = await server.ssrLoadModule(
        '/resources/js/composables/useApiClient.ts',
    );
    for (const [sport, status] of ['nba', 'cbb'].flatMap((sport) =>
        [404, 401, 403].map((status) => [sport, status]),
    )) {
        const archive = {
            source: 'legacy_archive',
            known_before_kickoff: true,
            predicted_spread: 3.5,
            predicted_total: 210,
        };
        globalThis.__nbaCutoverApi = {
            games: {
                show: async () => ({
                    data: { id: 1, status: 'STATUS_FINAL' },
                    historical_prediction: archive,
                }),
            },
            predictions: {
                forGame: async () => {
                    throw new ApiError(new Response(null, { status }), {});
                },
            },
            stats: {
                teams: async () => ({ data: [] }),
                players: async () => ({ data: [] }),
            },
        };
        let state;
        await renderToString(
            createSSRApp({
                async setup() {
                    state = useDetailedGameData({ sport, gameId: 1 });
                    await state.reload();
                    return () => '';
                },
            }),
        );
        if (status === 404) {
            assert.equal(state.error.value, null);
            assert.equal(state.game.value.id, 1);
            assert.equal(state.prediction.value, null);
            assert.equal(
                state.historicalPrediction.value.source,
                'legacy_archive',
            );
        } else {
            assert.ok(state.error.value);
            assert.equal(state.game.value, null);
            assert.equal(state.historicalPrediction.value, null);
        }
    }
});
