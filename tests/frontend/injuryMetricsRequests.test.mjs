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
for (const kind of ['injuries']) {
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
