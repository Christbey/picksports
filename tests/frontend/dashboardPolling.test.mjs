import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { computed, createRenderer, h, nextTick, ref } from 'vue';
import { createServer } from 'vite';
let server, useDashboardPolling;
before(async () => {
    server = await createServer({
        configFile: false,
        resolve: {
            alias: {
                '@inertiajs/vue3': fileURLToPath(
                    new URL('./fixtures/dashboardInertia.mjs', import.meta.url),
                ),
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    ({ useDashboardPolling } = await server.ssrLoadModule(
        '/resources/js/composables/useDashboardView.ts',
    ));
});
after(async () => server?.close());
function setup(t) {
    const original = {
        document: globalThis.document,
        setTimeout: globalThis.setTimeout,
        clearTimeout: globalThis.clearTimeout,
        __dashboardReloads: globalThis.__dashboardReloads,
    };
    const timers = new Map(),
        listeners = new Map(),
        requests = [];
    let id = 0;
    globalThis.document = {
        hidden: false,
        addEventListener: (name, fn) => listeners.set(name, fn),
        removeEventListener: (name) => listeners.delete(name),
    };
    globalThis.setTimeout = (fn, ms) => {
        timers.set(++id, { fn, ms });
        return id;
    };
    globalThis.clearTimeout = (key) => timers.delete(key);
    // The fixture holds the finish callback so the request can overlap a timer.
    globalThis.__dashboardReloads = [];
    globalThis.__dashboardHoldReload = (options) => requests.push(options);
    const renderer = createRenderer({
        createElement: () => ({}),
        createText: () => ({}),
        createComment: () => ({}),
        insert() {},
        remove() {},
        setElementText() {},
        setText() {},
        parentNode: () => null,
        nextSibling: () => null,
        patchProp() {},
    });
    const sports = ref([{ predictions: [{ is_live: true }] }]);
    let state;
    const app = renderer.createApp({
        setup() {
            state = useDashboardPolling({
                sports: computed(() => sports.value),
            });
            return () => h('div');
        },
    });
    app.mount({});
    t.after(() => {
        app.unmount();
        delete globalThis.__dashboardHoldReload;
        Object.assign(globalThis, original);
    });
    return {
        state,
        sports,
        requests,
        timers,
        listeners,
        app,
        tick() {
            const jobs = [...timers.values()];
            timers.clear();
            jobs.forEach(({ fn }) => fn());
        },
    };
}
test('manual refresh overlapping a live poll keeps polling after completion', async (t) => {
    const { state, requests, timers, tick } = setup(t);
    assert.equal([...timers.values()][0].ms, 30_000);
    state.reloadDashboardData();
    tick();
    assert.equal(requests.length, 1);
    assert.deepEqual(requests[0].only, ['sports', 'stats']);
    assert.equal(timers.size, 0);
    requests[0].onFinish();
    assert.equal([...timers.values()][0].ms, 30_000);
    tick();
    assert.equal(requests.length, 2);
});
test('hidden tabs skip requests and visibility resumes with the current cadence', async (t) => {
    const { sports, requests, timers, listeners, tick } = setup(t);
    document.hidden = true;
    tick();
    assert.equal(requests.length, 0);
    sports.value = [];
    await nextTick();
    document.hidden = false;
    listeners.get('visibilitychange')();
    assert.equal(requests.length, 1);
    requests[0].onFinish();
    assert.equal([...timers.values()][0].ms, 300_000);
});
test('unmount clears listeners and prevents an in-flight request from restarting polling', (t) => {
    const { state, requests, timers, listeners, app } = setup(t);
    state.reloadDashboardData();
    app.unmount();
    assert.equal(timers.size, 0);
    assert.equal(listeners.size, 0);
    requests[0].onFinish();
    state.reloadDashboardData();
    assert.equal(timers.size, 0);
    assert.equal(requests.length, 1);
});
