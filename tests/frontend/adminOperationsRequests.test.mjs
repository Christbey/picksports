import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { createServer } from 'vite';
import { fileURLToPath } from 'node:url';
let server, useHealthchecks;
before(async () => {
    server = await createServer({
        configFile: false,
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: {
                '@inertiajs/vue3': fileURLToPath(
                    new URL(
                        './fixtures/adminOperationsInertia.mjs',
                        import.meta.url,
                    ),
                ),
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
    });
    useHealthchecks = (
        await server.ssrLoadModule(
            '/resources/js/composables/useHealthchecks.ts',
        )
    ).useHealthchecks;
});
after(async () => server?.close());
test('parallel repair callbacks cannot reopen a still-pending action for duplicate dispatch', () => {
    const calls = [];
    globalThis.__opsPost = (...args) => calls.push(args);
    const state = useHealthchecks({ filters: {}, status_counts: {} });
    state.syncData('nba', 'heartbeat_sync');
    state.syncData('nfl', 'heartbeat_sync');
    state.syncData('nba', 'heartbeat_sync');
    assert.equal(calls.length, 2);
    calls[0][2].onFinish();
    assert.equal(state.syncingCheck.value, 'nfl-heartbeat_sync');
    state.syncData('nfl', 'heartbeat_sync');
    assert.equal(calls.length, 2);
    calls[1][2].onFinish();
    assert.equal(state.syncingCheck.value, null);
    state.syncData('nfl', 'heartbeat_sync');
    assert.equal(calls.length, 3);
});
