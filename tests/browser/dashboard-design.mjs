import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const manifest = JSON.parse(
        readFileSync(root + 'public/build/manifest.json', 'utf8'),
    ),
    css = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-dashboard-browser-vite',
    optimizeDeps: {
        noDiscovery: true,
        include: [
            'vue',
            'lucide-vue-next',
            'reka-ui',
            '@vueuse/core',
            'class-variance-authority',
            'clsx',
            'tailwind-merge',
        ],
    },
    plugins: [
        {
            name: 'dashboard-browser',
            enforce: 'pre',
            configureServer(vite) {
                vite.middlewares.use('/__dashboard', async (req, res) => {
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            '/__dashboard' + req.url,
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${css}"><style>.beta-indicator{top:5rem}@media(min-width:640px){.beta-indicator{top:.75rem}}</style></head><body><div class="beta-indicator pointer-events-none fixed right-3 z-[100] rounded-full border border-amber-300 bg-amber-100/95 px-2.5 py-1 text-[10px] font-bold tracking-[0.2em] text-amber-900 shadow-sm dark:border-amber-700 dark:bg-amber-900/90 dark:text-amber-100" aria-label="Beta indicator">BETA</div><div id="app"></div><script type="module" src="/tests/frontend/fixtures/dashboardDesign.mjs"></script></body></html>`,
                        ),
                    );
                });
            },
        },
        vue(),
    ],
    resolve: {
        alias: [
            {
                find: '@inertiajs/vue3',
                replacement:
                    root + 'tests/frontend/fixtures/dashboardInertia.mjs',
            },
            { find: '@', replacement: root + 'resources/js' },
        ],
    },
    server: {
        host: '127.0.0.1',
        port: 0,
        watch: { ignored: ['**/public/build/**', '**/frontend/dist/**'] },
    },
});
await server.listen();

let browser;
let checks = 0;
const primary = (page, selector) =>
    page
        .locator(selector)
        .first()
        .evaluate((el) =>
            getComputedStyle(el).getPropertyValue('--primary').trim(),
        );
async function overflow(page) {
    assert.ok(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
        'document overflows',
    );
    const badge = await page.getByLabel('Beta indicator').boundingBox();
    for (const control of [
        page.getByRole('button', { name: 'Scores', exact: true }),
        page.getByRole('heading', { name: 'Today, at a glance.' }),
    ]) {
        const box = await control.boundingBox();
        assert.ok(
            !box ||
                badge.x >= box.x + box.width ||
                box.x >= badge.x + badge.width ||
                badge.y >= box.y + box.height ||
                box.y >= badge.y + badge.height,
            `BETA overlaps dashboard controls: ${JSON.stringify({ width: await page.viewportSize(), badge, box })}`,
        );
    }
}
try {
    browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const url = server.resolvedUrls.local[0] + '__dashboard';
    await page.goto(url);
    await page.waitForFunction(() => globalThis.__dashboardReady, null, {
        timeout: 15000,
    });

    for (const dark of [false, true]) {
        await page.evaluate(
            (dark) => document.documentElement.classList.toggle('dark', dark),
            dark,
        );
        for (const width of [320, 390, 768, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.evaluate(
                () =>
                    new Promise((resolve) =>
                        requestAnimationFrame(() =>
                            requestAnimationFrame(resolve),
                        ),
                    ),
            );
            await overflow(page);
            assert.equal(
                await primary(page, '.ps-workspace'),
                dark ? '#ff9b76' : '#bd4326',
            );
            assert.equal(
                await page.getByRole('link', { name: /View game/ }).count(),
                3,
            );
            assert.match(
                await page
                    .getByRole('region', { name: 'Live games' })
                    .innerText(),
                /HOME 70.0%.*HOME \+5.0 pts/,
            );
            if (width === 390 || width === 1440)
                await page.screenshot({
                    path: `/tmp/dashboard-${width}-${dark ? 'dark' : 'light'}.png`,
                    fullPage: width < 768,
                    animations: 'disabled',
                });
            checks++;
        }
    }
    await page.evaluate(() =>
        document.documentElement.classList.remove('dark'),
    );
    await page.getByRole('button', { name: 'Refresh', exact: true }).click();
    assert.deepEqual(await page.evaluate(() => __dashboardReloads), [
        ['sports', 'stats'],
    ]);
    await page.getByRole('button', { name: 'Scores', exact: true }).click();
    assert.equal(
        await page
            .getByRole('button', { name: 'Scores', exact: true })
            .getAttribute('aria-expanded'),
        'true',
    );
    await page.getByRole('button', { name: 'Scores', exact: true }).click();
    const account = page.locator('[data-test="sidebar-menu-button"]');

    await account.focus();
    await page.keyboard.press('Enter');
    await page.getByRole('menuitem', { name: 'Settings' }).waitFor();
    assert.equal(
        await primary(page, '[data-slot="dropdown-menu-content"]'),
        '#bd4326',
    );
    assert.match(
        await page
            .getByRole('menuitem', { name: 'Settings' })
            .getAttribute('href'),
        /settings/,
    );
    await page.keyboard.press('Escape');
    await page.waitForFunction(
        () =>
            document.activeElement?.getAttribute('data-test') ===
            'sidebar-menu-button',
    );
    await page
        .getByRole('button', { name: 'Open feedback form', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: 'Send feedback' });
    await dialog.waitFor();
    assert.equal(
        await primary(page, '[data-slot="dialog-content"]'),
        '#bd4326',
    );
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });
    await page.getByRole('button', { name: 'Toggle Sidebar' }).click();
    assert.equal(
        await page
            .locator('[data-state="collapsed"][data-collapsible="icon"]')
            .count(),
        1,
    );
    await page.keyboard.press('Control+b');
    assert.equal(
        await page
            .locator('[data-state="expanded"][data-variant="inset"]')
            .count(),
        1,
    );
    for (const dark of [false, true]) {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.evaluate(
            (dark) => document.documentElement.classList.toggle('dark', dark),
            dark,
        );
        await page.getByRole('button', { name: 'Toggle Sidebar' }).click();
        await page.getByRole('dialog', { name: 'Sidebar' }).waitFor();
        assert.equal(
            await primary(page, '[role="dialog"]'),
            dark ? '#ff9b76' : '#bd4326',
        );
        await page.keyboard.press('Escape');
        await page
            .getByRole('dialog', { name: 'Sidebar' })
            .waitFor({ state: 'hidden' });
        await page.waitForFunction(
            () =>
                document.activeElement?.getAttribute('data-slot') ===
                'sidebar-trigger',
        );
    }
    await page.evaluate(() => {
        __dashboardProps.sports.forEach((s) =>
            s.predictions.forEach((p) => {
                p.is_live = false;
                p.is_final = true;
            }),
        );
    });
    assert.match(
        await page.getByRole('region', { name: 'Live games' }).innerText(),
        /FINALS/,
    );
    assert.match(
        await page.getByRole('region', { name: 'Live games' }).innerText(),
        /Recent finals/,
    );
    await page.evaluate(() => {
        __dashboardProps.sports = [];
        __dashboardProps.stats = {
            total_predictions_today: 0,
            total_games_today: 0,
            healthcheck_status: 'degraded',
        };
        __dashboardPage.props.subscription.tier = 'premium';
    });
    await page
        .getByText('No predictions are available yet.', { exact: false })
        .waitFor();
    assert.equal(
        await page
            .getByText('Unlock Unlimited Predictions', { exact: true })
            .count(),
        0,
    );
    assert.equal(await page.getByText('degraded', { exact: true }).count(), 1);
    await overflow(page);
    await page
        .getByRole('button', { name: 'Refresh', exact: true })
        .last()
        .click();
    assert.equal(await page.evaluate(() => __dashboardReloads.length), 2);
    for (const tier of ['free', 'basic', 'pro']) {
        await page.evaluate(
            (tier) => (__dashboardPage.props.subscription.tier = tier),
            tier,
        );
        assert.equal(
            await page.getByRole('link', { name: 'View Plans' }).count(),
            1,
        );
    }
    await page.evaluate(
        () => (__dashboardPage.props.subscription.tiers_enabled = false),
    );
    assert.equal(
        await page.getByRole('link', { name: 'View Plans' }).count(),
        0,
    );
    await page.evaluate(() => {
        __dashboardPage.props.subscription.tiers_enabled = true;
        __dashboardPage.props.impersonation.active = true;
    });
    assert.match(
        await page.locator('body').innerText(),
        /You are impersonating/,
    );
    await page
        .getByRole('button', { name: 'Stop impersonating', exact: true })
        .click();
    assert.deepEqual(await page.evaluate(() => __dashboardPosts), [
        '/impersonation/stop',
    ]);
    await page.getByRole('button', { name: 'Dismiss', exact: true }).click();
    assert.equal(
        await page.getByRole('link', { name: 'View Plans' }).count(),
        0,
    );
    assert.equal(
        await page.evaluate(() =>
            sessionStorage.getItem('subscription-banner-dismissed'),
        ),
        'true',
    );
    await page.goto(url + '?plain=1');
    await page.waitForFunction(() => globalThis.__dashboardReady);
    assert.equal(
        await page.locator('.ps-theme').count(),
        0,
        'theme leaks to other pages',
    );
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.getByRole('menuitem', { name: 'Settings' }).waitFor();
    assert.equal(
        await page
            .locator('[data-slot="dropdown-menu-content"].ps-theme')
            .count(),
        0,
    );
    assert.equal(errors.length, 0, errors.join('\n'));
    console.log(
        `Dashboard design: ${checks} responsive states plus refresh, scores, keyboard navigation, themed portals, finals, empty state, subscription, impersonation, and theme isolation passed.`,
    );
} catch (error) {
    console.error(error);
    throw error;
} finally {
    await browser?.close();
    await server.close();
}
