// Local fixture coverage. Set FRONTEND_CLEANUP_BASELINE_REF for exact comparisons with a release.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const baseline = process.env.FRONTEND_CLEANUP_BASELINE_REF;
const paths = {
    nba: 'pages/NBA/Futures.vue',
    mlb: 'pages/MLB/Futures.vue',
    health: 'pages/Admin/Healthchecks.vue',
    monitor: 'pages/Admin/MlbModelMonitoring.vue',
    public: 'pages/PublicSport.vue',
    trends: 'components/game-page/TrendsComparisonCard.vue',
    box: 'components/game-page/BasketballBoxScoreCard.vue',
};
const manifest = JSON.parse(
    readFileSync(root + 'public/build/manifest.json', 'utf8'),
);
const stylesheet = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-cleanup-browser-vite',
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
            name: 'cleanup-browser-fixtures',
            resolveId(id) {
                if (id.includes('__CleanupBefore'))
                    return root + 'resources/js/' + id.split('/').at(-1);
                if (id.endsWith('/routes')) return 'virtual:cleanup-routes';
            },
            load(id) {
                const kind = id.match(/__CleanupBefore(\w+)\.vue$/)?.[1];
                if (kind)
                    return execFileSync(
                        'git',
                        ['show', `${baseline}:resources/js/${paths[kind]}`],
                        { cwd: root, encoding: 'utf8' },
                    );
                if (id.endsWith('/composables/useApiV2Client.ts'))
                    return 'export const useApiV2Client=()=>globalThis.__futuresApi;';
                if (id === 'virtual:cleanup-routes')
                    return `export const home=()=>({url:'/'});export const dashboard=()=>({url:'/dashboard'});export const login=()=>({url:'/login'});export const register=()=>({url:'/register'});`;
            },
            configureServer(vite) {
                vite.middlewares.use('/__cleanup', async (req, res) => {
                    const q = new URL(req.url, 'http://fixture').searchParams,
                        kind = q.get('kind'),
                        scenario = q.get('scenario');
                    const path =
                        q.get('before') === '1'
                            ? `__CleanupBefore${kind}.vue`
                            : paths[kind];
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            `/__cleanup/${path}/${scenario}`,
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${stylesheet}"></head><body><div id="app"></div><script type="module">
import {createApp,nextTick} from 'vue';import Page from '/resources/js/${path}';import {cleanupProps,futuresRows} from '/tests/frontend/fixtures/frontendCleanup.mjs';
globalThis.__cleanupCalls=[];globalThis.__futuresCalls=[];globalThis.__futuresFail=${JSON.stringify(scenario)}==='failure';
globalThis.__futuresApi={forecasts:{index:async(sport,options)=>{__futuresCalls.push({sport,query:options.query});if(globalThis.__futuresFail)return null;return {data:${JSON.stringify(scenario)}==='empty'?[]:futuresRows(),meta:{available_seasons:[2026,2025],playoff_teams_per_conference:8,play_in_teams_per_conference:10}};}}};
const app=createApp(Page,cleanupProps(${JSON.stringify(kind)},${JSON.stringify(scenario)}));app.config.globalProperties.$page={props:{auth:{user:null}}};app.mount('#app');await nextTick();await new Promise(r=>setTimeout(r,0));globalThis.__cleanupReady=true;
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
                    root + 'tests/frontend/fixtures/frontendCleanupInertia.mjs',
            },
            {
                find: '@/layouts/AppLayout.vue',
                replacement: root + 'tests/frontend/fixtures/adminLayout.mjs',
            },
            {
                find: '@/layouts/settings/Layout.vue',
                replacement: root + 'tests/frontend/fixtures/adminLayout.mjs',
            },
            {
                find: '@/components/predictions/PredictionsPageShell.vue',
                replacement: root + 'tests/frontend/fixtures/adminLayout.mjs',
            },
            { find: '@', replacement: root + 'resources/js' },
        ],
    },
    server: { host: '127.0.0.1', port: 0, hmr: false, ws: false, watch: null },
});
let browser;
const images = new Map();
let comparisons = 0;
let lastScreenshot;
async function imageHash(page) {
    await page.mouse.move(0, 0);
    await page.addStyleTag({
        content:
            '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}',
    });
    await page.evaluate(() => document.fonts.ready);
    let previous;
    for (let i = 0; i < 5; i++) {
        await page.evaluate(
            () =>
                new Promise((r) =>
                    requestAnimationFrame(() => requestAnimationFrame(r)),
                ),
        );
        lastScreenshot = await page.screenshot({
            animations: 'disabled',
            fullPage: true,
        });
        const hash = createHash('sha256').update(lastScreenshot).digest('hex');
        if (hash === previous) return hash;
        previous = hash;
    }
    throw new Error('Screenshot did not stabilize');
}
async function compare(page, key, before) {
    const hash = await imageHash(page);
    if (before)
        images.set(key, {
            hash,
            png: lastScreenshot,
            html: await page.locator('#app').innerHTML(),
        });
    else if (baseline) {
        if (hash !== images.get(key).hash) {
            writeFileSync(
                '/tmp/frontend-cleanup-before.png',
                images.get(key).png,
            );
            writeFileSync('/tmp/frontend-cleanup-after.png', lastScreenshot);
            writeFileSync(
                '/tmp/frontend-cleanup-before.html',
                images.get(key).html,
            );
            writeFileSync(
                '/tmp/frontend-cleanup-after.html',
                await page.locator('#app').innerHTML(),
            );
        }
        assert.equal(
            hash,
            images.get(key).hash,
            `UI changed: ${key}; screenshots saved in /tmp/frontend-cleanup-{before,after}.png`,
        );
        comparisons++;
    }
}
const cases = [
    ['nba', 'normal'],
    ['nba', 'failure'],
    ['mlb', 'normal'],
    ['mlb', 'empty'],
    ['health', 'normal'],
    ['health', 'validation'],
    ['health', 'empty'],
    ['monitor', 'normal'],
    ['monitor', 'empty'],
    ['public', 'normal'],
    ['public', 'empty'],
    ['trends', 'normal'],
    ['trends', 'empty'],
    ['box', 'grid'],
    ['box', 'table'],
    ['box', 'gridzero'],
    ['box', 'tablezero'],
];
try {
    await server.listen();
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
        args: ['--disable-gpu', '--force-color-profile=srgb'],
    });
    const context = await browser.newContext({ timezoneId: 'America/Chicago' });
    for (const before of baseline ? [true, false] : [false])
        for (const [kind, scenario] of cases.filter(
            ([kind]) =>
                !process.env.FRONTEND_CLEANUP_KINDS ||
                process.env.FRONTEND_CLEANUP_KINDS.split(',').includes(kind),
        )) {
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(
                `http://127.0.0.1:${server.httpServer.address().port}/__cleanup?kind=${kind}&scenario=${scenario}&before=${before ? 1 : 0}`,
            );
            await page.waitForFunction(() => globalThis.__cleanupReady);
            assert.deepEqual(errors, []);
            for (const width of [320, 390, 1280]) {
                await page.setViewportSize({ width, height: 900 });
                if (
                    !before &&
                    (kind === 'nba' || kind === 'mlb' || kind === 'box')
                ) {
                    assert.equal(
                        await page.evaluate(
                            () =>
                                document.documentElement.scrollWidth <=
                                window.innerWidth + 1,
                        ),
                        true,
                        `Page overflow at ${width}px`,
                    );
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
                        (d) =>
                            document.documentElement.classList.toggle(
                                'dark',
                                d,
                            ),
                        dark,
                    );
                    await compare(
                        page,
                        `${kind}-${scenario}-${width}-${dark}`,
                        before,
                    );
                }
            }
            if (kind === 'monitor') {
                for (const tab of [
                    'Weekly',
                    'Markets',
                    'Failures',
                    'Lineage',
                ]) {
                    await page
                        .getByRole('tab', { name: tab, exact: true })
                        .click();
                    for (const dark of [false, true]) {
                        await page.evaluate(
                            (d) =>
                                document.documentElement.classList.toggle(
                                    'dark',
                                    d,
                                ),
                            dark,
                        );
                        await compare(
                            page,
                            `${kind}-${scenario}-${tab}-${dark}`,
                            before,
                        );
                    }
                }
                await page
                    .locator('select')
                    .selectOption(scenario === 'empty' ? '' : 'artifact-1');
                assert.deepEqual(
                    await page.evaluate(() => __cleanupCalls.at(-1).args),
                    [
                        '/admin/mlb-model-monitoring',
                        scenario === 'empty' ? {} : { artifact: 'artifact-1' },
                        { preserveScroll: true, preserveState: true },
                    ],
                );
            }
            if (kind === 'trends' && scenario === 'normal') {
                await page
                    .locator('details')
                    .first()
                    .locator('summary')
                    .first()
                    .focus();
                await page.keyboard.press('Enter');
                for (const dark of [false, true]) {
                    await page.evaluate(
                        (d) =>
                            document.documentElement.classList.toggle(
                                'dark',
                                d,
                            ),
                        dark,
                    );
                    await compare(page, `trends-details-${dark}`, before);
                }
                assert.equal(
                    await page.locator('details').first().getAttribute('open'),
                    '',
                );
            }
            if ((kind === 'nba' || kind === 'mlb') && scenario === 'normal') {
                assert.equal(
                    await page.evaluate(() => __futuresCalls.length),
                    1,
                );
                await page.locator('#season').selectOption('2025');
                await page.waitForFunction(() => __futuresCalls.length === 2);
                await page.locator('#sort-mode').selectOption('edge');
                await page.locator('input[type=checkbox]').check();
                assert.equal(
                    await page.evaluate(() => __futuresCalls.length),
                    2,
                );
                assert.equal(
                    await page.evaluate(
                        () => __futuresCalls.at(-1).query.season,
                    ),
                    2025,
                );
            }
            if (!before && kind === 'nba' && scenario === 'failure') {
                assert.equal(await page.locator('table').count(), 0);
                await page.evaluate(() => {
                    __futuresFail = false;
                });
                await page
                    .getByRole('button', { name: 'Try again', exact: true })
                    .click();
                await page.locator('table').first().waitFor();
                assert.equal(await page.getByRole('alert').count(), 0);
                assert.equal(
                    await page.evaluate(() => __futuresCalls.length),
                    2,
                );
            }
            if (kind === 'health' && scenario === 'normal') {
                await page.locator('select').selectOption('nfl');
                await page
                    .getByRole('button', {
                        name: 'Data Validation',
                        exact: true,
                    })
                    .click();
                assert.deepEqual(
                    await page.evaluate(() => __cleanupCalls.at(-1).args[1]),
                    { sport: 'nfl', view: 'validation', validation_run: null },
                );
                await page
                    .getByRole('button', {
                        name: 'Run Validation Checks',
                        exact: true,
                    })
                    .click();
                assert.deepEqual(
                    await page.evaluate(() => __cleanupCalls.at(-1).data),
                    { sport: 'nfl', mode: 'validation' },
                );
                assert.equal(
                    await page
                        .getByRole('button', {
                            name: 'Running Checks...',
                            exact: true,
                        })
                        .isDisabled(),
                    true,
                );
                await page.evaluate(() => __cleanupFinish());
                await page
                    .getByRole('button', { name: 'Run Sync', exact: true })
                    .click();
                assert.deepEqual(
                    await page.evaluate(() => __cleanupCalls.at(-1).data),
                    { sport: 'nfl', check_type: 'heartbeat_sync' },
                );
            }
            assert.deepEqual(errors, []);
            console.log(
                `${kind} ${scenario}: ${before ? 'baseline' : 'visuals and controls passed'}`,
            );
            await page.close();
        }
    console.log(`Exact screenshot comparisons: ${comparisons}`);
    await context.close();
} finally {
    await browser?.close();
    await server.close();
}
