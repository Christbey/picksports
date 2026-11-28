// Fixture browsers can reach their local Vite server only. API responses are
// supplied by page-level routes; no provider, analytics, or production call runs.
export async function launchFixtureBrowser(chromium, options = {}) {
    const channel =
        process.env.PLAYWRIGHT_BROWSER_CHANNEL ||
        process.env.PLAYWRIGHT_CHANNEL;
    return chromium.launch({
        headless: true,
        ...options,
        ...(channel ? { channel } : {}),
    });
}

export async function isolateFixtureNetwork(context) {
    await context.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (
            ['http:', 'https:'].includes(url.protocol) &&
            !['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname)
        ) {
            await route.abort('blockedbyclient');
            return;
        }
        await route.continue();
    });
}
