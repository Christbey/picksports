import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createRenderer, nextTick, ssrContextKey } from 'vue';
import { fileURLToPath } from 'node:url';

test('board keeps its cards while refreshing and ignores superseded date responses', async () => {
    const server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'board-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__boardApi;';
                },
            },
            vue(),
        ],
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    });
    const pending = [];
    globalThis.__boardApi = {
        predictions: {
            availableSeasons: async () => ({ data: [2026] }),
            availableDates: async () => ({
                data: ['2026-09-28', '2026-09-29', '2026-09-30'],
            }),
            index: async (_, options) =>
                pending.length === 0 && !globalThis.__boardLoaded
                    ? ((globalThis.__boardLoaded = true), { data: [{ id: 1 }] })
                    : new Promise((resolve) =>
                          pending.push({ resolve, options }),
                      ),
        },
    };
    let app;
    try {
        const { default: component } = await server.ssrLoadModule(
            '/resources/js/components/nfl/NflMatchupBoard.vue',
        );
        let state;
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
        app = renderer.createApp({
            setup(props, context) {
                state = component.setup(props, context);
                return () => null;
            },
        });
        app.provide(ssrContextKey, {});
        app.mount({});
        const flush = async () => {
            for (let i = 0; i < 30; i++) await Promise.resolve();
            await nextTick();
        };
        await flush();
        assert.equal(state.predictions.value[0].id, 1);
        state.selectedDate.value = '2026-09-29';
        await flush();
        assert.equal(state.loading.value, false);
        assert.equal(state.predictions.value[0].id, 1);
        state.selectedDate.value = '2026-09-30';
        await flush();
        assert.equal(pending.length, 2);
        assert.ok(pending[0].options.init.signal.aborted);
        pending[1].resolve({ data: [{ id: 3 }] });
        await flush();
        pending[0].resolve({ data: [{ id: 2 }] });
        await flush();
        assert.equal(state.predictions.value[0].id, 3);
    } finally {
        app?.unmount();
        await server.close();
        delete globalThis.__boardApi;
        delete globalThis.__boardLoaded;
    }
});
