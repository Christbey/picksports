// Fixture-only harness; not a browser-process or OS egress sandbox.
import assert from 'node:assert/strict';
import { appendFileSync, mkdtempSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, isAbsolute } from 'node:path';
// Node http.Server.listen(0) requests an OS-assigned port directly. Vite's public
// server.listen(0) currently substitutes DEFAULT_DEV_PORT; retain its wrapped init.
export async function listenFixtureServer(server) {
    assert(server.httpServer);
    await new Promise((resolve, reject) => {
        const failed = (error) => reject(error);
        server.httpServer.once('error', failed);
        server.httpServer.listen(0, '127.0.0.1', () => {
            server.httpServer.off('error', failed);
            resolve();
        });
    });
    console.log(
        'FIXTURE_OWNED_LISTEN',
        JSON.stringify({ requestedPort: 0, origin: fixtureOrigin(server) }),
    );
}
export function fixtureNoHmrPlugin(root) {
    const expected = root + 'node_modules/vite/dist/client/client.mjs';
    const connect = 'transport.connect(createHMRHandler(handleMessage));';
    return {
        name: 'owned-fixture-disable-hmr-client-connect',
        enforce: 'pre',
        transform(code, id) {
            if (id.split('?')[0] !== expected) return;
            assert.equal(
                code.split(connect).length,
                2,
                'Pinned Vite client connect site changed',
            );
            // Preserve Vite's actual style/env/hot-context exports. These immutable
            // fixture pages do not test hot reloading and must not open a socket.
            return {
                code: code.replace(
                    connect,
                    '// Owned fixture: hot-reload connection disabled.',
                ),
                map: null,
            };
        },
    };
}
const contexts = [];
const admissions = { allowed: 0, denied: [] };
export function fixtureOrigin(server) {
    const address = server.httpServer.address();
    assert(
        address &&
            typeof address === 'object' &&
            address.address === '127.0.0.1',
    );
    assert(
        Number.isInteger(address.port) &&
            address.port > 0 &&
            address.port < 65536,
    );
    return `http://127.0.0.1:${address.port}`;
}
export function admitsFixtureRequest(route, origin) {
    let url;
    try {
        url = new URL(route.request().url());
    } catch {
        admissions.denied.push({ protocol: 'invalid' });
        return false;
    }
    const allowed = url.protocol === 'http:' && url.origin === origin;
    if (allowed) admissions.allowed++;
    else {
        assert(admissions.denied.length < 100);
        admissions.denied.push({ origin: url.origin, protocol: url.protocol });
    }
    return allowed;
}
export async function launchFixtureBrowser(chromium, options = {}) {
    const executable = process.env.REVIEW_BROWSER_EXECUTABLE;
    assert(
        !executable || isAbsolute(executable),
        'Explicit browser executable must be absolute',
    );
    return chromium.launch({
        ...options,
        headless: true,
        ...(executable ? { executablePath: executable } : {}),
        timeout: 20000,
        args: [
            ...(options.args || []),
            '--disable-background-networking',
            '--disable-component-update',
            '--disable-sync',
            '--disable-default-apps',
            '--disable-breakpad',
            '--no-first-run',
            '--no-default-browser-check',
            '--metrics-recording-only',
        ],
    });
}
export async function isolateFixtureNetwork(context, origin) {
    assert(
        new URL(origin).origin === origin &&
            /^http:\/\/127\.0\.0\.1:\d+$/.test(origin),
    );
    const state = {
        origin,
        allowed: 0,
        denied: [],
        websockets: 0,
        webSocketUrls: [],
    };
    contexts.push(state);
    await context.routeWebSocket('**/*', (socket) => {
        state.websockets++;
        const url = new URL(socket.url());
        assert(state.webSocketUrls.length < 100);
        state.webSocketUrls.push({
            urlWithoutQuery: url.origin + url.pathname,
            queryKeys: [...url.searchParams.keys()],
        });
        socket.close();
    });
    await context.route('**/*', async (route) => {
        if (!admitsFixtureRequest(route, origin)) {
            assert(
                state.denied.length < 100,
                'Unexpected network request budget exceeded',
            );
            const url = new URL(route.request().url());
            state.denied.push({ origin: url.origin, protocol: url.protocol });
            await route.abort('blockedbyclient');
            return;
        }
        assert(++state.allowed <= 30000, 'Fixture request budget exceeded');
        await route.continue();
    });
    context.on('page', (page) => {
        page.setDefaultTimeout(15000);
        page.setDefaultNavigationTimeout(20000);
    });
}
export function writeFixtureNetworkReceipt() {
    assert(process.env.REVIEW_BROWSER_NETWORK_TRACE);
    appendFileSync(
        process.env.REVIEW_BROWSER_NETWORK_TRACE,
        JSON.stringify({ contexts, admissions }) + '\n',
        { flag: 'a', mode: 0o600 },
    );
    assert(contexts.length > 0);
    assert(admissions.allowed > 0 && admissions.denied.length === 0);
    assert(
        contexts.every((x) => x.denied.length === 0 && x.websockets === 0),
        'Unexpected network attempt; receipt retained',
    );
}

// No downloads/profile reuse. CI uses its preinstalled Playwright Chromium; local callers may supply an absolute executable.
export function configureOwnedFixtureOutputs() {
    const keys = [
        'REVIEW_A11Y_TRACE',
        'REVIEW_A11Y_OUTCOMES',
        'REVIEW_BROWSER_NETWORK_TRACE',
    ];
    if (keys.every((key) => process.env[key])) return;
    assert(
        keys.every((key) => !process.env[key]),
        'Supply all fixture evidence paths together',
    );
    const owner = mkdtempSync(join(tmpdir(), 'picksports-accessibility-'));
    const cache = join(owner, 'cache');
    mkdirSync(cache, { mode: 0o700 });
    process.env.REVIEW_BROWSER_CACHE_DIR = cache;
    process.env.REVIEW_A11Y_TRACE = join(owner, 'states.jsonl');
    process.env.REVIEW_A11Y_OUTCOMES = join(owner, 'outcomes.jsonl');
    process.env.REVIEW_BROWSER_NETWORK_TRACE = join(owner, 'network.jsonl');
    console.log('FIXTURE_EVIDENCE_DIRECTORY', owner);
}
