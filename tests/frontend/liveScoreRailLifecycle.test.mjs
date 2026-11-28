import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createRenderer, nextTick, ssrContextKey } from 'vue';
let server, Rail;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            vue(),
            {
                name: 'score-rail-fake-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__scoreRailApi;';
                },
            },
        ],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: [
                {
                    find: '@inertiajs/vue3',
                    replacement: fileURLToPath(
                        new URL(
                            './fixtures/adminAccountInertia.mjs',
                            import.meta.url,
                        ),
                    ),
                },
                {
                    find: '@',
                    replacement: fileURLToPath(
                        new URL('../../resources/js', import.meta.url),
                    ),
                },
            ],
        },
    });
    ({ default: Rail } = await server.ssrLoadModule(
        '/resources/js/components/AppLiveScoreRail.vue',
    ));
});
after(async () => {
    await server?.close();
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
async function withRail(show, run) {
    const original = {
        window: globalThis.window,
        document: globalThis.document,
        setTimeout: globalThis.setTimeout,
        clearTimeout: globalThis.clearTimeout,
    };
    const timers = new Map();
    let id = 0;
    globalThis.window = {};
    globalThis.document = { hidden: false };
    globalThis.setTimeout = (fn, delay) => {
        timers.set(++id, { fn, delay });
        return id;
    };
    globalThis.clearTimeout = (key) => timers.delete(key);
    globalThis.__scoreRailApi = { liveScoreboard: { show } };
    const app = renderer.createApp({ ...Rail, render: () => null });
    app.provide(ssrContextKey, { modules: new Set() });
    app.mount({});
    const take = () => {
        const [key, value] = timers.entries().next().value;
        timers.delete(key);
        return value;
    };
    try {
        await run({ app, timers, take });
    } finally {
        app.unmount();
        Object.assign(globalThis, original);
        delete globalThis.__scoreRailApi;
    }
}
test('score rail retries after an initial network failure', async () => {
    await withRail(
        async () => {
            throw new Error('Synthetic network failure');
        },
        async ({ timers, take }) => {
            const initial = take();
            await Promise.resolve(initial.fn()).catch(() => {});
            await nextTick();
            assert.equal(
                timers.size,
                1,
                'transient failure must leave a bounded retry scheduled',
            );
        },
    );
});
test('closing scores during an in-flight request cannot restart polling', async () => {
    let resolve;
    const pending = new Promise((yes) => {
        resolve = yes;
    });
    await withRail(
        () => pending,
        async ({ app, timers, take }) => {
            const request = take().fn();
            app.unmount();
            resolve({ data: { games: [] } });
            await request;
            await nextTick();
            assert.equal(
                timers.size,
                0,
                'disposed component must not create a new timer',
            );
        },
    );
});
test('successful empty scoreboard keeps the ordinary idle polling interval', async () => {
    await withRail(
        async () => ({ data: { games: [] } }),
        async ({ timers, take }) => {
            await take().fn();
            await nextTick();
            assert.equal(timers.size, 1);
            assert.equal(take().delay, 120_000);
        },
    );
});
