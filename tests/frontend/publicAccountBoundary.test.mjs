import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import {
    createRenderer,
    createSSRApp,
    effectScope,
    h,
    nextTick,
    reactive,
    ref,
} from 'vue';
import { renderToString } from '@vue/server-renderer';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';
let server;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'public-account-fixtures',
                enforce: 'pre',
                resolveId(id) {
                    if (id === '@inertiajs/vue3' || id === '/@boundary-inertia')
                        return '\0boundary-inertia';
                    if (
                        id === '@/composables/useApiV2Client' ||
                        id.endsWith('/composables/useApiV2Client')
                    )
                        return '\0boundary-api';
                    if (
                        id === '@/layouts/AppLayout.vue' ||
                        id.endsWith('/layouts/AppLayout.vue')
                    )
                        return '\0boundary-layout';
                    if (id.startsWith('@/components/ui/'))
                        return '\0boundary-ui';
                },
                load(id) {
                    if (id === '\0boundary-layout')
                        return `export default {setup(_, {slots}){return()=>slots.default?.();}};`;
                    if (id === '\0boundary-inertia')
                        return `export const usePage=()=>globalThis.__boundaryPage; export const router={}; export const Head={setup(){return()=>null;}};`;
                    if (id === '\0boundary-api')
                        return `export const useApiV2Client=()=>globalThis.__boundaryApi;`;
                    if (id === '\0boundary-ui')
                        return `import {h} from 'vue'; const Box={setup(_, {slots,attrs}){return()=>h('div',attrs,slots.default?.());}}; export const Card=Box,CardContent=Box,CardDescription=Box,CardHeader=Box,CardTitle=Box; export const Button={setup(_, {slots,attrs}){return()=>h('button',attrs,slots.default?.());}};`;
                },
            },
            vue(),
        ],
        resolve: {
            alias: {
                '@inertiajs/vue3': '/@boundary-inertia',
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
});
after(async () => server?.close());
const flush = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};

test('actual current-URL composable renders SSR navigation from the request path without a browser origin', async () => {
    globalThis.__boundaryPage = reactive({
        url: '/dashboard?tab=live',
        props: { auth: { user: null } },
    });
    const original = globalThis.window;
    delete globalThis.window;
    try {
        const { useCurrentUrl } = await server.ssrLoadModule(
            '/resources/js/composables/useCurrentUrl.ts',
        );
        const html = await renderToString(
            createSSRApp({
                setup() {
                    const current = useCurrentUrl();
                    return () =>
                        h(
                            'span',
                            current.isCurrentUrl('/dashboard')
                                ? 'Current dashboard'
                                : 'Other',
                        );
                },
            }),
        );
        assert.match(html, /Current dashboard/);
    } finally {
        if (original !== undefined) globalThis.window = original;
    }
});

test('actual personal-bet history exposes next page and requests the numeric resource page instead of ignoring older bets', async () => {
    const calls = [];
    const stats = {
        total_bets: 21,
        total_wagered: 21,
        wins: 0,
        losses: 0,
        pushes: 0,
        win_rate: 0,
        total_profit: 0,
        roi: 0,
    };
    const bet = (id) => ({
        id,
        prediction_id: null,
        prediction_sport: null,
        bet_amount: 1,
        odds: '-110',
        bet_type: 'moneyline',
        selection_side: 'home',
        selection_label: 'Manual pick',
        line: null,
        result: 'pending',
        profit_loss: null,
        notes: null,
        placed_at: '2026-11-26T12:00:00Z',
        settled_at: null,
    });
    globalThis.__boundaryApi = {
        userBets: {
            index: async (options) => {
                const page = options.query?.page ?? 1;
                calls.push(page);
                return {
                    bets: {
                        data: [bet(page === 1 ? 1 : 21)],
                        links: {
                            first: '/api/v2/user-bets?page=1',
                            last: '/api/v2/user-bets?page=2',
                            prev: null,
                            next: 'https://untrusted.example/not-followed',
                        },
                        meta: {
                            current_page: page,
                            last_page: 2,
                            per_page: 20,
                            total: 21,
                        },
                    },
                    statistics: stats,
                };
            },
        },
    };
    const { useMyBets, myBetsKey } = await server.ssrLoadModule(
        '/resources/js/composables/useMyBets.ts',
    );
    const History = (
        await server.ssrLoadModule(
            '/resources/js/components/my-bets/MyBetsHistory.vue',
        )
    ).default;
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
    let state;
    const app = renderer.createApp({
        setup() {
            state = useMyBets();
            return () => null;
        },
    });
    try {
        app.mount({});
        await flush();
        const view = createSSRApp(History);
        view.provide(myBetsKey, state);
        const html = await renderToString(view);
        assert.match(html, /Next page/);
        await state.changePage(2);
        assert.deepEqual(calls, [1, 2]);
        assert.equal(state.bets.value.data[0].id, 21);
        assert.doesNotMatch(html, /untrusted\.example/);
    } finally {
        app.unmount();
    }
});

test('actual page-view telemetry does not retain reset tokens email query or fragments', async () => {
    const originalWindow = globalThis.window,
        originalDocument = globalThis.document;
    globalThis.window = {
        location: {
            href: 'https://app.example/reset-password/synthetic-reset-token?email=private%40example.test#credential',
            pathname: '/reset-password/synthetic-reset-token',
            origin: 'https://app.example',
        },
        dataLayer: [],
    };
    globalThis.document = {
        title: 'Reset password',
        querySelector: () => ({
            content: JSON.stringify(['/reset-password/{token}', '/register']),
        }),
    };
    try {
        const { trackPageView } = await server.ssrLoadModule(
            '/resources/js/lib/analytics.ts',
        );
        trackPageView({
            component: 'auth/ResetPassword',
            url: '/reset-password/synthetic-reset-token',
            props: { auth: { user: null } },
        });
        assert.doesNotMatch(
            JSON.stringify(globalThis.window.dataLayer),
            /synthetic-reset-token|private|credential/,
        );
    } finally {
        if (originalWindow === undefined) delete globalThis.window;
        else globalThis.window = originalWindow;
        if (originalDocument === undefined) delete globalThis.document;
        else globalThis.document = originalDocument;
    }
});

test('credential transitions require a same-origin document boundary and ordinary telemetry drops query and fragments', async () => {
    const oldWindow = globalThis.window,
        oldDocument = globalThis.document;
    const storage = new Map();
    globalThis.window = {
        location: {
            origin: 'https://app.example',
            href: 'https://app.example/privacy?campaign=nonsecret#section',
            pathname: '/privacy',
        },
        dataLayer: [],
        sessionStorage: {
            setItem: (k, v) => storage.set(k, v),
            getItem: (k) => storage.get(k) ?? null,
            removeItem: (k) => storage.delete(k),
        },
    };
    let sensitiveDocument = false;
    globalThis.document = {
        title: 'Privacy',
        querySelector: (selector) => ({
            content: selector.includes('paths')
                ? JSON.stringify([
                      '/group-invitations/{token}',
                      '/subscription/operations/{intent}',
                  ])
                : String(sensitiveDocument),
        }),
    };
    try {
        const navigation = await server.ssrLoadModule(
            '/resources/js/lib/sensitiveNavigation.ts',
        );
        const analytics = await server.ssrLoadModule(
            '/resources/js/lib/analytics.ts',
        );
        assert.equal(
            navigation.requiresDocumentNavigation('/group-invitations/secret'),
            true,
        );
        assert.equal(
            navigation.requiresDocumentNavigation(
                '/subscription/operations/operation',
            ),
            true,
        );
        assert.equal(navigation.requiresDocumentNavigation('/privacy'), false);
        assert.equal(
            navigation.requiresDocumentNavigation(
                'https://untrusted.example/login',
            ),
            false,
        );
        analytics.trackPageView({
            component: 'Privacy',
            url: '/privacy',
            props: { auth: { user: null } },
        });
        assert.equal(
            window.dataLayer.at(-1).page_location,
            'https://app.example/privacy',
        );
        sensitiveDocument = true;
        analytics.setPendingAnalyticsEvent('login_complete', {
            login_method: 'email',
        });
        analytics.flushPendingAnalyticsEvent({
            component: 'Dashboard',
            url: '/dashboard',
            props: { auth: { user: { id: 1 } } },
        });
        assert.equal(
            storage.size,
            1,
            'conversion remains pending until a clean non-sensitive document',
        );
        assert.equal(navigation.requiresDocumentNavigation('/dashboard'), true);
        sensitiveDocument = false;
        analytics.flushPendingAnalyticsEvent({
            component: 'Dashboard',
            url: '/dashboard',
            props: { auth: { user: { id: 1 } } },
        });
        assert.equal(storage.size, 0);
        assert.equal(window.dataLayer.at(-1).event, 'login_complete');
        assert.doesNotMatch(
            JSON.stringify(window.dataLayer),
            /campaign|#section|secret|operation/,
        );
    } finally {
        if (oldWindow === undefined) delete globalThis.window;
        else globalThis.window = oldWindow;
        if (oldDocument === undefined) delete globalThis.document;
        else globalThis.document = oldDocument;
    }
});

test('personal tracked picks are cleared immediately when a persistent card changes authenticated account', async () => {
    globalThis.__boundaryPage = reactive({
        props: { auth: { user: { id: 1 } } },
    });
    const requests = [];
    globalThis.__boundaryApi = {
        userBets: {
            index: (options) =>
                new Promise((resolve) => requests.push({ resolve, options })),
        },
    };
    const { usePredictionCardGame } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionCardGame.ts',
    );
    const { usePredictionTracking } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionTracking.ts',
    );
    const props = reactive({
        sport: 'nfl',
        href: '/nfl/games/1',
        prediction: {
            id: 1,
            game: {
                status: 'STATUS_SCHEDULED',
                home_team: { abbreviation: 'HOME' },
                away_team: { abbreviation: 'AWAY' },
            },
        },
    });
    const scope = effectScope();
    const tracking = scope.run(() =>
        usePredictionTracking(props, usePredictionCardGame(props), ref(false)),
    );
    const option = { betType: 'spread', selectionSide: 'home' };
    try {
        requests[0].resolve({
            tracking: {
                markets: {},
                user_bets: [
                    { id: 11, bet_type: 'spread', selection_side: 'home' },
                ],
            },
        });
        await flush();
        assert.equal(tracking.trackedBetForOption(option).id, 11);
        globalThis.__boundaryPage.props.auth.user = { id: 2 };
        await nextTick();
        assert.equal(
            tracking.trackedBetForOption(option),
            null,
            'former account personal picks must disappear before new response',
        );
        assert.equal(requests.length, 2);
        requests[1].resolve({ tracking: { markets: {}, user_bets: [] } });
        await flush();
        assert.equal(tracking.trackedBetForOption(option), null);
    } finally {
        scope.stop();
    }
});

test('a delayed former-account tracking response cannot repopulate a logged-out persistent card or its next account cache', async () => {
    globalThis.__boundaryPage = reactive({
        props: { auth: { user: { id: 10 } } },
    });
    const requests = [];
    globalThis.__boundaryApi = {
        userBets: {
            index: (options) =>
                new Promise((resolve) => requests.push({ resolve, options })),
        },
    };
    const { usePredictionCardGame } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionCardGame.ts',
    );
    const { usePredictionTracking } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionTracking.ts',
    );
    const props = reactive({
        sport: 'nfl',
        href: '/nfl/games/2',
        prediction: {
            id: 2,
            game: {
                status: 'STATUS_SCHEDULED',
                home_team: { abbreviation: 'HOME' },
                away_team: { abbreviation: 'AWAY' },
            },
        },
    });
    const scope = effectScope();
    const tracking = scope.run(() =>
        usePredictionTracking(props, usePredictionCardGame(props), ref(false)),
    );
    const option = { betType: 'spread', selectionSide: 'home' };
    try {
        tracking.openSaveDialog({
            ...option,
            teamLabel: 'Home',
            title: 'Pick',
            defaultLine: null,
        });
        globalThis.__boundaryPage.props.auth.user = null;
        assert.equal(tracking.isSaveDialogOpen.value, false);
        assert.equal(requests[0].options.init.signal.aborted, true);
        requests[0].resolve({
            tracking: {
                markets: {},
                user_bets: [
                    { id: 99, bet_type: 'spread', selection_side: 'home' },
                ],
            },
        });
        await flush();
        assert.equal(tracking.trackedBetForOption(option), null);
        requests[1].resolve(null);
        await flush();
        globalThis.__boundaryPage.props.auth.user = { id: 11 };
        await nextTick();
        assert.equal(requests.length, 3);
        requests[2].resolve({ tracking: { markets: {}, user_bets: [] } });
        await flush();
        assert.equal(tracking.trackedBetForOption(option), null);
        const reloaded = tracking.handleSaved();
        assert.equal(
            requests.length,
            4,
            'same-account saved pick still refreshes',
        );
        requests[3].resolve({
            tracking: {
                markets: {},
                user_bets: [
                    { id: 110, bet_type: 'spread', selection_side: 'home' },
                ],
            },
        });
        await reloaded;
        assert.equal(tracking.trackedBetForOption(option).id, 110);
    } finally {
        scope.stop();
    }
});

test('actual public performance page distinguishes no winner sample from a measured zero-percent record', async () => {
    const Performance = (
        await server.ssrLoadModule('/resources/js/pages/Performance.vue')
    ).default;
    const overall = {
        total_predictions: 0,
        winner_accuracy: 0,
        avg_spread_error: null,
        avg_total_error: null,
        win_record: '0-0',
        winner_sample_size: 0,
        spread_sample_size: 0,
        total_sample_size: 0,
    };
    const roi = {
        total_bets: 0,
        total_wins: 0,
        total_losses: 0,
        total_pushes: 0,
        total_staked_units: 0,
        priced_bets: 0,
        unpriced_bets: 0,
        total_wagered: 0,
        total_profit: null,
        total_profit_units: null,
        roi_percentage: null,
        win_percentage: null,
        verified: true,
        methodology: 'Recorded settlements',
    };
    const props = {
        overall,
        roi,
        by_sport: {},
        season_to_date: {},
        recent: { overall, roi, by_sport: {} },
    };
    const empty = await renderToString(createSSRApp(Performance, props));
    assert.equal((empty.match(/No graded winner sample/g) ?? []).length, 2);
    const measured = {
        ...overall,
        total_predictions: 2,
        winner_sample_size: 2,
        win_record: '0-2',
    };
    const real = await renderToString(
        createSSRApp(Performance, {
            ...props,
            overall: measured,
            recent: { ...props.recent, overall: measured },
        }),
    );
    assert.doesNotMatch(real, /No graded winner sample/);
    assert.match(real, /0%/);
});
