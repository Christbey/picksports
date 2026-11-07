export function installPushBrowser(variant) {
    globalThis.__settingsSplitCalls = [];
    globalThis.__settingsSplitFetchCalls = [];
    globalThis.__settingsSplitErrors =
        variant === 'validation'
            ? {
                  enabled: 'Enabled validation error',
                  notification_types: 'Choose a channel',
                  phone_number: 'Phone validation error',
              }
            : {};
    globalThis.__pushDenied = false;
    globalThis.__pushFailure = false;
    let active = variant === 'subscribed';
    const subscription = {
        endpoint: 'https://fixture.invalid/push',
        toJSON: () => ({
            endpoint: 'https://fixture.invalid/push',
            keys: { p256dh: 'fixture', auth: 'fixture' },
        }),
        unsubscribe: async () => {
            active = false;
            return true;
        },
    };
    const registration = {
        pushManager: {
            getSubscription: async () => (active ? subscription : null),
            subscribe: async () => {
                active = true;
                return subscription;
            },
        },
    };
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: {
            register: async () => registration,
            ready: Promise.resolve(registration),
        },
    });
    Object.defineProperty(window, 'PushManager', {
        configurable: true,
        value: function () {},
    });
    Object.defineProperty(window, 'Notification', {
        configurable: true,
        value: {
            permission: 'default',
            requestPermission: async () =>
                globalThis.__pushDenied ? 'denied' : 'granted',
        },
    });
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (url, options) => {
        if (!String(url).startsWith('/settings/web-push/'))
            return nativeFetch(url, options);
        globalThis.__settingsSplitFetchCalls.push({ url, ...options });
        return new Response(
            JSON.stringify({
                ok: !globalThis.__pushFailure,
                message: globalThis.__pushFailure
                    ? 'Fixture push failure'
                    : 'Fixture push success',
            }),
            {
                status: globalThis.__pushFailure ? 500 : 200,
                headers: { 'Content-Type': 'application/json' },
            },
        );
    };
}
