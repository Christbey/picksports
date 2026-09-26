import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { ApiError, fetchJson, mutateJson } from '../../resources/js/composables/useApiClient.ts';

const originalFetch = globalThis.fetch;
const originalDocument = globalThis.document;
afterEach(() => {
    globalThis.fetch = originalFetch;
    if (originalDocument === undefined) delete globalThis.document;
    else globalThis.document = originalDocument;
});
const json = (body, status = 200, headers = {}) => new Response(JSON.stringify(body), {
    status, headers: { 'Content-Type': 'application/json', ...headers },
});
const documentWith = (cookie = '', token = null) => {
    globalThis.document = { cookie, querySelector: () => token ? { content: token } : null };
};

test('browser writes send decoded XSRF cookie, cookies, JSON headers and a retry key', async () => {
    documentWith('other=x; XSRF-TOKEN=encoded%2Btoken%3D');
    globalThis.fetch = async (url, init) => {
        assert.equal(url, '/api/v2/user-bets');
        assert.equal(init.credentials, 'same-origin');
        assert.equal(init.headers.get('X-XSRF-TOKEN'), 'encoded+token=');
        assert.equal(init.headers.get('Accept'), 'application/json');
        assert.equal(init.headers.get('X-Requested-With'), 'XMLHttpRequest');
        assert.match(init.headers.get('Idempotency-Key'), /^[\da-f-]{36}$/);
        assert.equal(init.body, '{"amount":10}');
        return json({ data: { id: 1 } }, 201);
    };
    assert.deepEqual(await mutateJson('/api/v2/user-bets', 'POST', { amount: 10 }), { data: { id: 1 } });
});

test('browser writes support meta token fallback and preserve supplied headers', async () => {
    documentWith('', 'meta-token');
    const seen = [];
    globalThis.fetch = async (_, init) => { seen.push(init.headers); return new Response(null, { status: 204 }); };
    await mutateJson('/bets/1', 'DELETE');
    assert.equal(seen[0].get('X-CSRF-TOKEN'), 'meta-token');
    await mutateJson('/bets/1', 'DELETE', undefined, { init: { headers: { 'X-CSRF-TOKEN': 'explicit', 'Idempotency-Key': 'explicit-key' } } });
    assert.equal(seen[1].get('X-CSRF-TOKEN'), 'explicit');
    assert.equal(seen[1].get('Idempotency-Key'), 'explicit-key');
});

test('missing CSRF state is initialized before a browser write', async () => {
    documentWith();
    const calls = [];
    globalThis.fetch = async (url, init) => {
        calls.push(url);
        if (url === '/sanctum/csrf-cookie') {
            globalThis.document.cookie = 'XSRF-TOKEN=fresh';
            return new Response(null, { status: 204 });
        }
        assert.equal(init.headers.get('X-XSRF-TOKEN'), 'fresh');
        return json({ ok: true });
    };
    await mutateJson('/api/v2/groups', 'POST', {});
    assert.deepEqual(calls, ['/sanctum/csrf-cookie', '/api/v2/groups']);
});

test('ambiguous network failures retry with the same key and body; separate writes use new keys', async () => {
    documentWith('', 'token');
    const calls = [];
    globalThis.fetch = async (_, init) => {
        calls.push({ key: init.headers.get('Idempotency-Key'), body: init.body });
        if (calls.length === 1) throw new TypeError('connection lost after commit');
        return json({ ok: true });
    };
    await mutateJson('/api/v2/user-bets', 'POST', { amount: 10 });
    await mutateJson('/api/v2/user-bets', 'POST', { amount: 10 });
    assert.deepEqual(calls[0], calls[1]);
    assert.notEqual(calls[1].key, calls[2].key);
    await mutateJson('/api/v2/user-bets', 'POST', {}, { idempotencyKey: 'retained-operation' });
    assert.equal(calls[3].key, 'retained-operation');
});

test('HTTP errors retain server details for reads and writes and are not retried', async () => {
    documentWith('', 'token');
    for (const status of [401, 403, 419, 422, 429, 500]) {
        for (const run of [() => fetchJson('/api/v2/sports/nfl/games'), () => mutateJson('/api/v2/groups', 'POST', {})]) {
            let calls = 0;
            const body = { error: { code: 'test_code', message: 'Useful error', fields: { name: ['Required'] } } };
            globalThis.fetch = async () => { calls++; return json(body, status, { 'X-Request-ID': 'request-123', 'Retry-After': '60' }); };
            await assert.rejects(run, (error) => {
                assert.ok(error instanceof ApiError);
                assert.equal(error.status, status);
                assert.equal(error.message, 'Useful error');
                assert.equal(error.code, 'test_code');
                assert.equal(error.requestId, 'request-123');
                assert.equal(error.retryAfter, '60');
                assert.deepEqual(error.data, body);
                return true;
            });
            assert.equal(calls, 1);
        }
    }
});

test('non-JSON failures preserve status and cancelled mutations do not retry', async () => {
    globalThis.fetch = async () => new Response('Bad gateway', { status: 502 });
    await assert.rejects(() => fetchJson('/api/v2/sports'), (error) => error.status === 502 && error.data === null);
    let calls = 0;
    globalThis.fetch = async () => { calls++; throw new DOMException('Cancelled', 'AbortError'); };
    await assert.rejects(() => mutateJson('/api/v2/groups', 'POST'), { name: 'AbortError' });
    assert.equal(calls, 1);
});
