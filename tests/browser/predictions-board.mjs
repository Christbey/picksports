// Run npm run build first. Synthetic fixtures only; BOARD_BASELINE_REF enables exact visual comparisons.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const baseline = process.env.BOARD_BASELINE_REF;
const original = baseline
    ? execFileSync(
          'git',
          ['show', `${baseline}:resources/js/components/SportPredictions.vue`],
          { cwd: root, encoding: 'utf8' },
      )
    : null;
const manifest = JSON.parse(
    readFileSync(`${root}public/build/manifest.json`, 'utf8'),
);
const stylesheet = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-predictions-browser-vite',
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
            name: 'predictions-board-fixture',
            resolveId(id) {
                if (id.endsWith('__BeforePredictions.vue'))
                    return `${root}resources/js/components/__BeforePredictions.vue`;
            },
            load(id) {
                if (id.endsWith('__BeforePredictions.vue')) return original;
            },
            configureServer(vite) {
                vite.middlewares.use(
                    '/__predictions-board',
                    async (request, response) => {
                        const query = new URL(request.url, 'http://fixture')
                            .searchParams;
                        const name =
                            query.get('before') === '1'
                                ? '__BeforePredictions'
                                : 'SportPredictions';
                        response.setHeader('Content-Type', 'text/html');
                        response.end(
                            await vite.transformIndexHtml(
                                '/__predictions-board' + request.url,
                                `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${stylesheet}"></head><body><main class="mx-auto max-w-6xl p-4"><div id="app"></div></main><script type="module">
import {createApp} from 'vue';
import Board from '/resources/js/components/${name}.vue';
import {predictionBoardUi} from '/tests/frontend/fixtures/predictionBoardUi.mjs';
const fixture=predictionBoardUi(${JSON.stringify(query.get('sport'))},${JSON.stringify(query.get('mode'))},${JSON.stringify(query.get('scenario') ?? 'normal')});
globalThis.__predictionBoardUiApi=fixture.api;globalThis.__boardCalls=fixture.calls;globalThis.__boardFlags=fixture.flags;globalThis.__boardHidden=false;
Object.defineProperty(document,'hidden',{configurable:true,get:()=>globalThis.__boardHidden});
const interval=window.setInterval.bind(window),clear=window.clearInterval.bind(window);
window.setInterval=(callback,delay,...args)=>{if(delay===30000){globalThis.__boardTick=callback;return 987654;}return interval(callback,delay,...args);};window.clearInterval=id=>{if(id===987654)globalThis.__boardTick=null;else clear(id);};
createApp(Board,{config:fixture.config}).mount('#app');
</script></body></html>`,
                            ),
                        );
                    },
                );
            },
        },
        vue(),
    ],
    resolve: {
        alias: [
            {
                find: '@inertiajs/vue3',
                replacement: `${root}tests/frontend/fixtures/playerInertia.mjs`,
            },
            {
                find: '@/composables/useApiV2Client',
                replacement: `${root}tests/frontend/fixtures/predictionBoardUiApi.mjs`,
            },
            { find: '@', replacement: `${root}resources/js` },
        ],
    },
    server: { host: '127.0.0.1', port: 0, hmr: false, ws: false, watch: null },
});
const images = new Map();
let browser;
async function imageHash(page) {
    await page.mouse.move(0, 0);
    await page.addStyleTag({
        content:
            '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}',
    });
    await page.evaluate(() => document.fonts.ready);
    let previous;
    for (let n = 0; n < 5; n++) {
        await page.evaluate(
            () =>
                new Promise((resolve) =>
                    requestAnimationFrame(() => requestAnimationFrame(resolve)),
                ),
        );
        const hash = createHash('sha256')
            .update(await page.screenshot({ animations: 'disabled' }))
            .digest('hex');
        if (hash === previous) return hash;
        previous = hash;
    }
    throw new Error('Screenshot did not stabilize');
}
const scenarios = [
    ['nba', 'date', 'normal'],
    ['cfb', 'seasonWeek', 'normal'],
    ['cfb', 'date', 'normal'],
    ['mlb', 'date', 'normal'],
    ['nba', 'none', 'normal'],
    ['nba', 'date', 'empty'],
    ['nba', 'date', 'error'],
];
try {
    await server.listen();
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
    });
    for (const before of baseline ? [true, false] : [false]) {
        const page = await browser.newPage({
            viewport: { width: 1280, height: 900 },
        });
        page.setDefaultTimeout(15000);
        const errors = [];
        page.on('pageerror', (error) => {
            errors.push(error.message);
            console.error(error.message);
        });
        await page.route('**/*', (route) =>
            new URL(route.request().url()).hostname === '127.0.0.1'
                ? route.continue()
                : route.abort(),
        );
        for (const [sport, mode, scenario] of scenarios) {
            await page.goto(
                `http://127.0.0.1:${server.httpServer.address().port}/__predictions-board/${sport}-${mode}-${scenario}-${before}?sport=${sport}&mode=${mode}&scenario=${scenario}&before=${before ? 1 : 0}`,
                { waitUntil: 'domcontentloaded' },
            );
            if (scenario === 'normal')
                await page.locator(`a[href="/${sport}/games/1"]`).waitFor();
            else if (scenario === 'error')
                await page
                    .getByText('Failed to fetch predictions', { exact: true })
                    .waitFor();
            else
                await page
                    .getByText('No prediction dates available yet', {
                        exact: true,
                    })
                    .waitFor();
            for (const width of [320, 390, 1280]) {
                await page.setViewportSize({ width, height: 900 });
                if (!before) {
                    assert.equal(
                        await page.evaluate(
                            () =>
                                document.documentElement.scrollWidth <=
                                window.innerWidth + 1,
                        ),
                        true,
                        `Page overflow at ${width}px`,
                    );
                    if (width < 640 && scenario === 'normal') {
                        for (const control of await page
                            .locator(
                                '#prediction-search, #week, #game-date, [aria-label="Board view"] button, [aria-label="Prediction pages"] button',
                            )
                            .all()) {
                            const rect = await control.boundingBox();
                            assert.ok(
                                rect.height >= 44 && rect.width >= 44,
                                'Mobile controls need 44px touch targets',
                            );
                        }
                    }
                    const regions = page
                        .getByRole('region')
                        .filter({ has: page.locator('table') });
                    for (const region of await regions.all()) {
                        assert.ok(await region.getAttribute('aria-label'));
                        assert.equal(
                            await region.getAttribute('tabindex'),
                            '0',
                        );
                        const scrollable = await region.evaluate(
                            (el) => el.scrollWidth > el.clientWidth + 1,
                        );
                        if (scrollable) {
                            await region.focus();
                            await page.keyboard.press('ArrowRight');
                            await page.waitForFunction(
                                (el) => el.scrollLeft > 0,
                                await region.elementHandle(),
                            );
                            await region.evaluate((el) => (el.scrollLeft = 0));
                        }
                    }
                    await page.evaluate(() => document.activeElement?.blur());
                }

                for (const dark of [false, true]) {
                    await page.evaluate(
                        (value) =>
                            document.documentElement.classList.toggle(
                                'dark',
                                value,
                            ),
                        dark,
                    );
                    const key = `${sport}-${mode}-${scenario}-${width}-${dark}`;
                    const hash = await imageHash(page);
                    if (before) images.set(key, hash);
                    else if (baseline)
                        assert.equal(
                            hash,
                            images.get(key),
                            `Layout changed: ${key}`,
                        );
                }
            }
            if (!before && scenario === 'error') {
                assert.equal(
                    await page
                        .getByText('No prediction dates available yet', {
                            exact: true,
                        })
                        .count(),
                    0,
                );
                await page.evaluate(() => {
                    __boardFlags.fail = false;
                });
                await page
                    .getByRole('button', { name: 'Try again', exact: true })
                    .click();
                await page
                    .locator(`a[href="/${sport}/games/1"]`)
                    .first()
                    .waitFor();
                assert.equal(await page.getByRole('alert').count(), 0);
            }
            if (scenario === 'normal') {
                await page
                    .getByLabel('Search matchups on this page')
                    .fill('Tigers');
                await page.waitForFunction(
                    () =>
                        document.querySelectorAll('a[href*="/games/"]')
                            .length === 1,
                );
                await page.getByLabel('Search matchups on this page').fill('');
                await page.waitForFunction(
                    () =>
                        document.querySelectorAll('a[href*="/games/"]')
                            .length === 2,
                );
                await page.getByRole('button', { name: /^Bets/ }).click();
                await page.waitForFunction(
                    () =>
                        document.querySelectorAll('a[href*="/games/"]')
                            .length === 1,
                );
                await page
                    .getByRole('button', { name: 'All games', exact: true })
                    .click();
                await page
                    .getByRole('button', { name: 'Filters', exact: true })
                    .click();
                await page.locator('#predictions-season').waitFor();
                const filterKey = `${sport}-${mode}-filters`;
                const filterImage = await imageHash(page);
                if (before) images.set(filterKey, filterImage);
                else if (baseline)
                    assert.equal(filterImage, images.get(filterKey), filterKey);
                if (mode === 'seasonWeek') {
                    await page
                        .locator('#season-type')
                        .selectOption('Postseason');
                    await page.waitForFunction(
                        () =>
                            globalThis.__boardCalls.at(-1)?.query
                                .season_type === '3',
                    );
                    assert.equal(await page.locator('#week').inputValue(), '');
                }
                await page
                    .getByRole('button', { name: 'Next', exact: true })
                    .click();
                await page.locator(`a[href="/${sport}/games/3"]`).waitFor();
                if (sport === 'cfb') {
                    await page
                        .getByRole('button', {
                            name: 'Show analysis for AWY3 at HOM3',
                            exact: true,
                        })
                        .click();
                    await page
                        .getByRole('button', {
                            name: 'Hide analysis for AWY3 at HOM3',
                            exact: true,
                        })
                        .waitFor();
                    const count = await page.evaluate(
                        () => __boardCalls.length,
                    );
                    await page.evaluate(() => {
                        __boardHidden = true;
                        __boardTick();
                    });
                    assert.equal(
                        await page.evaluate(() => __boardCalls.length),
                        count,
                    );
                    await page.evaluate(() => {
                        __boardHidden = false;
                        __boardFlags.live = true;
                        __boardTick();
                    });
                    await page.waitForFunction(
                        () =>
                            globalThis.__boardCalls.at(-1)?.query.page === 2 &&
                            document.body.innerText.includes('12:00'),
                    );
                    assert.equal(
                        await page
                            .getByRole('button', {
                                name: 'Hide analysis for AWY3 at HOM3',
                                exact: true,
                            })
                            .getAttribute('aria-expanded'),
                        'true',
                    );
                    assert.equal(
                        await page.evaluate(() => __boardCalls.at(-1).cache),
                        'no-store',
                    );
                    await page.evaluate(() => {
                        __boardFlags.fail = true;
                        __boardTick();
                    });
                    await page
                        .getByRole('status')
                        .filter({ hasText: 'Live refresh delayed' })
                        .waitFor();
                    assert.equal(
                        await page
                            .locator(`a[href="/${sport}/games/3"]`)
                            .count(),
                        2,
                    );
                    if (!before) {
                        await page.evaluate(() => {
                            __boardFlags.fail = false;
                        });
                        await page
                            .getByRole('button', {
                                name: 'Try again',
                                exact: true,
                            })
                            .click();
                        await page.waitForFunction(
                            () =>
                                !document.body.innerText.includes(
                                    'Live refresh delayed',
                                ),
                        );
                        assert.equal(
                            await page.evaluate(
                                () => __boardCalls.at(-1).query.page,
                            ),
                            2,
                        );
                        assert.equal(
                            await page
                                .getByRole('button', {
                                    name: 'Hide analysis for AWY3 at HOM3',
                                    exact: true,
                                })
                                .getAttribute('aria-expanded'),
                            'true',
                        );
                    }
                }
            }
            assert.deepEqual(errors, []);
            console.log(
                `${sport} ${mode} ${scenario}: visuals and controls ${before ? 'baseline' : 'passed'}`,
            );
        }
        await page.close();
    }
} catch (error) {
    console.error(error);
    throw error;
} finally {
    await browser?.close();
    await server.close();
}
