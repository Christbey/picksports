// Local fixture coverage. Set FORECAST_BASELINE_REF for exact comparisons with a release.
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
const baseline = process.env.FORECAST_BASELINE_REF;
const paths = {
    nfl: 'pages/NFL/Futures.vue',
    cbb: 'pages/CBB/TournamentForecast.vue',
    wcbb: 'pages/WCBB/TournamentForecast.vue',
};
const manifest = JSON.parse(
    readFileSync(root + 'public/build/manifest.json', 'utf8'),
);
const stylesheet = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-forecast-browser-vite',
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
                if (id.endsWith('/composables/useViewerAccess.ts'))
                    return `import {computed} from 'vue';export const useViewerAccess=()=>({isAdmin:computed(()=>false)});`;
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
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${stylesheet}">${q.get('before') === '1' ? '<style>[class~="min-w-[220px]"]{min-width:220px}[class~="min-w-[260px]"]{min-width:260px}</style>' : ''}</head><body><div id="app"></div><script type="module">
import {createApp,nextTick} from 'vue';import Page from '/resources/js/${path}';import {forecastRows} from '/tests/frontend/fixtures/forecastUi.mjs';
globalThis.__cleanupCalls=[];globalThis.__futuresCalls=[];globalThis.__futuresFail=${JSON.stringify(scenario)}==='failure';
globalThis.__futuresApi={forecasts:{index:async(sport,options)=>{__futuresCalls.push({sport,query:options.query});if(globalThis.__futuresFail)return null;return {data:${JSON.stringify(scenario)}==='empty'?[]:forecastRows(),meta:{available_seasons:[2026,2025],playoff_teams_per_conference:8,play_in_teams_per_conference:10,actual_field_size:${JSON.stringify(scenario)}==='empty'?0:2,mode:"live_snapshot",snapshot_as_of:"2026-03-20T00:00:00Z",simulations:1000,warnings:["Stored forecast fixture"]}};}}};
const app=createApp(Page,{});app.config.globalProperties.$page={props:{auth:{user:null}}};app.mount('#app');await nextTick();await new Promise(r=>setTimeout(r,0));globalThis.__cleanupReady=true;
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
    await page.evaluate(() => window.scrollTo(0, 0));
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

try {
    await server.listen();
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
        args: ['--disable-gpu', '--force-color-profile=srgb'],
    });
    const context = await browser.newContext({ timezoneId: 'America/Chicago' });
    for (const before of baseline ? [true, false] : [false])
        for (const kind of Object.keys(paths))
            for (const scenario of ['normal', 'failure', 'empty']) {
                const page = await context.newPage(),
                    errors = [];
                page.setDefaultTimeout(15000);
                page.on('pageerror', (e) => {
                    errors.push(e.message);
                    console.error(e.message);
                });
                await page.goto(
                    `http://127.0.0.1:${server.httpServer.address().port}/__cleanup?kind=${kind}&scenario=${scenario}&before=${before ? 1 : 0}`,
                );
                await page.waitForFunction(() => globalThis.__cleanupReady);
                for (const width of [320, 390, 1280]) {
                    await page.setViewportSize({ width, height: 900 });
                    for (const dark of [false, true]) {
                        await page.evaluate(
                            (d) =>
                                document.documentElement.classList.toggle(
                                    'dark',
                                    d,
                                ),
                            dark,
                        );
                        if (width === 1280 && scenario === 'normal')
                            await compare(
                                page,
                                `${kind}-${width}-${dark}`,
                                before,
                            );
                        if (!before) {
                            assert.equal(
                                await page.evaluate(
                                    () =>
                                        document.documentElement.scrollWidth <=
                                        innerWidth + 1,
                                ),
                                true,
                                `${kind} overflow at ${width}`,
                            );
                            for (const region of await page
                                .getByRole('region')
                                .filter({ has: page.locator('table') })
                                .all()) {
                                assert.equal(
                                    await region.getAttribute('tabindex'),
                                    '0',
                                );
                                assert.ok(
                                    await region.getAttribute('aria-label'),
                                );
                                if (
                                    await region.evaluate(
                                        (el) =>
                                            el.scrollWidth > el.clientWidth + 1,
                                    )
                                ) {
                                    await region.focus();
                                    await page.keyboard.press('ArrowRight');
                                    await page.waitForFunction(
                                        (el) => el.scrollLeft > 0,
                                        await region.elementHandle(),
                                    );
                                    await region.evaluate(
                                        (el) => (el.scrollLeft = 0),
                                    );
                                }
                            }
                            await page.evaluate(() =>
                                document.activeElement?.blur(),
                            );
                        }
                    }
                }
                if (!before && scenario === 'failure') {
                    assert.equal(await page.getByRole('alert').count(), 1);
                    assert.equal(await page.locator('table').count(), 0);
                    await page.evaluate(() => {
                        __futuresFail = false;
                    });
                    await page
                        .getByRole('button', { name: 'Try again', exact: true })
                        .click();
                    await page.locator('table').first().waitFor();
                    assert.equal(
                        await page
                            .getByRole('button', {
                                name: 'Try again',
                                exact: true,
                            })
                            .count(),
                        0,
                    );
                }
                if (!before && scenario === 'empty') {
                    assert.equal(
                        await page
                            .getByRole('button', {
                                name: 'Try again',
                                exact: true,
                            })
                            .count(),
                        0,
                    );
                    assert.equal(await page.locator('table').count(), 0);
                }
                if (!before && scenario === 'normal') {
                    const calls = await page.evaluate(
                        () => __futuresCalls.length,
                    );
                    await page.locator('#season').selectOption('2025');
                    await page.waitForFunction(
                        (n) => __futuresCalls.length === n + 1,
                        calls,
                    );
                    const sort = page.locator('#sort-mode');
                    if (await sort.count())
                        await sort.selectOption(
                            kind === 'nfl' ? 'edge' : 'edge',
                        );
                    const check = page.locator('input[type=checkbox]');
                    if (await check.count()) await check.check();
                    assert.equal(
                        await page.evaluate(() => __futuresCalls.length),
                        calls + 1,
                    );
                }
                assert.deepEqual(errors, []);
                console.log(
                    `${kind} ${scenario}: ${before ? 'baseline' : 'passed'}`,
                );
                await page.close();
            }
    console.log(`Exact desktop comparisons: ${comparisons}`);
} finally {
    await browser?.close();
    await server.close();
}
