import { createServer } from 'vite';
import { createRenderer, h, nextTick, reactive } from 'vue';
import { fileURLToPath } from 'node:url';
export async function harness() {
    const server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'board-cleanup-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client=()=>globalThis.__boardCleanupApi;';
                },
            },
        ],
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
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
    return {
        server,
        async mount(t, name, api, props = {}) {
            globalThis.__boardCleanupApi = api;
            const hooks = await server.ssrLoadModule(
                '/resources/js/composables/' + name + '.ts',
            );
            let state;
            const input = reactive(props);
            const app = renderer.createApp({
                setup() {
                    state = hooks[name](input);
                    return () => h('div');
                },
            });
            app.mount({});
            t.after(() => app.unmount());
            await flush();
            return { state, app, props: input };
        },
    };
}
export const flush = async () => {
    await new Promise((r) => setImmediate(r));
    await nextTick();
};
export const deferred = () => {
    let resolve, reject;
    const promise = new Promise((a, b) => {
        resolve = a;
        reject = b;
    });
    return { resolve, reject, promise };
};
export function queue() {
    const calls = [];
    const run = (...args) => {
        const job = deferred();
        calls.push({ ...job, args });
        return job.promise;
    };
    return { calls, run };
}
