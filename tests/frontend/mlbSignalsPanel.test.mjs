import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, createRenderer, h, nextTick } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server;
let board;
let useMlbSignals;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'mlb-signals-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__mlbSignalsApi;';
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
    board = (
        await server.ssrLoadModule(
            '/resources/js/components/mlb/MlbSignalsBoard.vue',
        )
    ).default;
    ({ useMlbSignals } = await server.ssrLoadModule(
        '/resources/js/composables/useMlbSignals.ts',
    ));
});
after(async () => {
    await server?.close();
});
const fixture = (extra = {}) => ({
    season: 2026,
    as_of_date: '2026-11-04',
    backend_review: { model: 'test', strengths: [], watch_items: [] },
    recommended_bets: [],
    world_series: [],
    moneyline: [],
    run_line: [],
    totals: [],
    ballpark: [],
    live: [],
    streaks: [],
    ...extra,
});
const bet = (extra = {}) => ({
    type: 'moneyline',
    game_id: 1,
    matchup: 'AWY @ HOM',
    classification: 'bet',
    team_name: 'Public pick',
    pick_side: 'home',
    model_probability: 0.6,
    market_price: 110,
    probability_edge: 0.05,
    score: 75,
    ...extra,
});
const render = (payload) => {
    const app = createSSRApp(board, { payload });
    app.config.warnHandler = (message) => {
        throw new Error(message);
    };
    return renderToString(app);
};
test('signal board keeps public picks and protected research distinct', async () => {
    const publicHtml = await render(fixture({ recommended_bets: [bet()] }));
    assert.match(publicHtml, /Top Validated Signal/);
    assert.match(publicHtml, /Public pick/);
    assert.match(publicHtml, /60%/);
    assert.match(publicHtml, /\+110/);
    assert.match(publicHtml, /\+5.0%/);
    const research = await render(
        fixture({
            bet_filter: { promotion: { status: 'blocked' }, risk_controls: [] },
            shadow_recommended_bets: [bet({ team_name: 'Research pick' })],
        }),
    );
    assert.match(research, /Research Signal/);
    assert.match(research, /tracking only/);
    assert.match(research, /Research pick/);
    assert.doesNotMatch(research, /Top Validated Signal/);
    assert.match(research, /No games passed the selective bet filter/);
});
test('live signals retain zero scores, stale warnings, row limits and collapsed diagnostics', async () => {
    const html = await render(
        fixture({
            recommended_bets: Array.from({ length: 10 }, (_, i) =>
                bet({ game_id: i, team_name: `Pick ${i}` }),
            ),
            live: [
                {
                    type: 'live',
                    game_id: 99,
                    matchup: 'Live matchup',
                    home_score: 0,
                    away_score: 0,
                    inning: 1,
                    inning_state: 'top',
                    is_stale: true,
                    signal: 'stale_feed',
                    live_win_probability: 0,
                    live_predicted_total: 0,
                },
            ],
        }),
    );
    assert.match(html, /0-0 top 1/);
    assert.match(html, /stale feed/);
    assert.match(html, /0%/);
    assert.match(html, /Pick 7/);
    assert.doesNotMatch(html, /Pick 8/);
    assert.match(html, /Secondary signal detail/);
    assert.match(html, /Model controls and diagnostics/);
    assert.doesNotMatch(html, /<details[^>]*\bopen\b/);
});
const renderer = createRenderer({
    createElement: () => ({}),
    createText: () => ({}),
    createComment: () => ({}),
    insert() {},
    remove() {},
    setText() {},
    setElementText() {},
    parentNode: () => null,
    nextSibling: () => null,
    patchProp() {},
});
const flush = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
test('signals load on mount, refresh silently every 30 seconds, recover from errors and clear the timer', async () => {
    const oldWindow = globalThis.window;
    let poll,
        cleared,
        calls = 0,
        fail = false,
        state;
    globalThis.window = {
        setInterval(fn, ms) {
            assert.equal(ms, 30000);
            poll = fn;
            return 7;
        },
        clearInterval(id) {
            cleared = id;
        },
    };
    globalThis.__mlbSignalsApi = {
        signals: {
            async index(sport, options) {
                calls++;
                assert.equal(sport, 'mlb');
                assert.deepEqual(options.query, { season: 2026 });
                if (fail) throw new Error('Unavailable');
                return { data: fixture() };
            },
        },
    };
    const app = renderer.createApp({
        setup() {
            state = useMlbSignals({ season: 2026 });
            return () => h('div');
        },
    });
    try {
        app.mount({});
        await flush();
        assert.equal(calls, 1);
        assert.equal(state.loading.value, false);
        assert.equal(state.payload.value.season, 2026);
        fail = true;
        poll();
        assert.equal(state.loading.value, false);
        await flush();
        assert.equal(state.error.value, 'Unavailable');
        fail = false;
        poll();
        await flush();
        assert.equal(state.error.value, null);
        assert.equal(calls, 3);
    } finally {
        app.unmount();
        globalThis.window = oldWindow;
        delete globalThis.__mlbSignalsApi;
    }
    assert.equal(cleared, 7);
});
