import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { createServer } from 'vite';
import { fileURLToPath } from 'node:url';
import { reactive, createRenderer, h, nextTick } from 'vue';
import { mappingsProps, alertProps } from './fixtures/settingsPageSplit.mjs';
let server, useTeamMappings, useAlertPreferences;
before(async () => {
    const root = fileURLToPath(new URL('../../', import.meta.url));
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'settings-routes',
                enforce: 'pre',
                resolveId(id) {
                    if (id.endsWith('/routes/web-push'))
                        return 'virtual:settings-push';
                },
                load(id) {
                    if (id === 'virtual:settings-push')
                        return `export default {subscriptions:{store:{url:()=>'/settings/web-push/subscriptions'},destroy:{url:()=>'/settings/web-push/subscriptions'}},test:{url:()=>'/settings/web-push/test'}};`;
                },
            },
        ],
        resolve: {
            alias: [
                {
                    find: '@inertiajs/vue3',
                    replacement:
                        root +
                        'tests/frontend/fixtures/settingsPageSplitInertia.mjs',
                },
                { find: '@', replacement: root + 'resources/js' },
            ],
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    ({ useTeamMappings } = await server.ssrLoadModule(
        '/resources/js/composables/useTeamMappings.ts',
    ));
    ({ useAlertPreferences } = await server.ssrLoadModule(
        '/resources/js/composables/useAlertPreferences.ts',
    ));
});
after(async () => server?.close());
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
const flush = async () => {
    await new Promise((r) => setImmediate(r));
    await nextTick();
};
function mount(factory, props, t) {
    let state;
    const app = renderer.createApp({
        setup() {
            state = factory(reactive(props));
            return () => h('div');
        },
    });
    app.mount({});
    t.after(() => app.unmount());
    return state;
}
function browser(t) {
    const saved = {
        window: globalThis.window,
        navigator: Object.getOwnPropertyDescriptor(globalThis, 'navigator'),
        Notification: globalThis.Notification,
        document: globalThis.document,
        fetch: globalThis.fetch,
    };
    const calls = [];
    let permission = 'granted',
        subscription = null;
    const sub = {
        endpoint: 'https://fixture.invalid/endpoint',
        toJSON: () => ({
            endpoint: 'https://fixture.invalid/endpoint',
            keys: { p256dh: 'test', auth: 'test' },
        }),
        unsubscribe: async () => {
            calls.push('unsubscribe');
            subscription = null;
            return true;
        },
    };
    const registration = {
        pushManager: {
            getSubscription: async () => subscription,
            subscribe: async (options) => {
                calls.push({ subscribe: options });
                subscription = sub;
                return sub;
            },
        },
    };
    const notification = {
        permission: 'default',
        requestPermission: async () => permission,
    };
    const navigator = {
        serviceWorker: {
            register: async (path) => {
                calls.push({ register: path });
                return registration;
            },
            ready: Promise.resolve(registration),
        },
    };
    globalThis.window = {
        PushManager() {},
        Notification: notification,
        atob,
        matchMedia: () => ({ matches: false }),
        navigator,
    };
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: navigator,
    });
    globalThis.Notification = notification;
    globalThis.document = {
        querySelector: () => ({ getAttribute: () => 'csrf-test' }),
    };
    globalThis.fetch = async (url, options) => {
        calls.push({ url, options });
        return {
            ok: true,
            json: async () => ({ ok: true, message: 'Test fixture sent' }),
        };
    };
    t.after(() => {
        globalThis.window = saved.window;
        globalThis.Notification = saved.Notification;
        globalThis.document = saved.document;
        globalThis.fetch = saved.fetch;
        if (saved.navigator)
            Object.defineProperty(globalThis, 'navigator', saved.navigator);
        else delete globalThis.navigator;
    });
    return { calls, deny: () => (permission = 'denied') };
}
test('mapping navigation preserves provider overrides, query values and page/filter state', (t) => {
    globalThis.__settingsSplitCalls = [];
    const s = mount(useTeamMappings, mappingsProps(), t);
    s.changeSport('americanfootball_nfl');
    s.changeFilter('unmapped');
    s.changePage(2);
    s.changeProvider('cfbd');
    const urls = __settingsSplitCalls.map(
        (c) => new URL(c.url, 'https://fixture.invalid'),
    );
    assert.equal(urls[0].searchParams.get('custom'), 'a & b');
    assert.equal(urls[0].searchParams.get('sport'), 'americanfootball_nfl');
    assert.equal(urls[1].searchParams.get('filter'), 'unmapped');
    assert.equal(urls[2].searchParams.get('page'), '2');
    assert.equal(urls[3].searchParams.get('sport'), 'americanfootball_ncaaf');
    assert.equal(urls[3].searchParams.has('custom'), false);
});
for (const player of [false, true])
    test(`${player ? 'player' : 'team'} mapping edits, suggestions and removal retain their exact payloads`, (t) => {
        globalThis.__settingsSplitCalls = [];
        const props = mappingsProps(player),
            s = mount(useTeamMappings, props, t);
        s.startEdit(props.mappings.data[0]);
        s.selectedEspnName.value = 'Changed';
        s.saveMapping(1);
        const saved = __settingsSplitCalls.at(-1);
        assert.equal(saved.method, 'patch');
        assert.deepEqual(
            saved.data,
            player
                ? { espn_player_name: 'Changed', espn_player_id: null }
                : { espn_team_name: 'Changed' },
        );
        assert.equal(saved.options.preserveScroll, true);
        assert.equal(s.editingMappingId.value, 1);
        saved.options.onSuccess();
        assert.equal(s.editingMappingId.value, null);
        assert.equal(s.selectedEspnName.value, '');
        s.acceptSuggestedMapping(props.mappings.data[1]);
        assert.deepEqual(
            __settingsSplitCalls.at(-1).data,
            player
                ? { espn_player_name: 'Away Player', espn_player_id: 0 }
                : { espn_team_name: 'Away Team' },
        );
        s.removeMapping(2);
        assert.equal(__settingsSplitCalls.at(-1).method, 'delete');
        assert.equal(
            new URL(
                __settingsSplitCalls.at(-1).url,
                'https://fixture.invalid',
            ).searchParams.get('custom'),
            'a & b',
        );
        s.startEdit(props.mappings.data[0]);
        s.cancelEdit();
        assert.equal(s.editingMappingId.value, null);
    });
test('mapping search stays local, props stay reactive, and sync ends only on completion', (t) => {
    globalThis.__settingsSplitCalls = [];
    const props = reactive(mappingsProps()),
        s = mount(useTeamMappings, props, t);
    s.searchQuery.value = 'suggested';
    assert.equal(s.filteredMappings.value.length, 1);
    assert.equal(__settingsSplitCalls.length, 0);
    props.stats.mapped = 0;
    assert.equal(s.mappingPercentage.value, 0);
    s.syncOddsApiMappings();
    assert.equal(s.isSyncing.value, true);
    __settingsSplitCalls.at(-1).options.onFinish();
    assert.equal(s.isSyncing.value, false);
});
test('alert defaults, channel normalization and digest/phone dependencies retain their behavior', (t) => {
    browser(t);
    const s = mount(useAlertPreferences, alertProps('defaults'), t);
    assert.equal(s.alertsEnabled.value, false);
    assert.deepEqual(s.selectedNotificationTypes.value, ['email']);
    assert.equal(s.autoDigestMode.value, 'daily_summary');
    s.toggleNotificationType('push', true);
    s.toggleNotificationType('push', true);
    assert.deepEqual(s.selectedNotificationTypes.value, ['email', 'push']);
    assert.equal(s.autoDigestMode.value, 'realtime');
    s.toggleNotificationType('whatsapp', true);
    assert.equal(s.needsPhoneNumber.value, true);
    s.onEnabledChange('indeterminate');
    assert.equal(s.alertsEnabled.value, false);
    s.onDailyDigestSubscribedChange(false);
    assert.equal(s.dailyDigestSubscribed.value, false);
    s.toggleNotificationType('push', false);
    assert.equal(s.digestModeLabel.value, 'Daily Summary');
});
test('web push enable, test and disable preserve browser sequence, routes and CSRF headers', async (t) => {
    const env = browser(t);
    const props = alertProps('phone'),
        s = mount(useAlertPreferences, props, t);
    await flush();
    assert.equal(s.webPushSupported.value, true);
    await s.enableWebPush();
    assert.equal(s.webPushHasSubscription.value, true);
    assert.ok(s.selectedNotificationTypes.value.includes('push'));
    const subscribe = env.calls.find((c) => c.subscribe).subscribe;
    assert.equal(subscribe.userVisibleOnly, true);
    assert.deepEqual(
        [...new Uint8Array(subscribe.applicationServerKey)],
        [1, 2, 3],
    );
    const post = env.calls.find((c) => c.url);
    assert.equal(post.url, '/settings/web-push/subscriptions');
    assert.equal(post.options.method, 'POST');
    assert.equal(post.options.headers['X-CSRF-TOKEN'], 'csrf-test');
    await s.sendTestPush();
    assert.equal(s.webPushMessage.value, 'Test fixture sent');
    await s.disableWebPush();
    assert.equal(s.webPushHasSubscription.value, false);
    assert.equal(env.calls.at(-1).options.method, 'DELETE');
    assert.deepEqual(JSON.parse(env.calls.at(-1).options.body), {
        endpoint: 'https://fixture.invalid/endpoint',
    });
    assert.equal(s.webPushBusy.value, false);
});
test('web push denied permission retains an error without claiming an active subscription', async (t) => {
    const env = browser(t);
    const s = mount(useAlertPreferences, alertProps(), t);
    env.deny();
    await s.enableWebPush();
    assert.equal(s.webPushHasSubscription.value, false);
    assert.match(s.webPushError.value, /permission was not granted/);
    assert.equal(env.calls.filter((c) => c.url).length, 0);
    assert.equal(s.webPushBusy.value, false);
});

test('failed push saves retain the existing error and do not add a push channel', async (t) => {
    browser(t);
    const s = mount(useAlertPreferences, alertProps('phone'), t);
    globalThis.fetch = async () => ({ ok: false });
    await s.enableWebPush();
    assert.equal(s.webPushHasSubscription.value, false);
    assert.equal(s.selectedNotificationTypes.value.includes('push'), false);
    assert.equal(s.webPushMessage.value, null);
    assert.match(s.webPushError.value, /Could not save push subscription/);
    assert.equal(s.webPushBusy.value, false);
});
test('notification labels normalize stored channel names and existing subscriptions are reused', async (t) => {
    const env = browser(t),
        props = alertProps();
    props.preference.notification_types = [' EMAIL ', ' WHATSAPP ', ''];
    const s = mount(useAlertPreferences, props, t);
    assert.deepEqual(s.selectedNotificationTypes.value, ['email', 'whatsapp']);
    assert.deepEqual(s.selectedNotificationLabels.value, ['Email', 'WhatsApp']);
    assert.equal(s.needsPhoneNumber.value, true);
    await s.enableWebPush();
    await s.enableWebPush();
    assert.equal(env.calls.filter((c) => c.subscribe).length, 1);
});
