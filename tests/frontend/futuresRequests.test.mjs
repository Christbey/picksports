import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { createRenderer, h, nextTick } from 'vue';
import { createServer } from 'vite';
import { fileURLToPath } from 'node:url';
let server, factories;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'futures-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client=()=>globalThis.__futuresApi;';
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
    factories = {
        nba: (
            await server.ssrLoadModule(
                '/resources/js/composables/useNbaFutures.ts',
            )
        ).useNbaFutures,
        mlb: (
            await server.ssrLoadModule(
                '/resources/js/composables/useMlbFutures.ts',
            )
        ).useMlbFutures,
    };
});
after(async () => server?.close());
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
    await new Promise((r) => setImmediate(r));
    await nextTick();
};
function mount(sport, t) {
    const calls = [];
    globalThis.__futuresApi = {
        forecasts: {
            index: (s, options) =>
                new Promise((resolve, reject) =>
                    calls.push({ sport: s, ...options, resolve, reject }),
                ),
        },
    };
    let state;
    const app = renderer.createApp({
        setup() {
            state = factories[sport]();
            return () => h('div');
        },
    });
    app.mount({});
    t.after(() => app.unmount());
    return { app, state, calls };
}
const row = (id) => ({
    id,
    team_id: id,
    team: null,
    playoff_make_probability: 0.5,
    champion_probability: 0,
    play_in_tournament_probability: 0.2,
    pennant_probability: 0,
    league_championship_probability: 0.1,
    nba_finals_probability: 0,
});
for (const sport of ['nba', 'mlb']) {
    test(`${sport}: latest season owns rows, loading, and errors; superseded reads abort`, async (t) => {
        const { state: s, calls } = mount(sport, t);
        await flush();
        assert.equal(calls.length, 1);
        assert.equal(calls[0].sport, sport);
        s.selectedSeason.value = 2025;
        await flush();
        assert.equal(calls[0].init.signal.aborted, true);
        calls[0].resolve({
            data: [row(1)],
            meta: { available_seasons: [2025] },
        });
        await flush();
        assert.equal(s.loading.value, true);
        assert.deepEqual(s.forecasts.value, []);
        calls[1].resolve({
            data: [row(2)],
            meta: { available_seasons: [2025] },
        });
        await flush();
        assert.equal(s.forecasts.value[0].id, 2);
        assert.equal(s.loading.value, false);
        s.selectedSeason.value = 2024;
        await flush();
        s.selectedSeason.value = 2023;
        await flush();
        calls[2].reject(new Error('stale'));
        await flush();
        assert.equal(s.error.value, null);
        calls[3].resolve(null);
        await flush();
        assert.match(s.error.value, /Failed to load/);
        assert.deepEqual(s.forecasts.value, []);
    });
    test(`${sport}: unmount cancels and ignores late completions, errors, and fallback metadata`, async (t) => {
        const { app, state: s, calls } = mount(sport, t);
        await flush();
        app.unmount();
        assert.equal(calls[0].init.signal.aborted, true);
        calls[0].resolve({
            data: [row(1)],
            meta: { available_seasons: [1999] },
        });
        await flush();
        assert.deepEqual(s.forecasts.value, []);
        assert.notEqual(s.selectedSeason.value, 1999);
        await s.fetchForecasts();
        assert.equal(calls.length, 1);
    });
    test(`${sport}: unavailable season starts one fallback request and local sorting never refetches`, async (t) => {
        const { state: s, calls } = mount(sport, t);
        await flush();
        calls[0].resolve({
            data: [row(1)],
            meta: {
                available_seasons: [2025],
                fallback_applied: true,
                season: 2025,
                requested_season: 2026,
            },
        });
        await flush();
        assert.equal(s.selectedSeason.value, 2025);
        assert.equal(calls.length, 2);
        calls[1].resolve({
            data: [
                row(2),
                { ...row(3), market_edge: { edge_probability: 0.1 } },
            ],
            meta: { available_seasons: [2025] },
        });
        await flush();
        s.onlyPlusEv.value = true;
        s.sortMode.value = 'edge';
        await flush();
        assert.equal(calls.length, 2);
        assert.equal(s.topByMake.value.length, 1);
        assert.equal(s.topByMake.value[0].id, 3);
        assert.equal(s.formatPct(0), '0.0%');
        assert.equal(s.formatAmericanOdds(0), '0');
        assert.equal(s.formatTeam(null, 2), 'Team 2');
    });
}
test('NBA fallback warning and MLB missing metadata retain distinct behavior', async (t) => {
    let { state: s, calls } = mount('nba', t);
    await flush();
    calls[0].resolve({
        data: [row(1)],
        meta: {
            available_seasons: [2026],
            requested_season: 2026,
            season: 2025,
            fallback_applied: true,
        },
    });
    await flush();
    assert.equal(
        s.error.value,
        'No rows for 2026; showing latest available season 2025.',
    );
    ({ state: s, calls } = mount('mlb', t));
    await flush();
    calls[0].resolve({ data: [row(2)] });
    await flush();
    assert.equal(s.error.value, null);
    assert.equal(s.forecasts.value[0].id, 2);
});
