import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import {
    createSSRApp,
    createRenderer,
    ssrContextKey,
    effectScope,
    h,
    nextTick,
    reactive,
    unref,
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
                name: 'readiness-inertia',
                enforce: 'pre',
                resolveId(id) {
                    if (
                        id === '/@readiness-inertia' ||
                        id === '@inertiajs/vue3'
                    )
                        return '\0readiness-inertia';
                    if (
                        /\/(TwoFactorRecoveryCodes|TwoFactorSetupModal|AdminGroupUserSearch|UserSearchInput)\.vue$/.test(
                            id,
                        )
                    )
                        return;
                    if (
                        id.startsWith('@/components/') ||
                        id.startsWith('@/layouts/') ||
                        id.includes('/resources/js/components/') ||
                        id.includes('/resources/js/layouts/')
                    )
                        return '\0readiness-component';
                    if (
                        id === '@/composables/useWebAuthnSignal' ||
                        id.endsWith('/composables/useWebAuthnSignal')
                    )
                        return '\0readiness-signal';
                },
                load(id) {
                    if (id === '\0readiness-component')
                        return `import {h} from 'vue'; const Component = {setup(_, {slots}) {return () => h('div', slots.default?.());}}; export default Component; export const Button = {setup(_, {slots}) {return () => h('button', slots.default?.());}}, Input = {render(){return h('input');}}, Label = Component, Card = Component, CardContent = Component, CardDescription = Component, CardHeader = Component, CardTitle = Component, Dialog = Component, DialogContent = Component, DialogDescription = Component, DialogHeader = Component, DialogTitle = Component, InputOTP = Component, InputOTPGroup = Component, InputOTPSlot = Component, Spinner = Component;`;
                    if (id === '\0readiness-inertia')
                        return `import {h, reactive} from 'vue'; export const usePage = () => globalThis.__accountPage; export const router = {post: (...args) => globalThis.__accountPosts.push(args), reload(){}}; export const useForm = (data = {}) => reactive({...data, errors: {}, processing: false, post: (...args) => globalThis.__accountPosts.push(args), reset(){}}); export const Head = {render(){return null;}}; export const Link = {setup(_, {slots}){return () => h('a',slots.default?.());}}; export const Form = {setup(_, {slots}){return () => h('form',slots.default?.({errors:{}, processing:false, recentlySuccessful:false}));}};`;
                    if (id === '\0readiness-signal')
                        return `export const signalCurrentUserDetails = (...args) => {globalThis.__accountSignals.push(args);};`;
                },
            },
            vue(),
        ],
        resolve: {
            alias: {
                '@inertiajs/vue3': '/@readiness-inertia',
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
async function component(path) {
    return (await server.ssrLoadModule(`/resources/js/pages/${path}.vue`))
        .default;
}
async function bindingsFor(component, props) {
    let bindings;
    await renderToString(
        createSSRApp({
            setup() {
                bindings = component.setup(props, { expose() {} });
                return () => h('div');
            },
        }),
    );
    return bindings;
}
test('actual plan template renders valid null and partial tier features without inventing unlimited access', async () => {
    const Plans = await component('Subscription/Plans');
    const html = await renderToString(
        createSSRApp(Plans, {
            tiers: [
                {
                    id: 'custom-null',
                    name: 'Null feature plan',
                    description: '',
                    price: { monthly: 1, yearly: 10 },
                    features: null,
                    is_current: false,
                },
                {
                    id: 'custom-partial',
                    name: 'Partial feature plan',
                    description: '',
                    price: { monthly: 2, yearly: 20 },
                    features: { api_access: true },
                    is_current: false,
                },
            ],
            currentTier: null,
        }),
    );
    assert.match(html, /Null feature plan/);
    assert.match(html, /Partial feature plan/);
    assert.match(html, /Not specified/);
    assert.doesNotMatch(html, /Unlimited/);
});
test('actual subscription templates omit billing actions for nonbillable access and keep paid actions', async () => {
    for (const path of ['Subscription/Manage', 'settings/Subscription']) {
        const Page = await component(path);
        for (const status of [
            'founding_access',
            'tiers_disabled',
            'tier_bypass',
        ]) {
            const html = await renderToString(
                createSSRApp(Page, {
                    subscription: {
                        tier: 'Access',
                        status,
                        current_period_end: null,
                        cancel_at_period_end: false,
                    },
                }),
            );
            assert.doesNotMatch(
                html,
                /Update Payment Method|Cancel Subscription|Resume Subscription/,
            );
        }
        const html = await renderToString(
            createSSRApp(Page, {
                subscription: {
                    tier: 'Paid',
                    status: 'active',
                    current_period_end: null,
                    cancel_at_period_end: false,
                },
            }),
        );
        assert.match(html, /Update Payment Method/);
        assert.match(html, /Cancel Subscription/);
        const bindings = await bindingsFor(Page, {
            subscription: {
                tier: 'Access',
                status: 'founding_access',
                current_period_end: null,
                cancel_at_period_end: false,
            },
        });
        globalThis.__accountPosts = [];
        bindings.openBillingPortal();
        bindings.cancelSubscription();
        bindings.resumeSubscription();
        assert.deepEqual(globalThis.__accountPosts, []);
    }
});
test('profile preserved-instance bindings follow replaced account props and signal the new email after save', async () => {
    globalThis.__accountPage = reactive({
        props: {
            auth: {
                user: {
                    id: 7,
                    email: 'old@example.test',
                    email_verified_at: '2026-11-25',
                },
            },
        },
    });
    globalThis.__accountSignals = [];
    const Profile = await component('settings/Profile');
    const bindings = await bindingsFor(Profile, { mustVerifyEmail: true });
    globalThis.__accountPage.props.auth.user = {
        id: 7,
        email: 'new@example.test',
        email_verified_at: null,
    };
    assert.equal(unref(bindings.user).email, 'new@example.test');
    assert.equal(unref(bindings.user).email_verified_at, null);
    await bindings.handleProfileUpdateSuccess();
    assert.deepEqual(globalThis.__accountSignals, [['7', 'new@example.test']]);
});

test('editing a selected group user disables the actual Add control until a new selection resolves', async () => {
    const { useAdminSettings, adminSettingsKey } = await server.ssrLoadModule(
        '/resources/js/composables/useAdminSettings.ts',
    );
    const GroupSearch = (
        await server.ssrLoadModule(
            '/resources/js/components/admin/AdminGroupUserSearch.vue',
        )
    ).default;
    const scope = effectScope();
    const state = scope.run(() =>
        useAdminSettings(
            reactive({ foundingUsers: { limit: 5 }, groups: [{ id: 3 }] }),
        ),
    );
    try {
        state.selectAssignableUser(3, {
            id: 11,
            name: 'First user',
            email: 'first@example.test',
        });
        const render = () => {
            const app = createSSRApp(GroupSearch, {
                group: { id: 3, name: 'Test pool' },
            });
            app.provide(adminSettingsKey, state);
            return renderToString(app);
        };
        assert.doesNotMatch(
            await render(),
            /<button[^>]*disabled[^>]*>Add User/,
        );
        state.setGroupUserSearch(3, 'Second user');
        assert.equal(state.assignMemberForm.group_id, 3);
        assert.equal(state.assignMemberForm.user_id, null);
        assert.match(await render(), /<button[^>]*disabled[^>]*>Add User/);
        globalThis.__accountPosts = [];
        state.addUserToGroup(3);
        assert.deepEqual(globalThis.__accountPosts, []);
        state.selectAssignableUser(3, {
            id: 12,
            name: 'Second user',
            email: 'second@example.test',
        });
        assert.equal(state.assignMemberForm.user_id, 12);
        state.addUserToGroup(3);
        assert.equal(globalThis.__accountPosts.length, 1);
    } finally {
        scope.stop();
    }
});

test('actual recovery panel hides secrets from markup and assistive technology until explicitly revealed', async () => {
    const { useTwoFactorAuth } = await server.ssrLoadModule(
        '/resources/js/composables/useTwoFactorAuth.ts',
    );
    const state = useTwoFactorAuth();
    state.recoveryCodesList.value = ['synthetic-recovery-secret'];
    const Panel = (
        await server.ssrLoadModule(
            '/resources/js/components/TwoFactorRecoveryCodes.vue',
        )
    ).default;
    const bindings = await bindingsFor(Panel, {});
    const render = () =>
        renderToString(createSSRApp({ ...Panel, setup: () => bindings }));
    try {
        const hidden = await render();
        assert.match(hidden, /aria-hidden="true"/);
        assert.match(hidden, /inert/);
        assert.match(hidden, /aria-expanded="false"/);
        assert.doesNotMatch(hidden, /synthetic-recovery-secret/);
        await bindings.toggleRecoveryCodesVisibility();
        const visible = await render();
        assert.match(visible, /aria-hidden="false"/);
        assert.match(visible, /aria-expanded="true"/);
        assert.doesNotMatch(visible, / inert(?:[ >])/);
        assert.match(visible, /synthetic-recovery-secret/);
        await bindings.toggleRecoveryCodesVisibility();
        assert.doesNotMatch(await render(), /synthetic-recovery-secret/);
    } finally {
        state.clearTwoFactorAuthData();
    }
});

test('actual authentication and setup templates name the recovery input and secret copy control', async () => {
    const Challenge = await component('auth/TwoFactorChallenge');
    const bindings = await bindingsFor(Challenge, {});
    bindings.showRecoveryInput.value = true;
    const recovery = await renderToString(
        createSSRApp({ ...Challenge, setup: () => bindings }),
    );
    assert.match(recovery, /aria-label="Recovery code"/);
    bindings.showRecoveryInput.value = false;
    const otp = await renderToString(
        createSSRApp({ ...Challenge, setup: () => bindings }),
    );
    assert.match(otp, /aria-label="Authentication code"/);
    const { useTwoFactorAuth } = await server.ssrLoadModule(
        '/resources/js/composables/useTwoFactorAuth.ts',
    );
    const state = useTwoFactorAuth();
    state.qrCodeSvg.value = '<svg></svg>';
    state.manualSetupKey.value = 'synthetic-setup-key';
    try {
        const Modal = (
            await server.ssrLoadModule(
                '/resources/js/components/TwoFactorSetupModal.vue',
            )
        ).default;
        const html = await renderToString(
            createSSRApp(Modal, {
                requiresConfirmation: true,
                twoFactorEnabled: false,
                isOpen: true,
            }),
        );
        assert.match(html, /aria-label="Authenticator setup key"/);
        assert.match(html, /aria-label="Copy authenticator setup key"/);
    } finally {
        state.clearTwoFactorAuthData();
    }
});

test('actual billing templates display actionable provider admission errors and distinguish scheduled access end from renewal', async () => {
    for (const page of ['Subscription/Manage', 'settings/Subscription']) {
        const Panel = await component(page);
        const props = {
            subscription: {
                tier: 'Manual access tier',
                status: 'active',
                current_period_end: null,
                scheduled_access_end: '2026-11-30T12:00:00Z',
                cancel_at_period_end: true,
            },
        };
        const bindings = await bindingsFor(Panel, props);
        globalThis.__accountPosts = [];
        bindings.openBillingPortal();
        assert.equal(globalThis.__accountPosts.length, 1);
        const callbacks = globalThis.__accountPosts[0][2];
        callbacks.onError({
            billing:
                'Secure payment management requires support configuration.',
        });
        callbacks.onFinish();
        const html = await renderToString(
            createSSRApp({ ...Panel, setup: () => bindings }, props),
        );
        assert.match(html, /role="alert"/);
        assert.match(
            html,
            /Secure payment management requires support configuration/,
        );
        assert.match(html, /November 30, 2026/);
        assert.doesNotMatch(html, /Next Billing Date/);
        assert.equal(unref(bindings.isProcessingPortal), false);
    }
});

test('actual checkout template reports a refused charge admission instead of silently resetting loading', async () => {
    const Plans = await component('Subscription/Plans');
    const props = {
        tiers: [
            {
                id: 'paid',
                name: 'Paid',
                description: '',
                price: { monthly: 10, yearly: 100 },
                features: {},
                is_current: false,
            },
        ],
        currentTier: null,
    };
    const bindings = await bindingsFor(Plans, props);
    globalThis.__accountPosts = [];
    bindings.subscribe('paid');
    const callbacks = globalThis.__accountPosts[0][2];
    callbacks.onError({
        billing: 'An administrator access override blocks this purchase.',
    });
    callbacks.onFinish();
    const html = await renderToString(
        createSSRApp({ ...Plans, setup: () => bindings }, props),
    );
    assert.match(html, /role="alert"/);
    assert.match(html, /administrator access override blocks this purchase/);
    assert.equal(unref(bindings.isProcessing), false);
});

test('actual billing operation polling fences replaced requests and aborts on unmount', async () => {
    const Panel = await component('Subscription/Operation');
    const originalFetch = globalThis.fetch;
    const requests = [];
    globalThis.fetch = (url, options) =>
        new Promise((resolve) => requests.push({ url, options, resolve }));
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
    const row = (id) => ({
        id,
        kind: 'checkout',
        status: 'pending',
        message: 'Pending',
        url: null,
        result_expired: false,
    });
    const props = reactive({ operation: row(1) });
    let bindings;
    const app = renderer.createApp({
        setup() {
            bindings = Panel.setup(props, { expose() {} });
            return () => null;
        },
    });
    app.provide(ssrContextKey, { modules: new Set() });
    const flush = async () => {
        await new Promise((resolve) => setImmediate(resolve));
        await nextTick();
    };
    try {
        app.mount({});
        assert.equal(requests[0].url, '/subscription/operations/1');
        props.operation = row(2);
        assert.equal(requests[0].options.signal.aborted, true);
        assert.equal(requests[1].url, '/subscription/operations/2');
        requests[0].resolve({
            ok: true,
            json: async () => ({
                ...row(1),
                status: 'completed',
                url: 'https://invoice.stripe.com/old',
            }),
        });
        await flush();
        assert.equal(unref(bindings.current).id, 2);
        assert.equal(unref(bindings.current).url, null);
        requests[1].resolve({
            ok: true,
            json: async () => ({
                ...row(2),
                status: 'session_open',
                url: 'https://checkout.stripe.com/owned',
            }),
        });
        await flush();
        assert.equal(
            unref(bindings.safeContinuation),
            'https://checkout.stripe.com/owned',
        );
        props.operation = row(3);
        app.unmount();
        assert.equal(requests[2].options.signal.aborted, true);
        requests[2].resolve({
            ok: true,
            json: async () => ({
                ...row(3),
                status: 'completed',
                url: 'https://invoice.stripe.com/late',
            }),
        });
        await flush();
        assert.equal(unref(bindings.current).status, 'pending');
        assert.equal(unref(bindings.current).url, null);
    } finally {
        app.unmount();
        globalThis.fetch = originalFetch;
    }
});

test('actual billing operation template suppresses an unapproved continuation scheme or host', async () => {
    const Panel = await component('Subscription/Operation');
    for (const url of [
        'javascript:alert(1)',
        'https://evil.example/checkout',
        'http://checkout.stripe.com/unsafe',
    ]) {
        const html = await renderToString(
            createSSRApp(Panel, {
                operation: {
                    id: 1,
                    kind: 'checkout',
                    status: 'session_open',
                    message: 'Pending',
                    url,
                    result_expired: false,
                },
            }),
        );
        assert.doesNotMatch(html, /Continue securely with Stripe/);
        assert.doesNotMatch(
            html,
            /href="(?:javascript:|https:\/\/evil|http:\/\/checkout)/,
        );
    }
});

test('actual billing views separate paid billing from manual access and do not offer impossible cancel or resume actions', async () => {
    for (const page of ['Subscription/Manage', 'settings/Subscription']) {
        const Panel = await component(page);
        const props = {
            subscription: {
                tier: 'Manual Free',
                billing_tier: 'Paid Pro',
                status: 'active',
                current_period_end: '2026-12-01T12:00:00Z',
                scheduled_access_end: null,
                cancel_at_period_end: false,
                can_cancel: false,
                can_resume: false,
            },
        };
        const bindings = await bindingsFor(Panel, props);
        globalThis.__accountPosts = [];
        bindings.cancelSubscription();
        bindings.resumeSubscription();
        assert.deepEqual(globalThis.__accountPosts, []);
        const html = await renderToString(
            createSSRApp({ ...Panel, setup: () => bindings }, props),
        );
        assert.match(html, /Manual Free/);
        assert.match(html, /Paid Pro/);
        assert.match(html, /Observed Billing Period End/);
        assert.doesNotMatch(
            html,
            /Next Billing Date|Cancel Subscription|Resume Subscription/,
        );
    }
});

test('actual admin batch toolbar renders its labeled cursor and submits one bounded batch', async () => {
    const Panel = await component('Admin/Subscriptions');
    globalThis.__accountPage = reactive({
        props: { auth: { user: { id: 7 } } },
    });
    globalThis.__accountPosts = [];
    const originalConfirm = globalThis.confirm;
    globalThis.confirm = () => true;
    const props = {
        users: {
            data: [],
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: 0,
        },
        tiers: [],
        filters: { search: null },
    };
    try {
        const bindings = await bindingsFor(Panel, props);
        bindings.batchCursor.value = '41';
        const html = await renderToString(
            createSSRApp({ ...Panel, setup: () => bindings }, props),
        );
        assert.match(html, /for="billing-batch-cursor"/);
        assert.match(html, /id="billing-batch-cursor"/);
        assert.match(html, /Queue Billing Batch/);
        bindings.syncAll();
        assert.equal(globalThis.__accountPosts.length, 1);
        assert.deepEqual(globalThis.__accountPosts[0].slice(0, 2), [
            '/admin/subscriptions/sync-all',
            { after_user_id: 41, limit: 20 },
        ]);
    } finally {
        globalThis.confirm = originalConfirm;
    }
});
