import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createRenderer, h, nextTick, reactive, ssrContextKey } from 'vue';
import { fileURLToPath } from 'node:url';
import {
    injuryConfig,
    metricsConfig,
    injuryRows,
    metricRows,
} from './fixtures/injuryMetricsUi.mjs';
let server, components;
before(async () => {
    const root = fileURLToPath(new URL('../../', import.meta.url));
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'injury-metrics-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client=()=>globalThis.__injuryMetricsApi;';
                    if (
                        id.endsWith('/layouts/AppLayout.vue') ||
                        id.endsWith('/components/SubscriptionBanner.vue')
                    )
                        return '<template><div><slot/></div></template>';
                },
            },
            vue(),
        ],
        resolve: {
            alias: [
                {
                    find: '@inertiajs/vue3',
                    replacement:
                        root +
                        'tests/frontend/fixtures/frontendCleanupInertia.mjs',
                },
                { find: '@', replacement: root + 'resources/js' },
            ],
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    components = {
        injuries: (
            await server.ssrLoadModule(
                '/resources/js/components/SportInjuries.vue',
            )
        ).default,
        metrics: (
            await server.ssrLoadModule(
                '/resources/js/components/SportTeamMetrics.vue',
            )
        ).default,
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
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((a, b) => {
        resolve = a;
        reject = b;
    });
    return { resolve, reject, promise };
};
function mount(kind, t, api) {
    globalThis.__injuryMetricsApi = api;
    let state;
    const app = renderer.createApp({
        setup() {
            state = components[kind].setup(
                reactive({
                    config:
                        kind === 'injuries'
                            ? injuryConfig('nfl')
                            : metricsConfig('nfl'),
                }),
                { expose() {} },
            );
            return () => h('div');
        },
    });
    app.provide(ssrContextKey, { modules: new Set() });
    app.mount({});
    t.after(() => {
        app.unmount();
        delete globalThis.__injuryMetricsApi;
    });
    return { app, state };
}
test('injuries distinguish null responses, preserve filters and stored rows, and retry without refreshing providers', async (t) => {
    let fail = true;
    const calls = [];
    const { state: s } = mount('injuries', t, {
        injuries: {
            index: async (sport, options) => {
                calls.push({ sport, ...options });
                return fail
                    ? null
                    : {
                          data: injuryRows(),
                          meta: {
                              warnings: ['Coverage warning'],
                              freshness: { is_stale: false },
                          },
                      };
            },
        },
    });
    await flush();
    assert.match(s.error.value, /Failed to load injuries/);
    assert.deepEqual(s.rows.value, []);
    fail = false;
    await s.fetchInjuries();
    await flush();
    assert.equal(s.error.value, null);
    assert.equal(s.rows.value.length, 2);
    assert.deepEqual(s.warnings.value, ['Coverage warning']);
    assert.deepEqual(calls[1].query, { active: 1, limit: 500, actionable: 1 });
    s.search.value = 'Test';
    s.severityFilter.value = 'out';
    fail = true;
    await s.fetchInjuries();
    await flush();
    assert.equal(s.rows.value.length, 2);
    assert.equal(s.grouped.value.length, 1);
    fail = false;
    await s.fetchInjuries();
    await flush();
    assert.equal(s.search.value, 'Test');
    assert.equal(s.severityFilter.value, 'out');
    assert.equal(s.rows.value[0].expected_impact, 0);
});
test('injuries cancel superseded reads and discard late errors, rows, warnings, and post-unmount retries', async (t) => {
    const calls = [];
    const { app, state: s } = mount('injuries', t, {
        injuries: {
            index: (sport, options) => {
                const d = deferred();
                calls.push({ ...d, ...options });
                return d.promise;
            },
        },
    });
    await flush();
    const retry = s.fetchInjuries();
    assert.equal(calls[0].init.signal.aborted, true);
    calls[0].reject(new Error('obsolete'));
    await flush();
    assert.equal(s.error.value, null);
    assert.equal(s.loading.value, true);
    calls[1].resolve({ data: injuryRows(), meta: { warnings: ['Active'] } });
    await retry;
    await flush();
    const late = s.fetchInjuries();
    app.unmount();
    assert.equal(calls[2].init.signal.aborted, true);
    calls[2].resolve({ data: [], meta: { warnings: ['Late'] } });
    await late;
    await flush();
    assert.equal(s.rows.value.length, 2);
    assert.deepEqual(s.warnings.value, ['Active']);
    await s.fetchInjuries();
    assert.equal(calls.length, 3);
});
test('metrics recover metadata failure before selecting a season and preserve the season-type query', async (t) => {
    let fail = true;
    const calls = [];
    const { state: s } = mount('metrics', t, {
        metrics: {
            teamAvailableSeasons: async () =>
                fail ? null : { data: [2025, 2026] },
            teams: async (sport, options) => {
                calls.push(options.query);
                return {
                    data: metricRows(),
                    meta: { tier: { limit: 2, name: 'free' } },
                };
            },
        },
    });
    await flush();
    assert.match(s.seasonsError.value, /Available seasons/);
    assert.deepEqual(calls[0], { per_page: 500, season_type: '2' });
    fail = false;
    await s.loadSeasons();
    await flush();
    assert.equal(s.seasonsError.value, null);
    assert.equal(s.selectedSeason.value, '2026');
    assert.deepEqual(calls[1], {
        per_page: 500,
        season: '2026',
        season_type: '2',
    });
    assert.equal(s.tierLimit.value, 2);
});
test('metrics retain stale rows for the same query, clear them for different filters, and ignore obsolete failures', async (t) => {
    const calls = [];
    const { state: s } = mount('metrics', t, {
        metrics: {
            teamAvailableSeasons: async () => ({ data: [2026, 2025] }),
            teams: (sport, options) => {
                const d = deferred();
                calls.push({ ...d, ...options });
                return d.promise;
            },
        },
    });
    await flush();
    calls[0].resolve({
        data: metricRows(),
        meta: { tier: { limit: 2, name: 'free' } },
    });
    await flush();
    s.searchQuery.value = 'Home';
    const retry = s.fetchMetrics();
    assert.equal(s.metrics.value.length, 2);
    calls[1].reject(new Error('Offline'));
    await retry;
    assert.equal(s.metrics.value.length, 2);
    assert.equal(s.error.value, 'Offline');
    s.selectedSeason.value = '2025';
    await flush();
    assert.deepEqual(s.metrics.value, []);
    assert.equal(s.tierLimit.value, null);
    s.selectedSeasonType.value = '3';
    await flush();
    assert.equal(calls[2].init.signal.aborted, true);
    calls[2].reject(new Error('obsolete'));
    await flush();
    assert.equal(s.error.value, null);
    assert.equal(s.loading.value, true);
    calls[3].resolve({ data: metricRows(), meta: {} });
    await flush();
    assert.equal(s.error.value, null);
    assert.equal(s.searchQuery.value, 'Home');
    assert.equal(s.sortedMetrics.value.length, 1);
});
test('closing during seasons bootstrap prevents later metrics reads and mutations', async (t) => {
    const seasons = deferred();
    let signal,
        reads = 0;
    const { app, state: s } = mount('metrics', t, {
        metrics: {
            teamAvailableSeasons: (sport, options) => {
                signal = options.init.signal;
                return seasons.promise;
            },
            teams: async () => {
                reads++;
                return { data: [] };
            },
        },
    });
    app.unmount();
    assert.equal(signal.aborted, true);
    seasons.resolve({ data: [2026] });
    await flush();
    assert.deepEqual(s.availableSeasons.value, []);
    assert.equal(reads, 0);
    await s.fetchMetrics();
    await s.loadSeasons();
    assert.equal(reads, 0);
});
test('closing during a metrics read ignores late success and failure', async (t) => {
    const d = deferred();
    let signal;
    const { app, state: s } = mount('metrics', t, {
        metrics: {
            teamAvailableSeasons: async () => ({ data: [2026] }),
            teams: (sport, options) => {
                signal = options.init.signal;
                return d.promise;
            },
        },
    });
    await flush();
    app.unmount();
    assert.equal(signal.aborted, true);
    d.reject(new Error('Late'));
    await flush();
    assert.equal(s.error.value, null);
    assert.deepEqual(s.metrics.value, []);
});

for (const kind of ['injuries', 'metrics']) {
    test(`${kind}: successful empty responses are not request failures`, async (t) => {
        const { state: s } = mount(kind, t, {
            injuries: { index: async () => ({ data: [], meta: {} }) },
            metrics: {
                teamAvailableSeasons: async () => ({ data: [2026] }),
                teams: async () => ({ data: [], meta: {} }),
            },
        });
        await flush();
        assert.equal(s.loading.value, false);
        assert.equal(s.error.value, null);
        assert.deepEqual((kind === 'injuries' ? s.rows : s.metrics).value, []);
    });
}
