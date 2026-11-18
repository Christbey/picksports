// Synthetic data only. Uses the production pages, cards, API client and pagination.
// Run npm run build first; PLAYWRIGHT_MODULE_PATH may point to an existing installation.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import {
    slateSports,
    slateDate,
    slateParityFixture,
} from '../frontend/fixtures/slateParity.mjs';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const manifest = JSON.parse(
    readFileSync(root + 'public/build/manifest.json', 'utf8'),
);
const css = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-slate-parity-vite',
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
            name: 'slate-parity-fixture',
            configureServer(vite) {
                vite.middlewares.use('/__slate-parity', async (req, res) => {
                    const params = new URL(req.url, 'http://fixture')
                        .searchParams;
                    const view = params.get('view');
                    const source = params.get('source');
                    if (view !== 'dashboard' && !slateSports.includes(view)) {
                        res.statusCode = 404;
                        res.end();
                        return;
                    }
                    const page =
                        view === 'dashboard'
                            ? 'Dashboard'
                            : `${view.toUpperCase()}/Predictions`;
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            '/__slate-parity' + req.url,
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${css}"></head><body><div id="app"></div><script type="module">
import {createApp} from 'vue';
import Page from '/resources/js/pages/${page}.vue';
import {slateParityFixture} from '/tests/frontend/fixtures/slateParity.mjs';
globalThis.__dashboardPage={url:location.pathname,props:{auth:{user:{id:1,name:'Fixture viewer'}},subscription:{tier:'premium',tiers_enabled:false}}};
globalThis.__dashboardReloads=[];globalThis.__dashboardPosts=[];
const fixture=slateParityFixture(${JSON.stringify(source)});
createApp(Page,${view === 'dashboard' ? 'fixture.dashboardProps' : '{}'}).mount('#app');
</script></body></html>`,
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
            {
                find: '@/layouts/AppLayout.vue',
                replacement: root + 'tests/frontend/fixtures/adminLayout.mjs',
            },
            { find: '@', replacement: root + 'resources/js' },
        ],
    },
    server: { host: '127.0.0.1', port: 0, hmr: false, ws: false, watch: null },
});
let browser;
let checks = 0;
const sorted = (ids) => [...ids].sort((a, b) => a - b);
async function linkedIds(scope, sport) {
    return scope
        .locator(`a[href^="/${sport}/games/"]:visible`)
        .evaluateAll((links) => [
            ...new Set(
                links.map((link) =>
                    Number(link.getAttribute('href').split('/').at(-1)),
                ),
            ),
        ]);
}
try {
    await server.listen();
    browser = await chromium.launch({ channel: 'chrome', headless: true });
    for (const source of ['legacy', 'canonical']) {
        for (const width of [1440, 390]) {
            const fixture = slateParityFixture(source);
            const context = await browser.newContext({
                viewport: { width, height: 900 },
            });
            const page = await context.newPage();
            await page.clock.setFixedTime(new Date(`${slateDate}T18:00:00Z`));
            const errors = [],
                requests = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.route('**/api/v2/**', async (route) => {
                const url = new URL(route.request().url());
                requests.push(url);
                try {
                    await route.fulfill({ json: fixture.response(url) });
                } catch (error) {
                    errors.push(error.message);
                    await route.fulfill({
                        status: 500,
                        json: { message: error.message },
                    });
                }
            });
            const base = server.resolvedUrls.local[0] + '__slate-parity';
            await page.goto(`${base}?view=dashboard&source=${source}`);
            const dashboard = new Map();
            for (const sport of slateSports) {
                const region = page.getByRole('region', {
                    name: `${sport.toUpperCase()} fixture slate`,
                    exact: true,
                });
                await region.waitFor();
                const ids = await linkedIds(region, sport);
                assert.deepEqual(
                    sorted(ids),
                    sorted(fixture.forDate(sport).map((row) => row.game_id)),
                    `${sport} dashboard omitted games (${source}, ${width})`,
                );
                dashboard.set(sport, ids);
            }
            for (const sport of slateSports) {
                requests.length = 0;
                await page.goto(`${base}?view=${sport}&source=${source}`);
                const dedicated = ['nfl', 'mlb'].includes(sport);
                const date = page.getByRole('combobox', {
                    name: sport === 'mlb' ? 'Matchup date' : 'Game date',
                    exact: true,
                });
                await date.waitFor();
                await page.waitForFunction(
                    (label) =>
                        document.querySelector(`select[aria-label="${label}"]`)
                            ?.value === '2026-11-17',
                    sport === 'mlb' ? 'Matchup date' : 'Game date',
                );
                const seen = [];
                if (dedicated) {
                    await page.waitForFunction(
                        () =>
                            document.querySelectorAll('button[data-game-id]')
                                .length === 5,
                    );
                    seen.push(
                        ...(await page
                            .locator('button[data-game-id]')
                            .evaluateAll((cards) =>
                                cards.map((card) =>
                                    Number(card.dataset.gameId),
                                ),
                            )),
                    );
                } else {
                    const list = page.locator(
                        '[aria-label="Prediction matchups"]:visible',
                    );
                    for (let current = 1; current <= 3; current++) {
                        await page
                            .getByRole('navigation', {
                                name: 'Prediction pages',
                            })
                            .getByText(`Page ${current} of 3`, { exact: true })
                            .waitFor();
                        await list.waitFor();
                        const ids = await linkedIds(list, sport);
                        assert.equal(
                            ids.length,
                            current === 3 ? 1 : 2,
                            `${sport} page ${current}`,
                        );
                        seen.push(...ids);
                        if (current < 3)
                            await page
                                .getByRole('button', {
                                    name: 'Next',
                                    exact: true,
                                })
                                .click();
                    }
                    assert.equal(
                        await page
                            .getByRole('button', { name: 'Next', exact: true })
                            .isEnabled(),
                        false,
                    );
                    await page
                        .getByRole('button', { name: 'Previous', exact: true })
                        .click();
                    await page
                        .getByText('Page 2 of 3', { exact: true })
                        .waitFor();
                    assert.deepEqual(
                        sorted(await linkedIds(list, sport)),
                        sorted(
                            fixture
                                .forDate(sport)
                                .slice(2, 4)
                                .map((row) => row.game_id),
                        ),
                    );
                }
                assert.equal(
                    new Set(seen).size,
                    seen.length,
                    `${sport} duplicated games across pages`,
                );
                assert.deepEqual(
                    sorted(seen),
                    sorted(dashboard.get(sport)),
                    `${sport} board differs from dashboard (${source}, ${width})`,
                );
                const reads = requests.filter(
                    (url) =>
                        url.pathname === `/api/v2/sports/${sport}/predictions`,
                );
                assert.deepEqual(
                    [
                        ...new Set(
                            reads.map((url) =>
                                Number(url.searchParams.get('page') || 1),
                            ),
                        ),
                    ],
                    [1, 2, 3],
                    `${sport} did not consume all API pages`,
                );
                assert.ok(
                    reads.every(
                        (url) =>
                            url.searchParams.get('from_date') === slateDate &&
                            url.searchParams.get('to_date') === slateDate &&
                            url.searchParams.get('season') === '2026',
                    ),
                );
                assert.equal(await page.getByRole('alert').count(), 0);
                checks++;
            }
            assert.deepEqual(errors, [], `${source}/${width} browser errors`);
            await context.close();
        }
    }
    console.log(
        `Passed ${checks} populated dashboard/board comparisons: seven sports, legacy/canonical payloads, desktop/mobile, all three API pages.`,
    );
} finally {
    await browser?.close();
    await server.close();
}
