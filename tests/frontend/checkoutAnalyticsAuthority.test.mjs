import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { fileURLToPath } from 'node:url';
let server, cache, analytics;
before(async () => {
    cache = await mkdtemp(join(tmpdir(), 'checkout-analytics-'));
    server = await createServer({
        configFile: false,
        cacheDir: cache,
        plugins: [
            {
                name: 'checkout-intent-inertia',
                enforce: 'pre',
                resolveId(id) {
                    if (id === '/@checkout-inertia' || id === '@inertiajs/vue3')
                        return '\0checkout-inertia';
                },
                load(id) {
                    if (id === '\0checkout-inertia')
                        return `export const router={post:(...args)=>globalThis.__checkoutPosts.push(args)};export const Head={};`;
                },
            },
            vue(),
        ],
        resolve: {
            alias: {
                '@inertiajs/vue3': '/@checkout-inertia',
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    analytics = await server.ssrLoadModule('/resources/js/lib/analytics.ts');
});
after(async () => {
    try {
        await server?.close();
    } finally {
        if (cache) await rm(cache, { recursive: true, force: true });
    }
});
function environment(t, path = '/subscription/plans') {
    const previous = {
        window: globalThis.window,
        document: globalThis.document,
    };
    const values = new Map();
    const location = (path) => {
        const url = new URL(path, 'https://fixture.invalid');
        return { href: url.href, origin: url.origin, pathname: url.pathname };
    };
    globalThis.window = {
        location: location(path),
        dataLayer: [],
        sessionStorage: {
            setItem: (k, v) => values.set(k, String(v)),
            getItem: (k) => values.get(k) ?? null,
            removeItem: (k) => values.delete(k),
        },
    };
    globalThis.document = {
        querySelector: () => null,
        title: 'Synthetic checkout',
    };
    t.after(() => {
        for (const key of ['window', 'document']) {
            if (previous[key] === undefined) delete globalThis[key];
            else globalThis[key] = previous[key];
        }
    });
    return {
        navigate(path) {
            window.location = location(path);
        },
        events: () => window.dataLayer,
        values,
    };
}
for (const status of [
    'payment_pending',
    'session_open',
    'awaiting_reconciliation',
    'uncertain',
]) {
    test(`a ${status} checkout followed by dashboard navigation does not claim purchase`, (t) => {
        const e = environment(t);
        e.values.set(
            'ps_pending_analytics_event',
            JSON.stringify({
                event: 'purchase',
                params: { plan_id: 'synthetic', event_id: 'local-only' },
                createdAt: Date.now(),
            }),
        );
        e.navigate('/subscription/operations/7');
        analytics.flushPendingAnalyticsEvent({
            url: '/subscription/operations/7',
            props: { auth: { user: { id: 1 } }, operation: { id: 7, status } },
        });
        assert.deepEqual(
            e.events(),
            [],
            'sensitive billing routes must remain excluded from telemetry',
        );
        e.navigate('/dashboard');
        analytics.flushPendingAnalyticsEvent({
            url: '/dashboard',
            props: { auth: { user: { id: 1 } } },
        });
        assert.equal(
            e.events().some((event) => event.event === 'purchase'),
            false,
            'dashboard auth/navigation is not confirmed purchase evidence',
        );
    });
}
test('actual helper preserves authenticated login completion on a nonsensitive destination', (t) => {
    const e = environment(t, '/login');
    analytics.setPendingAnalyticsEvent('login_complete', {
        login_method: 'password',
    });
    e.navigate('/dashboard');
    analytics.flushPendingAnalyticsEvent({
        props: { auth: { user: { id: 1 } } },
    });
    assert.equal(e.events()[0].event, 'login_complete');
});

test('legacy and unknown pending kinds are discarded, not emitted', (t) => {
    const e = environment(t, '/dashboard');
    for (const event of ['purchase', 'unknown_event']) {
        e.values.set(
            'ps_pending_analytics_event',
            JSON.stringify({ event, createdAt: Date.now() }),
        );
        analytics.flushPendingAnalyticsEvent({
            props: { auth: { user: { id: 1 } } },
        });
        assert.deepEqual(e.events(), []);
        assert.equal(e.values.has('ps_pending_analytics_event'), false);
    }
});
test('sign-up completion preserves authentication, expiry and one-time gates', (t) => {
    const e = environment(t, '/dashboard');
    analytics.setPendingAnalyticsEvent('sign_up_complete', {
        sign_up_method: 'email',
    });
    analytics.flushPendingAnalyticsEvent({ props: { auth: { user: null } } });
    assert.deepEqual(e.events(), []);
    analytics.flushPendingAnalyticsEvent({
        props: { auth: { user: { id: 1 } } },
    });
    assert.equal(e.events()[0].event, 'sign_up_complete');
    analytics.flushPendingAnalyticsEvent({
        props: { auth: { user: { id: 1 } } },
    });
    assert.equal(e.events().length, 1);
    e.values.set(
        'ps_pending_analytics_event',
        JSON.stringify({
            event: 'login_complete',
            createdAt: Date.now() - 31 * 60 * 1000,
        }),
    );
    analytics.flushPendingAnalyticsEvent({
        props: { auth: { user: { id: 1 } } },
    });
    assert.equal(e.events().length, 1);
    assert.equal(e.values.size, 0);
});
test('actual Plans subscribe submits intent without staging a purchase', async (t) => {
    const e = environment(t);
    globalThis.__checkoutPosts = [];
    t.after(() => delete globalThis.__checkoutPosts);
    const Plans = (
        await server.ssrLoadModule('/resources/js/pages/Subscription/Plans.vue')
    ).default;
    let state;
    await renderToString(
        createSSRApp({
            setup() {
                state = Plans.setup(
                    {
                        tiers: [
                            {
                                id: 'synthetic-pro',
                                name: 'Synthetic Pro',
                                price: { monthly: 10, yearly: 100 },
                                features: null,
                                is_current: false,
                            },
                        ],
                        currentTier: null,
                    },
                    { expose() {} },
                );
                return () => h('div');
            },
        }),
    );
    state.subscribe('synthetic-pro');
    assert.equal(globalThis.__checkoutPosts.length, 1);
    assert.equal(globalThis.__checkoutPosts[0][0], '/subscription/checkout');
    assert.deepEqual(globalThis.__checkoutPosts[0][1], {
        tier: 'synthetic-pro',
        billing_period: 'monthly',
    });
    assert.equal(e.values.has('ps_pending_analytics_event'), false);
    assert.deepEqual(
        e.events(),
        [],
        'sensitive checkout page remains telemetry suppressed',
    );
});
test('existing begin_checkout intent is retained only where privacy allows', (t) => {
    const e = environment(t, '/dashboard');
    analytics.pushAnalyticsEvent('begin_checkout', { plan_id: 'synthetic' });
    assert.equal(e.events()[0].event, 'begin_checkout');
    e.navigate('/subscription/plans');
    analytics.pushAnalyticsEvent('begin_checkout', { plan_id: 'synthetic' });
    assert.equal(e.events().length, 1);
});
