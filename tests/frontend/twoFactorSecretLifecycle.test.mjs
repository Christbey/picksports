import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { effectScope } from 'vue';
import { createServer } from 'vite';
let server, useTwoFactorAuth;
before(async () => {
    server = await createServer({
        configFile: false,
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
    ({ useTwoFactorAuth } = await server.ssrLoadModule(
        '/resources/js/composables/useTwoFactorAuth.ts',
    ));
});
after(async () => server?.close());
function setup(t) {
    const original = globalThis.fetch;
    const calls = [];
    globalThis.fetch = (url, options) =>
        new Promise((resolve, reject) =>
            calls.push({ url, ...options, resolve, reject }),
        );
    const scope = effectScope();
    const state = scope.run(() => useTwoFactorAuth());
    state.clearTwoFactorAuthData();
    t.after(() => {
        scope.stop();
        globalThis.fetch = original;
    });
    return { state, calls, scope };
}
const response = (value) => ({ ok: true, json: async () => value });
test('clearing account secrets aborts all pending reads and fences ignored abort responses', async (t) => {
    const { state, calls } = setup(t);
    const setupRead = state.fetchSetupData(),
        recoveryRead = state.fetchRecoveryCodes();
    assert.equal(calls.length, 3);
    state.clearTwoFactorAuthData();
    assert.ok(calls.every((call) => call.signal.aborted));
    calls[0].resolve(response({ svg: 'old-qr' }));
    calls[1].resolve(response({ secretKey: 'old-key' }));
    calls[2].resolve(response(['old-recovery']));
    await Promise.all([setupRead, recoveryRead]);
    assert.equal(state.qrCodeSvg.value, null);
    assert.equal(state.manualSetupKey.value, null);
    assert.deepEqual(state.recoveryCodesList.value, []);
    assert.deepEqual(state.errors.value, []);
});
test('scope disposal revokes pending secret reads and old requests cannot populate a new consumer', async (t) => {
    const { state, calls, scope } = setup(t);
    const oldRead = state.fetchRecoveryCodes();
    scope.stop();
    assert.equal(calls[0].signal.aborted, true);
    const next = useTwoFactorAuth();
    const newRead = next.fetchRecoveryCodes();
    calls[1].resolve(response(['new-account']));
    await newRead;
    calls[0].resolve(response(['old-account']));
    await oldRead;
    assert.deepEqual(next.recoveryCodesList.value, ['new-account']);
    next.clearTwoFactorAuthData();
});
test('a superseded recovery read cannot replace regenerated codes or publish its delayed error', async (t) => {
    const { state, calls } = setup(t);
    const oldRead = state.fetchRecoveryCodes();
    const freshRead = state.fetchRecoveryCodes();
    assert.equal(calls[0].signal.aborted, true);
    calls[1].resolve(response(['regenerated']));
    await freshRead;
    calls[0].reject(new Error('old unavailable'));
    await oldRead;
    assert.deepEqual(state.recoveryCodesList.value, ['regenerated']);
    assert.deepEqual(state.errors.value, []);
});
test('clearing during JSON parsing rejects stale setup data while fresh concurrent setup still works', async (t) => {
    const { state, calls } = setup(t);
    let finishJson;
    const pendingBody = new Promise((resolve) => {
        finishJson = resolve;
    });
    const oldRead = state.fetchQrCode();
    calls[0].resolve({ ok: true, json: () => pendingBody });
    await new Promise((resolve) => setImmediate(resolve));
    state.clearSetupData();
    const freshRead = state.fetchSetupData();
    calls[1].resolve(response({ svg: 'new-qr' }));
    calls[2].resolve(response({ secretKey: 'new-key' }));
    await freshRead;
    finishJson({ svg: 'old-qr' });
    await oldRead;
    assert.equal(state.qrCodeSvg.value, 'new-qr');
    assert.equal(state.manualSetupKey.value, 'new-key');
    assert.equal(state.hasSetupData.value, true);
});
