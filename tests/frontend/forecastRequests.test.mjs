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
                    if (id.endsWith('/composables/useViewerAccess.ts'))
                        return `import {computed} from 'vue';export const useViewerAccess=()=>({isAdmin:computed(()=>true)});`;
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
    factories = {};
    for (const sport of ['nfl', 'cbb', 'wcbb']) {
        const cap = sport[0].toUpperCase() + sport.slice(1);
        factories[sport] = (
            await server.ssrLoadModule(
                `/resources/js/composables/use${cap}Forecast.ts`,
            )
        )[`use${cap}Forecast`];
    }
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
    team_name: 'Team ' + id,
    team: null,
    conference: 'AFC',
    division: 'East',
    projected_wins: 0,
    projected_seed: 1,
    division_winner_probability: 0,
    make_playoffs_probability: 0,
    conference_champion_probability: 0,
    super_bowl_champion_probability: 0,
    tournament_make_probability: 0,
    champion_probability: 0,
    final_four_probability: 0,
    title_game_probability: 0,
    is_actual_field: true,
    actual_region: 'East',
    actual_seed: 1,
    is_first_four: false,
    auto_bid_probability: 0,
    at_large_probability: 0,
    first_four_probability: 0,
    bid_thief_probability: 0,
});
for (const sport of ['nfl', 'cbb', 'wcbb']) {
    test(`${sport}: season changes cancel prior reads; unmount rejects late rows and metadata`, async (t) => {
        const { app, state: s, calls } = mount(sport, t);
        await flush();
        assert.equal(calls.length, 1);
        s.selectedSeason.value = 2025;
        await flush();
        assert.equal(calls[0].init.signal.aborted, true);
        calls[0].resolve({
            data: [row(99)],
            meta: { available_seasons: [1999] },
        });
        await flush();
        assert.deepEqual(s.forecasts.value, []);
        assert.equal(s.loading.value, true);
        calls[1].resolve({
            data: [row(2)],
            meta: {
                available_seasons: [2025],
                actual_field_size: 1,
                simulations: 1000,
            },
        });
        await flush();
        assert.equal(s.forecasts.value[0].team_id, 2);
        assert.equal(s.loading.value, false);
        s.selectedSeason.value = 2024;
        await flush();
        app.unmount();
        assert.equal(calls[2].init.signal.aborted, true);
        calls[2].resolve({
            data: [row(3)],
            meta: { available_seasons: [1998] },
        });
        await flush();
        assert.equal(s.forecasts.value[0].team_id, 2);
        assert.equal(s.selectedSeason.value, 2024);
        await s.fetchForecasts();
        assert.equal(calls.length, 3);
    });
    test(`${sport}: failed season retries retain filters, distinguish empty success, and ignore obsolete failures`, async (t) => {
        const { state: s, calls } = mount(sport, t);
        await flush();
        calls[0].resolve(null);
        await flush();
        assert.match(s.error.value, /Failed to load/);
        const season = s.selectedSeason.value;
        const retry = s.fetchForecasts();
        assert.equal(calls[1].query.season, season);
        calls[1].resolve({ data: [] });
        await retry;
        await flush();
        assert.equal(s.error.value, null);
        assert.deepEqual(s.forecasts.value, []);
        s.selectedSeason.value = 2025;
        await flush();
        s.selectedSeason.value = 2024;
        await flush();
        calls[2].reject(new Error('obsolete'));
        await flush();
        assert.equal(s.error.value, null);
        assert.equal(s.loading.value, true);
        calls[3].resolve({
            data: [row(1)],
            meta: { actual_field_size: 1, simulations: 1000 },
        });
        await flush();
        assert.equal(s.error.value, null);
        assert.equal(s.formatAmericanOdds(0), '0');
        if (sport === 'nfl') assert.equal(s.formatPct(0), '0%*');
        else assert.equal(s.formatPct(0), '0.0%');
    });
}
test('CBB actual field, eliminated teams and live snapshot metadata retain their meaning', async (t) => {
    const { state: s, calls } = mount('cbb', t);
    await flush();
    calls[0].resolve({
        data: [row(1), { ...row(2), is_eliminated: true }],
        meta: {
            actual_field_size: 2,
            mode: 'live_snapshot',
            snapshot_as_of: '2026-03-20T00:00:00Z',
        },
    });
    await flush();
    assert.equal(s.actualFieldKnown.value, true);
    assert.equal(s.actualFieldForecasts.value.length, 1);
    assert.equal(s.forecastMode.value, 'live_snapshot');
    assert.equal(s.snapshotAsOf.value, '2026-03-20T00:00:00Z');
});

for (const sport of ['cbb', 'wcbb'])
    test(`${sport}: unavailable seasons request the fallback once and local controls never refetch`, async (t) => {
        const { state: s, calls } = mount(sport, t);
        await flush();
        calls[0].resolve({
            data: [row(1)],
            meta: { available_seasons: [2025] },
        });
        await flush();
        assert.equal(s.selectedSeason.value, 2025);
        assert.equal(calls.length, 2);
        assert.equal(calls[1].query.season, 2025);
        calls[1].resolve({
            data: [row(2)],
            meta: { available_seasons: [2025], actual_field_size: 1 },
        });
        await flush();
        if (sport === 'wcbb') {
            s.sortMode.value = 'edge';
            s.onlyPlusEv.value = true;
            await flush();
            assert.equal(s.topByMake.value.length, 0);
        }
        assert.equal(calls.length, 2);
        assert.equal(s.forecasts.value[0].id, 2);
    });
