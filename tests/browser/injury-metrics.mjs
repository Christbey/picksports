// Local fixture coverage. Set INJURY_METRICS_BASELINE_REF for exact comparisons with a release.
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
const baseline = process.env.INJURY_METRICS_BASELINE_REF;
const paths = {
    injuries: 'components/SportInjuries.vue',
    metrics: 'components/SportTeamMetrics.vue',
};
const manifest = JSON.parse(
    readFileSync(root + 'public/build/manifest.json', 'utf8'),
);
const stylesheet = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-injury-metrics-browser-vite',
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
                if (id.endsWith('/components/SubscriptionBanner.vue'))
                    return '<template><div/></template>';
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
                        sport = q.get('sport') ?? 'nfl',
                        scenario = q.get('scenario');
                    const path =
                        q.get('before') === '1'
                            ? `__CleanupBefore${kind}.vue`
                            : paths[kind];
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            '/__cleanup' + req.url,
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${stylesheet}">${q.get('before') === '1' ? '<style>[class~="min-w-[220px]"]{min-width:220px}[class~="min-w-[260px]"]{min-width:260px}</style>' : ''}</head><body><div id="app"></div><script type="module">
import {createApp,nextTick} from 'vue';import Page from '/resources/js/${path}';import {injuryConfig,metricsConfig,injuryRows,metricRows} from '/tests/frontend/fixtures/injuryMetricsUi.mjs';
globalThis.__cleanupCalls=[];globalThis.__boardCalls=[];globalThis.__boardFail=${JSON.stringify(scenario)}==='failure';globalThis.__seasonFail=${JSON.stringify(scenario)}==='seasons';
const injured=injuryRows();if(${JSON.stringify(scenario)}==='long'){injured[0].player_name='Long'+ 'name'.repeat(40);injured[0].detail='unbroken'.repeat(80);}
globalThis.__futuresApi={injuries:{index:async(sport,options)=>{__boardCalls.push({kind:'injuries',sport,query:options.query});if(__boardFail)return null;return {data:${JSON.stringify(scenario)}==='empty'?[]:injured,meta:{freshness:{last_observed_at:'2026-11-06T10:00:00Z',is_stale:${JSON.stringify(scenario)}==='stale'},warnings:${JSON.stringify(scenario)}==='warning'?['Partial coverage warning']:[]}};}},metrics:{teamAvailableSeasons:async(sport,options)=>{__boardCalls.push({kind:'seasons',sport});return __seasonFail?null:{data:[2026,2025]};},teams:async(sport,options)=>{__boardCalls.push({kind:'metrics',sport,query:options.query});return __boardFail?null:{data:${JSON.stringify(scenario)}==='empty'?[]:metricRows(),meta:{tier:{limit:2,name:'free'}}};}}};
const config=${JSON.stringify(kind)}==='injuries'?injuryConfig(${JSON.stringify(sport)}):metricsConfig(${JSON.stringify(sport)});
const app=createApp(Page,{config});app.config.globalProperties.$page={props:{auth:{user:null}}};app.mount('#app');await nextTick();await new Promise(r=>setTimeout(r,0));globalThis.__cleanupReady=true;
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
        args: [
            '--disable-gpu',
            '--force-color-profile=srgb',
            '--disable-smooth-scrolling',
        ],
    });
    const context = await browser.newContext({ timezoneId: 'America/Chicago' });
    for (const before of baseline ? [true, false] : [false])
        for (const kind of ['injuries', 'metrics'])
            for (const sport of ['nfl', 'nba'])
                for (const scenario of kind === 'injuries'
                    ? ['normal', 'failure', 'empty', 'warning', 'stale', 'long']
                    : ['normal', 'failure', 'empty', 'seasons']) {
                    if (
                        process.env.INJURY_METRICS_CASE &&
                        `${kind}-${sport}-${scenario}` !==
                            process.env.INJURY_METRICS_CASE
                    )
                        continue;
                    const page = await context.newPage(),
                        errors = [];
                    page.setDefaultTimeout(15000);
                    page.on('pageerror', (e) => errors.push(e.message));
                    page.on('console', (message) => {
                        if (/Failed to resolve component/.test(message.text()))
                            errors.push(message.text());
                    });
                    await page.goto(
                        `http://127.0.0.1:${server.httpServer.address().port}/__cleanup/${kind}-${sport}-${scenario}-${before ? 1 : 0}?kind=${kind}&sport=${sport}&scenario=${scenario}&before=${before ? 1 : 0}`,
                    );
                    await page.waitForFunction(() => globalThis.__cleanupReady);
                    await page
                        .getByRole('heading', {
                            name:
                                sport.toUpperCase() +
                                (kind === 'injuries'
                                    ? ' Injuries'
                                    : ' Team Metrics'),
                            exact: true,
                        })
                        .waitFor();
                    for (const width of [1280, 320, 390]) {
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
                                    `${kind}-${sport}-${dark}`,
                                    before,
                                );
                            if (!before) {
                                assert.equal(
                                    await page.evaluate(
                                        () =>
                                            document.documentElement
                                                .scrollWidth <=
                                            innerWidth + 1,
                                    ),
                                    true,
                                    `${kind}/${sport}/${scenario} overflow at ${width}`,
                                );
                                for (const region of await page
                                    .getByRole('region')
                                    .filter({ has: page.locator('table') })
                                    .all()) {
                                    assert.ok(
                                        await region.getAttribute('aria-label'),
                                    );
                                    assert.equal(
                                        await region.getAttribute('tabindex'),
                                        '0',
                                    );
                                    if (
                                        await region.evaluate(
                                            (el) =>
                                                el.scrollWidth >
                                                el.clientWidth + 1,
                                        )
                                    ) {
                                        await region.focus();
                                        await page.keyboard.press('ArrowRight');
                                        await page.waitForFunction(
                                            (el) => el.scrollLeft > 0,
                                            await region.elementHandle(),
                                        );
                                        await region.evaluate((el) =>
                                            el.scrollTo({
                                                left: 0,
                                                behavior: 'instant',
                                            }),
                                        );
                                    }
                                }
                                if (width < 640)
                                    for (const control of await page
                                        .locator('input,select,button')
                                        .all()) {
                                        const rect =
                                            await control.boundingBox();
                                        if (rect && (await control.isVisible()))
                                            assert.ok(
                                                rect.height >= 44,
                                                `${kind} control height ${rect.height}`,
                                            );
                                    }
                                await page.evaluate(() =>
                                    document.activeElement?.blur(),
                                );
                            }
                        }
                    }
                    if (!before) {
                        if (
                            kind === 'injuries' &&
                            (scenario === 'normal' ||
                                scenario === 'warning' ||
                                scenario === 'stale')
                        ) {
                            await page
                                .getByText('Test Player', { exact: true })
                                .waitFor();
                            if (scenario === 'warning')
                                await page
                                    .getByText('Partial coverage warning', {
                                        exact: true,
                                    })
                                    .waitFor();
                            if (scenario === 'stale')
                                await page
                                    .getByText(/Injury data is stale/)
                                    .waitFor();
                            const count = await page.evaluate(
                                () => __boardCalls.length,
                            );
                            await page
                                .getByRole('button', {
                                    name: 'Out',
                                    exact: true,
                                })
                                .click();
                            assert.equal(
                                await page
                                    .getByText('Questionable Player', {
                                        exact: true,
                                    })
                                    .count(),
                                0,
                            );
                            assert.equal(
                                await page.evaluate(() => __boardCalls.length),
                                count,
                            );
                        }
                        if (scenario === 'failure') {
                            assert.equal(
                                await page.getByRole('alert').count(),
                                1,
                            );
                            assert.equal(
                                await page
                                    .getByText(
                                        'No actionable injuries found.',
                                        { exact: true },
                                    )
                                    .count(),
                                0,
                            );
                            if (kind === 'injuries') {
                                await page
                                    .getByLabel('Search injuries')
                                    .fill('Test');
                                await page
                                    .getByRole('button', {
                                        name: 'Out',
                                        exact: true,
                                    })
                                    .click();
                            } else
                                await page
                                    .getByLabel('Search teams')
                                    .fill('Home');
                            await page.evaluate(() => {
                                __boardFail = false;
                            });
                            await page
                                .getByRole('button', {
                                    name: 'Try again',
                                    exact: true,
                                })
                                .click();
                            await (
                                kind === 'injuries'
                                    ? page.getByText('Test Player', {
                                          exact: true,
                                      })
                                    : page.locator(
                                          `a[href="/${sport}/teams/1"]`,
                                      )
                            )
                                .first()
                                .waitFor()
                                .catch(async (e) => {
                                    console.error(
                                        await page.locator('#app').innerText(),
                                    );
                                    console.error(errors);
                                    throw e;
                                });
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
                        if (scenario === 'seasons') {
                            await page.evaluate(() => {
                                __seasonFail = false;
                            });
                            await page
                                .getByRole('button', {
                                    name: 'Try again',
                                    exact: true,
                                })
                                .click();
                            await page.waitForFunction(
                                () =>
                                    document.querySelector('#metrics-season')
                                        ?.value === '2026',
                            );
                            assert.equal(
                                await page.evaluate(
                                    () =>
                                        __boardCalls
                                            .filter((c) => c.kind === 'metrics')
                                            .at(-1).query.season,
                                ),
                                '2026',
                            );
                        }
                        if (kind === 'metrics' && scenario === 'normal') {
                            const count = await page.evaluate(
                                () => __boardCalls.length,
                            );
                            await page.getByLabel('Search teams').fill('Home');
                            assert.equal(
                                await page.evaluate(() => __boardCalls.length),
                                count,
                            );
                            await page.getByLabel('Search teams').clear();
                            if (sport === 'nfl') {
                                const toggle = page
                                    .locator('button[aria-expanded]')
                                    .first();
                                await toggle.focus();
                                await page.keyboard.press('Enter');
                                assert.equal(
                                    await toggle.getAttribute('aria-expanded'),
                                    'true',
                                );
                                await page.keyboard.press('Space');
                                assert.equal(
                                    await toggle.getAttribute('aria-expanded'),
                                    'false',
                                );
                            }
                            await page
                                .locator('#metrics-season')
                                .selectOption('2025');
                            await page.waitForFunction(
                                () =>
                                    __boardCalls
                                        .filter((c) => c.kind === 'metrics')
                                        .at(-1).query.season === '2025',
                            );
                            await page
                                .locator('#metrics-season-type')
                                .selectOption('3');
                            await page.waitForFunction(
                                () =>
                                    __boardCalls
                                        .filter((c) => c.kind === 'metrics')
                                        .at(-1).query.season_type === '3',
                            );
                        }
                        assert.equal(
                            await page
                                .getByText('Team Metrics Render Error', {
                                    exact: true,
                                })
                                .count(),
                            0,
                        );
                        assert.deepEqual(errors, []);
                    }
                    console.log(
                        `${kind} ${sport} ${scenario}: ${before ? 'baseline' : 'passed'}`,
                    );
                    await page.close();
                }
    console.log(`Exact desktop comparisons: ${comparisons}`);
} finally {
    await browser?.close();
    await server.close();
}
