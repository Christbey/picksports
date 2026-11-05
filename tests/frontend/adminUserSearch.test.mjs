import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { effectScope } from 'vue';
import { createServer } from 'vite';
let server, useAdminUserSearch;
before(async () => {
    server = await createServer({
        configFile: false,
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    ({ useAdminUserSearch } = await server.ssrLoadModule(
        '/resources/js/composables/useAdminUserSearch.ts',
    ));
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
const flush = () => new Promise((resolve) => setImmediate(resolve));
const users = (id) => ({
    ok: true,
    json: async () => ({
        users: [{ id, name: `User ${id}`, email: `${id}@example.com` }],
    }),
});
function setup(t) {
    const original = {
        fetch: globalThis.fetch,
        setTimeout: globalThis.setTimeout,
        clearTimeout: globalThis.clearTimeout,
    };
    const timers = new Map(),
        calls = [];
    let id = 0;
    globalThis.setTimeout = (callback, delay) => {
        assert.equal(delay, 250);
        timers.set(++id, callback);
        return id;
    };
    globalThis.clearTimeout = (key) => timers.delete(key);
    globalThis.fetch = (url, options) => {
        const result = deferred();
        calls.push({ url, ...options, ...result });
        return result.promise;
    };
    const scope = effectScope();
    const state = scope.run(() =>
        useAdminUserSearch(
            (key, query) =>
                `/users?group=${key}&query=${encodeURIComponent(query)}`,
        ),
    );
    t.after(() => {
        scope.stop();
        Object.assign(globalThis, original);
    });
    return {
        state,
        scope,
        calls,
        timers,
        tick() {
            const jobs = [...timers.values()];
            timers.clear();
            jobs.forEach((job) => job());
        },
    };
}
test('debounces and trims queries, ignores short input, and preserves lookup headers', async (t) => {
    const { state, calls, timers, tick } = setup(t);
    state.search('founding', 'a');
    assert.equal(timers.size, 0);
    state.search('founding', ' old ');
    state.search('founding', ' new ');
    assert.equal(timers.size, 1);
    assert.equal(calls.length, 0);
    tick();
    assert.equal(calls[0].url, '/users?group=founding&query=new');
    assert.equal(calls[0].headers.Accept, 'application/json');
    assert.equal(calls[0].method, 'GET');
    assert.equal(state.searching.value.founding, true);
    calls[0].resolve(users(1));
    await flush();
    assert.equal(state.suggestions.value.founding[0].id, 1);
    assert.equal(state.searching.value.founding, false);
});
test('clearing input immediately aborts and rejects late responses even if transport ignores abort', async (t) => {
    const { state, calls, tick } = setup(t);
    state.search('founding', 'old');
    tick();
    state.search('founding', '');
    assert.equal(calls[0].signal.aborted, true);
    assert.equal(state.searching.value.founding, false);
    calls[0].resolve(users(1));
    await flush();
    assert.deepEqual(state.suggestions.value.founding, []);
});
test('new input invalidates the active request before debounce and old completion cannot clear newer loading', async (t) => {
    const { state, calls, tick } = setup(t);
    state.search(1, 'old');
    tick();
    state.search(1, 'new');
    assert.equal(calls[0].signal.aborted, true);
    tick();
    calls[0].resolve(users(1));
    await flush();
    assert.equal(state.searching.value[1], true);
    assert.equal(state.suggestions.value[1], undefined);
    calls[1].resolve(users(2));
    await flush();
    assert.equal(state.suggestions.value[1][0].id, 2);
});
test('invalidation also guards delayed JSON parsing and stale errors', async (t) => {
    const { state, calls, tick } = setup(t);
    const body = deferred();
    state.search(1, 'old');
    tick();
    calls[0].resolve({ ok: true, json: () => body.promise });
    await flush();
    state.search(1, 'new');
    tick();
    calls[1].resolve(users(2));
    await flush();
    body.resolve({ users: [{ id: 1 }] });
    await flush();
    assert.equal(state.suggestions.value[1][0].id, 2);
    state.search(1, 'third');
    tick();
    state.search(1, 'fourth');
    tick();
    calls[2].reject(new Error('stale'));
    await flush();
    assert.equal(state.searching.value[1], true);
    calls[3].resolve(users(4));
    await flush();
    assert.equal(state.suggestions.value[1][0].id, 4);
});
test('groups debounce and complete independently; clearing one does not cancel another', async (t) => {
    const { state, calls, tick } = setup(t);
    state.search(1, 'alpha');
    state.search(2, 'bravo');
    tick();
    assert.equal(calls.length, 2);
    state.clear(1);
    assert.equal(calls[0].signal.aborted, true);
    assert.equal(calls[1].signal.aborted, false);
    calls[1].resolve(users(2));
    calls[0].resolve(users(1));
    await flush();
    assert.deepEqual(state.suggestions.value[1], []);
    assert.equal(state.suggestions.value[2][0].id, 2);
});
test('selection cancels pending work; scope disposal cancels timers and in-flight requests', async (t) => {
    const { state, scope, calls, timers, tick } = setup(t);
    state.search('founding', 'selection');
    state.clear('founding');
    assert.equal(timers.size, 0);
    state.search(1, 'active');
    tick();
    state.search(2, 'queued');
    scope.stop();
    assert.equal(timers.size, 0);
    assert.equal(calls[0].signal.aborted, true);
    calls[0].resolve(users(1));
    await flush();
    assert.equal(state.suggestions.value[1], undefined);
    state.search(3, 'after');
    assert.equal(timers.size, 0);
});
test('failed HTTP, malformed payload, and network errors clear results and loading', async (t) => {
    const { state, calls, tick } = setup(t);
    for (const [index, response] of [
        users(1),
        { ok: false },
        { ok: true, json: async () => ({ users: null }) },
        null,
    ].entries()) {
        state.search(1, `query${index}`);
        tick();
        if (response) calls[index].resolve(response);
        else calls[index].reject(new Error('network'));
        await flush();
        assert.equal(state.searching.value[1], false);
        assert.equal(state.suggestions.value[1].length, index === 0 ? 1 : 0);
    }
});
