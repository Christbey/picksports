self.addEventListener('push', (event) => {
    const fallback = {
        title: 'PickSports',
        body: 'You have a new alert.',
        icon: '/apple-touch-icon.png',
        badge: '/icon-192.png',
        data: { url: '/' },
    };

    let payload = fallback;
    if (event.data) {
        try {
            payload = { ...fallback, ...event.data.json() };
        } catch {
            payload = { ...fallback, body: event.data.text() || fallback.body };
        }
    }

    event.waitUntil(
        self.registration.showNotification(payload.title, {
            body: payload.body,
            icon: payload.icon,
            badge: payload.badge,
            tag: payload.tag || undefined,
            data: payload.data || { url: payload.url || '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = event.notification?.data?.url || '/';
    const absoluteTargetUrl = new URL(targetUrl, self.location.origin).toString();

    event.waitUntil((async () => {
        const allClients = await clients.matchAll({
            type: 'window',
            includeUncontrolled: true,
        });
        const targetPattern = 'URLPattern' in self
            ? new URLPattern({ pathname: new URL(absoluteTargetUrl).pathname })
            : null;

        for (const client of allClients) {
            const samePath = targetPattern
                ? targetPattern.test(client.url)
                : new URL(client.url).pathname === new URL(absoluteTargetUrl).pathname;

            if (samePath && 'focus' in client) {
                await client.focus();
                return;
            }
        }

        await clients.openWindow(absoluteTargetUrl);
    })());
});
