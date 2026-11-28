import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRenderer, createSSRApp, nextTick, ssrContextKey } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
let server, cache, Profile;
before(async () => {
    cache = await mkdtemp(join(tmpdir(), 'profile-list-vite-'));
    server = await createServer({
        configFile: false,
        cacheDir: cache,
        plugins: [
            {
                name: 'profile-list-boundary',
                enforce: 'pre',
                resolveId(id) {
                    if (id === '@inertiajs/vue3' || id === '/@profile-inertia')
                        return '\0profile-inertia';
                    if (
                        id.startsWith('@/components/') ||
                        id.startsWith('@/layouts/') ||
                        id.includes('/resources/js/components/') ||
                        id.includes('/resources/js/layouts/')
                    )
                        return '\0profile-child';
                    if (id === '@/composables/useWebAuthnSignal')
                        return '\0profile-signal';
                },
                load(id) {
                    if (id === '\0profile-inertia')
                        return `import {h} from 'vue'; export const usePage=()=>({props:{auth:{user:{id:1,name:'Synthetic account',email:'synthetic@example.test',email_verified_at:'2026-01-01'}}}}); export const Head={render(){return null}}; export const Link={setup(_, {slots}){return()=>h('a',slots.default?.())}}; export const Form={setup(_, {slots}){return()=>h('form',slots.default?.({errors:{},processing:false,recentlySuccessful:false}))}};`;
                    if (id === '\0profile-child')
                        return `import {h} from 'vue'; const C={setup(_, {slots}){return()=>h('div',slots.default?.())}}; export default C; export const Button={setup(_, {slots}){return()=>h('button',slots.default?.())}},Input={render(){return h('input')}},Label=C;`;
                    if (id === '\0profile-signal')
                        return `export const signalCurrentUserDetails=()=>{throw new Error('Unexpected crypto signal')};`;
                },
            },
            vue(),
        ],
        resolve: {
            alias: {
                '@inertiajs/vue3': '/@profile-inertia',
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    Profile = (
        await server.ssrLoadModule('/resources/js/pages/settings/Profile.vue')
    ).default;
});
after(async () => {
    try {
        await server?.close();
    } finally {
        if (cache) await rm(cache, { recursive: true, force: true });
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
    patchProp() {},
    parentNode: () => null,
    nextSibling: () => null,
});
const flush = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
async function mount(t) {
    const original = globalThis.fetch,
        requests = [];
    globalThis.fetch = (url, options) => {
        assert.equal(String(url), '/passkeys');
        assert.equal(options.method, 'GET');
        return new Promise((resolve) => requests.push({ resolve, options }));
    };
    let state;
    const app = renderer.createApp({
        setup() {
            state = Profile.setup({ mustVerifyEmail: false }, { expose() {} });
            return () => null;
        },
    });
    app.provide(ssrContextKey, { modules: new Set() });
    app.mount({});
    t.after(() => {
        app.unmount();
        globalThis.fetch = original;
    });
    assert.equal(
        requests.length,
        1,
        'actual Profile onMounted must request listing',
    );
    const html = () =>
        renderToString(
            createSSRApp(
                {
                    ...Profile,
                    setup() {
                        return state;
                    },
                },
                { mustVerifyEmail: false },
            ),
        );
    const reply = async (
        passkeys = [],
        status = 200,
        index = requests.length - 1,
    ) => {
        const request = requests[index];
        request.resolve(
            new Response(JSON.stringify({ passkeys }), {
                status,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        await flush();
    };
    return { state, requests, html, reply };
}
test('actual mounted Profile does not claim an empty credential list after listing failure', async (t) => {
    const p = await mount(t);
    await p.reply([], 500);
    const html = await p.html();
    assert.match(html, /Failed to load passkeys/);
    assert.doesNotMatch(html, /No passkeys added yet/);
    assert.match(html, /Retry/);
});
test('actual mounted Profile reports successful empty lists', async (t) => {
    const p = await mount(t);
    await p.reply([]);
    assert.match(await p.html(), /No passkeys added yet/);
    assert.doesNotMatch(await p.html(), /Failed to load passkeys/);
});
test('actual mounted Profile keeps existing credentials after refresh fails', async (t) => {
    const p = await mount(t);
    await p.reply([
        {
            id: 7,
            name: 'Existing synthetic device',
            created_at: '2026-01-01T00:00:00Z',
            last_used_at: null,
        },
    ]);
    const pending = p.state.loadPasskeys();
    await p.reply([], 500);
    await pending;
    const html = await p.html();
    assert.match(html, /Existing synthetic device/);
    assert.match(html, /Failed to load passkeys/);
    assert.doesNotMatch(html, /No passkeys added yet/);
});
test('actual mounted Profile keeps pending initial listings distinct from empty results', async (t) => {
    const p = await mount(t);
    assert.match(await p.html(), /Loading passkeys/);
    assert.doesNotMatch(await p.html(), /No passkeys added yet/);
    await p.reply([]);
});
test('actual mounted Profile clears listing failure after a successful retry', async (t) => {
    const p = await mount(t);
    await p.reply([], 500);
    const retry = p.state.loadPasskeys();
    await p.reply([]);
    await retry;
    const html = await p.html();
    assert.match(html, /No passkeys added yet/);
    assert.doesNotMatch(html, /Failed to load passkeys/);
});

const credential = (id, name) => ({
    id,
    name,
    created_at: '2026-01-01T00:00:00Z',
    last_used_at: null,
});
test('an older failed Profile listing cannot overwrite a newer successful list', async (t) => {
    const p = await mount(t);
    const newer = p.state.loadPasskeys();
    assert.equal(p.requests.length, 2);
    await p.reply([credential(2, 'Newer device')], 200, 1);
    await newer;
    await p.reply([], 500, 0);
    const html = await p.html();
    assert.match(html, /Newer device/);
    assert.doesNotMatch(html, /Failed to load passkeys|Retry loading passkeys/);
});
test('an older successful Profile listing cannot replace a newer successful list', async (t) => {
    const p = await mount(t);
    const newer = p.state.loadPasskeys();
    await p.reply([credential(2, 'Newer device')], 200, 1);
    await newer;
    await p.reply([credential(1, 'Older device')], 200, 0);
    const html = await p.html();
    assert.match(html, /Newer device/);
    assert.doesNotMatch(html, /Older device/);
});
test('an older Profile completion cannot clear a newer pending loading state', async (t) => {
    const p = await mount(t);
    const newer = p.state.loadPasskeys();
    await p.reply([], 500, 0);
    const html = await p.html();
    assert.match(html, /Loading passkeys/);
    assert.doesNotMatch(html, /Failed to load passkeys|No passkeys added yet/);
    await p.reply([], 200, 1);
    await newer;
});
