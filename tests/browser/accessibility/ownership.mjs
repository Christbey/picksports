// Selected real-component accessibility fixtures; synthetic transport, no auth or delivery.
import assert from 'node:assert/strict';
import { appendFileSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';
import {
    listenFixtureServer,
    fixtureOrigin,
    fixtureNoHmrPlugin,
    launchFixtureBrowser,
    isolateFixtureNetwork,
    writeFixtureNetworkReceipt,
    configureOwnedFixtureOutputs,
} from './runtime.mjs';
const { chromium } = await import('playwright');
const root = fileURLToPath(new URL('../../../', import.meta.url));
const ownershipCases = [
    'user-moved-focus',
    'filter-superseded',
    'request-failed',
    'rapid-activation',
];
const group = process.argv[2];
if (group === '--list') {
    console.log(JSON.stringify({ group: 'ownership', ids: ownershipCases }));
    process.exit(0);
}
configureOwnedFixtureOutputs();
const groups = {
    'navigation-board': [
        'A01',
        'A02',
        'A03',
        'A04',
        'B01',
        'B02',
        'B03',
        'B04',
        'B05',
    ],
    'forms-states': [
        'C01',
        'C02',
        'C03',
        'C04',
        'C05',
        'D01',
        'D02',
        'D03',
        'D04',
    ],
    standalone: ['E01', 'E02'],
};
assert(groups[group] || group === '--list');
if (group !== '--list') assert(process.env.REVIEW_A11Y_TRACE);
const standalone = group === 'standalone';
const manifest = JSON.parse(
    readFileSync(root + 'public/build/manifest.json', 'utf8'),
);
const css = '/build/' + manifest['resources/js/app.ts'].css[0];
let server;
const makeServer = async () =>
    createServer({
        root,
        configFile: false,
        envFile: false,
        envPrefix: 'A11Y_NO_ENV_',
        define: { 'import.meta.env.VITE_API_ORIGIN': '""' },
        cacheDir: process.env.REVIEW_BROWSER_CACHE_DIR,
        optimizeDeps: {
            noDiscovery: true,
            include: [
                'vue',
                'vue-router',
                'lucide-vue-next',
                'reka-ui',
                '@vueuse/core',
                'class-variance-authority',
                'clsx',
                'tailwind-merge',
            ],
        },
        plugins: [
            fixtureNoHmrPlugin(root),
            {
                name: 'a11y-owned-synthetic-entry',
                enforce: 'pre',
                load(id) {
                    if (
                        !standalone &&
                        id.endsWith('/composables/useApiV2Client.ts')
                    )
                        return "export {useApiV2Client} from '/tests/browser/accessibility/board-api.mjs';";
                },
                configureServer(vite) {
                    vite.middlewares.use(async (req, res, next) => {
                        const url = new URL(req.url, 'http://fixture');
                        if (
                            !req.headers.accept?.includes('text/html') ||
                            !(
                                url.pathname === '/__accessibility' ||
                                (standalone &&
                                    [
                                        '/login',
                                        '/nfl/predictions',
                                        '/',
                                    ].includes(url.pathname))
                            )
                        )
                            return next();
                        const id = url.searchParams.get('__a11y');
                        if (!groups[group].includes(id)) {
                            res.statusCode = 404;
                            res.end('Unknown owned fixture');
                            return;
                        }
                        const entry = standalone
                            ? '/frontend/src/main.ts'
                            : '/tests/browser/accessibility/entry.mjs';
                        res.setHeader('Content-Type', 'text/html');
                        res.end(
                            await vite.transformIndexHtml(
                                url.pathname,
                                `<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Owned accessibility fixture</title><link rel="stylesheet" href="${css}"></head><body><div id="app"></div><script>globalThis.__A11Y_CASE_ID=${JSON.stringify(id)};</script><script type="module" src="${entry}"></script></body></html>`,
                            ),
                        );
                    });
                },
            },
            vue(),
            ...(standalone ? [tailwind()] : []),
        ],
        resolve: {
            alias: [
                ...(!standalone
                    ? [
                          {
                              find: '@inertiajs/vue3',
                              replacement:
                                  root +
                                  'tests/browser/accessibility/inertia.mjs',
                          },
                      ]
                    : [
                          {
                              find: '@/platform/AppLayout.vue',
                              replacement: root + 'frontend/src/AppLayout.vue',
                          },
                          {
                              find: '@/platform',
                              replacement:
                                  root + 'frontend/src/platform/index.ts',
                          },
                      ]),
                { find: '@', replacement: root + 'resources/js' },
            ],
        },
        server: {
            host: '127.0.0.1',
            port: 0,
            hmr: false,
            ws: false,
            watch: null,
            allowedHosts: ['127.0.0.1'],
            fs: { strict: true, allow: [root] },
        },
    });

// Four independent real-component deferred-request controls. Transport fixture only; no focus writes.
let browser;
const results = [];
let primaryError = null;
try {
    server = await makeServer();
    await listenFixtureServer(server);
    const origin = fixtureOrigin(server);
    browser = await launchFixtureBrowser(chromium);
    for (const name of ownershipCases) {
        let context,
            page,
            error = null;
        const states = [];
        try {
            context = await browser.newContext({
                viewport: { width: 1440, height: 900 },
                serviceWorkers: 'block',
                acceptDownloads: false,
                reducedMotion: 'reduce',
            });
            await isolateFixtureNetwork(context, origin);
            page = await context.newPage();
            page.setDefaultTimeout(6000);
            page.setDefaultNavigationTimeout(12000);
            const pageErrors = [];
            page.on('pageerror', (e) => pageErrors.push(e.message));
            await page.goto(origin + '/__accessibility?__a11y=B03');
            await page.waitForFunction(() => globalThis.__a11yReady === true);
            const nav = page.getByRole('navigation', {
                    name: 'Prediction pages',
                }),
                next = nav.getByRole('button', { name: 'Next', exact: true });
            await nav.getByText('Page 1 of 2', { exact: true }).waitFor();
            const tabTo = async (target) => {
                for (let i = 0; i < 100; i++) {
                    if (
                        await target.evaluate(
                            (e) => e === document.activeElement,
                        )
                    )
                        return;
                    await page.keyboard.press('Tab');
                }
                assert.fail('Required real control not reachable by keyboard');
            };
            const snap = async (label) => ({
                label,
                focus: await page.evaluate(() => ({
                    tag: document.activeElement?.tagName,
                    id: document.activeElement?.id,
                    text: document.activeElement?.textContent?.slice(0, 100),
                })),
                pending: await page.evaluate(
                    () => __a11yPaginationDiagnostic.pending,
                ),
                calls: await page.evaluate(() => __a11yBoard.calls),
                aria: await page.locator('body').ariaSnapshot(),
            });
            if (name === 'filter-superseded') {
                const filters = page.getByRole('button', {
                    name: 'Filters',
                    exact: true,
                });
                await tabTo(filters);
                await page.keyboard.press('Enter');
                await page.getByLabel('Season', { exact: true }).waitFor();
            }
            await tabTo(next);
            const trigger = await next.elementHandle();
            states.push(await snap('before-request'));
            await page.evaluate(() => __a11yPaginationDiagnostic.hold());
            await page.keyboard.press('Enter');
            await page.waitForFunction(
                () => __a11yPaginationDiagnostic.pending === 1,
            );
            states.push(await snap('request-held'));
            if (name === 'user-moved-focus') {
                const search = page.getByRole('textbox', {
                    name: /Search matchups/,
                });
                await tabTo(search);
                const focus = await search.elementHandle();
                states.push(await snap('user-moved-to-search'));
                await page.evaluate(() => __a11yPaginationDiagnostic.release());
                await nav.getByText('Page 2 of 2', { exact: true }).waitFor();
                await page.waitForFunction(
                    (e) => !e.disabled,
                    await nav
                        .getByRole('button', { name: 'Previous', exact: true })
                        .elementHandle(),
                );
                states.push(await snap('completed-before-assert'));
                assert(
                    await focus.evaluate((e) => e === document.activeElement),
                    'A completed pagination request must not steal user-moved focus',
                );
            } else if (name === 'filter-superseded') {
                const date = page.getByRole('combobox', {
                    name: 'Game date',
                    exact: true,
                });
                assert(
                    await date.isEnabled(),
                    'Supported date filter must remain enabled while page request is held',
                );
                await tabTo(date);
                const chosen = await date.evaluate(
                    (e) =>
                        [...e.options].find(
                            (o) => !o.disabled && o.value !== e.value,
                        )?.value,
                );
                assert(chosen, 'Fixture requires a noncurrent enabled date');
                await date.selectOption(chosen);
                await page.waitForFunction(
                    () => __a11yPaginationDiagnostic.pending >= 2,
                );
                states.push(await snap('filter-superseded-old-request'));
                await page.evaluate(() => __a11yPaginationDiagnostic.release());
                await nav.getByText('Page 1 of 2', { exact: true }).waitFor();
                await page.waitForFunction(
                    (e) => !e.disabled,
                    await nav
                        .getByRole('button', { name: 'Next', exact: true })
                        .elementHandle(),
                );
                states.push(await snap('filter-completed-before-assert'));
                assert.equal(await date.inputValue(), chosen);
                assert(
                    await date.evaluate((e) => e === document.activeElement),
                    'Old pagination must not steal focus from a superseding date filter',
                );
            } else if (name === 'request-failed') {
                await page.evaluate(() => {
                    __a11yBoard.flags.fail = true;
                    __a11yPaginationDiagnostic.release();
                });
                await page.getByRole('alert').first().waitFor();
                await page.waitForFunction((e) => !e.disabled, trigger);
                states.push(await snap('error-completed-before-assert'));
                assert(
                    await trigger.evaluate((e) => e === document.activeElement),
                    'Failed owned pagination must restore its enabled trigger',
                );
                assert(
                    await nav
                        .getByText('Page 1 of 2', { exact: true })
                        .isVisible(),
                );
            } else {
                await page.keyboard.press('Enter');
                await page.keyboard.press('Enter');
                await page.keyboard.press('Space');
                states.push(await snap('rapid-activation-before-assert'));
                assert.equal(
                    await page.evaluate(
                        () => __a11yPaginationDiagnostic.pending,
                    ),
                    1,
                    'Disabled rapid activations must not add provider calls',
                );
                const beforeCalls = await page.evaluate(
                    () => __a11yBoard.calls.length,
                );
                await page.evaluate(() => __a11yPaginationDiagnostic.release());
                await nav.getByText('Page 2 of 2', { exact: true }).waitFor();
                await page.waitForFunction(
                    (e) => !e.disabled,
                    await nav
                        .getByRole('button', { name: 'Previous', exact: true })
                        .elementHandle(),
                );
                states.push(await snap('rapid-completed-before-assert'));
                assert.equal(
                    await page.evaluate(() => __a11yBoard.calls.length),
                    beforeCalls + 1,
                );
                assert.equal(
                    await page.evaluate(
                        () => __a11yPaginationDiagnostic.pending,
                    ),
                    0,
                );
            }
            assert.deepEqual(pageErrors, []);
            results.push({ name, status: 'passed', states });
        } catch (e) {
            error = { name: e.name, message: e.message };
            if (page)
                states.push({
                    label: 'failed-before-outcome',
                    aria: await page.locator('body').ariaSnapshot(),
                });
            results.push({ name, status: 'failed', error, states });
        } finally {
            await context?.close();
        }
    }
} catch (e) {
    primaryError = { name: e.name, message: e.message };
    console.error('PAGINATION_NEGATIVE_PRIMARY_ERROR', e.stack ?? e);
} finally {
    await browser?.close();
    await server?.close();
    appendFileSync(
        process.env.REVIEW_A11Y_TRACE,
        JSON.stringify({
            kind: 'pagination-focus-negative-controls',
            expected: ownershipCases,
            results,
            primaryError,
            scope: 'Real compiled pagination/board and existing source fake provider, deferred transport only. Filter selectOption is an explicit functional supersession control, not native keyboard selection qualification. No focus writes or private component methods.',
        }) + '\n',
        { mode: 0o600 },
    );
    writeFixtureNetworkReceipt();
}
assert.equal(primaryError, null);
assert(
    results.length === 4 && results.every((x) => x.status === 'passed'),
    'Pagination focus ownership controls failed; raw states retained',
);
