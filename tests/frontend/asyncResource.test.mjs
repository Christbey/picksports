import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import {
    createRenderer,
    effectScope,
    h,
    nextTick,
    reactive,
    ssrContextKey,
} from 'vue';
import vue from '@vitejs/plugin-vue';

let server;
let useAsyncResource;
let useApiV2Resource;
let useGameDepthCharts;
let theme;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [vue()],
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    ({ useAsyncResource } = await server.ssrLoadModule(
        '/resources/js/composables/useAsyncResource.ts',
    ));
    ({ useApiV2Resource } = await server.ssrLoadModule(
        '/resources/js/composables/useApiV2Resource.ts',
    ));
    ({ useGameDepthCharts } = await server.ssrLoadModule(
        '/resources/js/composables/useGameDepthCharts.ts',
    ));
    theme = await server.ssrLoadModule(
        '/resources/js/composables/useAppearance.ts',
    );
});
after(async () => server?.close());
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
};

const scoped = (factory) => {
    const scope = effectScope();
    return { scope, resource: scope.run(factory) };
};

test('latest request owns data and loading even when an aborted fetch still completes', async () => {
    const requests = [];
    const { scope, resource } = scoped(() =>
        useAsyncResource((signal) => {
            const pending = deferred();
            requests.push({ ...pending, signal });
            return pending.promise;
        }),
    );
    try {
        const first = resource.execute();
        const second = resource.execute();
        assert.equal(requests[0].signal.aborted, true);
        requests[0].resolve('obsolete');
        assert.equal(await first, null);
        assert.equal(resource.data.value, null);
        assert.equal(resource.isLoading.value, true);
        requests[1].resolve('current');
        assert.equal(await second, 'current');
        assert.equal(resource.data.value, 'current');
        assert.equal(resource.isLoading.value, false);
        assert.ok(resource.loadedAt.value instanceof Date);
    } finally {
        scope.stop();
    }
});

test('late failures cannot clear a successful newer result or publish an error', async () => {
    const requests = [];
    const { scope, resource } = scoped(() =>
        useAsyncResource(() => {
            const pending = deferred();
            requests.push(pending);
            return pending.promise;
        }),
    );
    try {
        const first = resource.execute();
        const second = resource.execute();
        requests[1].resolve('new');
        await second;
        requests[0].reject(new Error('old failure'));
        await first;
        assert.equal(resource.data.value, 'new');
        assert.equal(resource.error.value, null);
    } finally {
        scope.stop();
    }
});

test('disposing cancels pending work and prevents later state writes or reloads', async () => {
    const pending = deferred();
    let signal;
    let calls = 0;
    const { scope, resource } = scoped(() =>
        useAsyncResource((value) => {
            signal = value;
            calls++;
            return pending.promise;
        }),
    );
    const request = resource.execute();
    scope.stop();
    assert.equal(signal.aborted, true);
    pending.resolve('late');
    assert.equal(await request, null);
    assert.equal(resource.data.value, null);
    assert.equal(resource.loadedAt.value, null);
    assert.equal(resource.isLoading.value, false);
    assert.equal(await resource.reload(), null);
    assert.equal(calls, 1);
});

test('API envelope metadata follows data atomically and errors clear both', async () => {
    let response = {
        data: [{ id: 1 }],
        meta: { warnings: ['partial'], freshness: { warnings: ['stale'] } },
    };
    const { scope, resource } = scoped(() =>
        useApiV2Resource(async () => {
            if (response instanceof Error) throw response;
            return response;
        }),
    );
    try {
        await resource.execute();
        assert.deepEqual(resource.data.value, [{ id: 1 }]);
        assert.deepEqual(resource.warnings.value, ['partial', 'stale']);
        const loadedAt = resource.loadedAt.value;
        response = new Error('Forbidden');
        await resource.reload();
        assert.equal(resource.error.value, 'Forbidden');
        assert.equal(resource.data.value, null);
        assert.equal(resource.meta.value, null);
        assert.deepEqual(resource.warnings.value, []);
        assert.equal(resource.loadedAt.value, loadedAt);
        response = { data: { id: 2 } };
        await resource.reload();
        assert.deepEqual(resource.data.value, { id: 2 });
        assert.equal(resource.error.value, null);
    } finally {
        scope.stop();
    }
});

test('depth-chart reloads preserve last good data on failure and forward cancellation', async () => {
    const originalFetch = globalThis.fetch;
    let fail = false;
    let signal;
    globalThis.fetch = async (url, init) => {
        assert.equal(url, '/api/v2/sports/nfl/games/42/depth-charts');
        signal = init.signal;
        if (fail) throw new Error('Offline');
        return new Response(
            JSON.stringify({ data: { home: null, away: null } }),
            { headers: { 'Content-Type': 'application/json' } },
        );
    };
    const scope = effectScope();
    try {
        const resource = scope.run(() =>
            useGameDepthCharts('nfl', 42, { autoLoad: false }),
        );
        await resource.reloadDepthCharts();
        assert.ok(signal instanceof AbortSignal);
        assert.deepEqual(resource.depthCharts.value, {
            home: null,
            away: null,
        });
        fail = true;
        await resource.reloadDepthCharts();
        assert.equal(resource.depthChartsError.value, 'Offline');
        assert.deepEqual(resource.depthCharts.value, {
            home: null,
            away: null,
        });
        assert.equal(resource.depthChartsLoading.value, false);
    } finally {
        scope.stop();
        globalThis.fetch = originalFetch;
    }
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

test('system-theme changes update computed state and initialization keeps one listener', () => {
    const originals = {
        window: globalThis.window,
        document: globalThis.document,
        localStorage: globalThis.localStorage,
    };
    const listeners = new Set();
    const classes = new Set();
    const values = new Map([['appearance', 'invalid']]);
    const query = {
        matches: false,
        addEventListener: (_, callback) => listeners.add(callback),
        removeEventListener: (_, callback) => listeners.delete(callback),
    };
    globalThis.window = { matchMedia: () => query };
    globalThis.document = {
        documentElement: {
            classList: {
                toggle: (name, enabled) =>
                    enabled ? classes.add(name) : classes.delete(name),
            },
        },
        cookie: '',
    };
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };
    let state;
    const app = renderer.createApp({
        setup() {
            state = theme.useAppearance();
            return () => h('div');
        },
    });
    try {
        theme.initializeTheme();
        theme.initializeTheme();
        assert.equal(listeners.size, 1);
        app.mount({});
        assert.equal(state.appearance.value, 'system');
        assert.equal(state.resolvedAppearance.value, 'light');
        query.matches = true;
        for (const callback of listeners) callback();
        assert.equal(state.resolvedAppearance.value, 'dark');
        assert.equal(classes.has('dark'), true);
        state.updateAppearance('light');
        for (const callback of listeners) callback();
        assert.equal(state.resolvedAppearance.value, 'light');
        assert.equal(classes.has('dark'), false);
        assert.equal(values.get('appearance'), 'light');
    } finally {
        app.unmount();
        for (const [key, value] of Object.entries(originals)) {
            if (value === undefined) delete globalThis[key];
            else globalThis[key] = value;
        }
    }
});

test('props card derives only the latest sport response and reloads when its limit changes', async () => {
    const originalFetch = globalThis.fetch;
    const requests = [];
    globalThis.fetch = (url, init) => {
        const pending = deferred();
        requests.push({ ...pending, url, signal: init.signal });
        return pending.promise;
    };
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/GamePlayerPropsCard.vue',
    );
    let state;
    const wrapper = {
        props: component.props,
        setup(props, context) {
            state = component.setup(props, context);
            return () => h('div');
        },
    };
    const input = reactive({ sportSlug: 'nfl', gameId: 42, limit: 10 });
    const app = renderer.createApp({ setup: () => () => h(wrapper, input) });
    app.provide(ssrContextKey, { modules: new Set() });
    const respond = (request, name) =>
        request.resolve(
            new Response(
                JSON.stringify({
                    data: [
                        {
                            id: 1,
                            player_name: name,
                            market: 'Yards',
                            line: 50,
                            over_price: -110,
                            recommendation: {
                                side: 'Over',
                                confidence_score: 60,
                                predicted_over_probability: 0.6,
                                market_over_probability: 0.5,
                                edge_probability: 0.1,
                                data_quality_score: 0.9,
                            },
                        },
                        { id: 2, player_name: 'Locked', recommendation: null },
                    ],
                }),
                { headers: { 'Content-Type': 'application/json' } },
            ),
        );
    const settle = async () => {
        await new Promise((resolve) => setImmediate(resolve));
        await nextTick();
    };
    try {
        app.mount({});
        assert.equal(requests.length, 1);
        input.sportSlug = 'nba';
        await nextTick();
        assert.equal(requests.length, 2);
        assert.equal(requests[0].signal.aborted, true);
        assert.match(requests[1].url, /sports\/nba\/games\/42\/player-props/);
        respond(requests[1], 'Current');
        await settle();
        assert.deepEqual(
            state.recommendations.value.map((row) => row.player.name),
            ['Current'],
        );
        respond(requests[0], 'Obsolete');
        await settle();
        assert.equal(state.recommendations.value[0].player.name, 'Current');
        state.selectedMarket.value = 'Yards';
        input.limit = 30;
        await nextTick();
        assert.match(requests[2].url, /per_page=30/);
        requests[2].resolve(
            new Response(JSON.stringify({ data: [] }), {
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        await settle();
        assert.deepEqual(state.recommendations.value, []);
        assert.equal(state.selectedMarket.value, '');
    } finally {
        app.unmount();
        globalThis.fetch = originalFetch;
    }
});
