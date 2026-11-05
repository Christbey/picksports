// Run npm run build first. Synthetic fixtures only; PLAYER_BASELINE_REF enables exact visual comparisons.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const baseline = process.env.PLAYER_BASELINE_REF;
const sources = baseline
    ? Object.fromEntries(
          [
              'player-page/SportPlayerShell',
              'player-stats/SportPlayerStatsShell',
          ].map((path) => [
              path.split('/').at(-1),
              execFileSync(
                  'git',
                  ['show', `${baseline}:resources/js/components/${path}.vue`],
                  { cwd: root, encoding: 'utf8' },
              ),
          ]),
      )
    : {};
const manifest = JSON.parse(
    readFileSync(`${root}public/build/manifest.json`, 'utf8'),
);
const stylesheet = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-player-browser-vite',
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
            name: 'player-shell-fixtures',
            resolveId(id) {
                if (id.includes('__BeforePlayer'))
                    return `${root}resources/js/components/${id.split('/').at(-1)}`;
            },
            load(id) {
                const name = id.match(/__BeforePlayer(\w+)\.vue$/)?.[1];
                if (name)
                    return sources[name].replaceAll(
                        "from './",
                        "from '@/components/" +
                            (name === 'SportPlayerShell'
                                ? 'player-page'
                                : 'player-stats') +
                            '/',
                    );
            },
            configureServer(vite) {
                vite.middlewares.use(
                    '/__player-shell',
                    async (request, response) => {
                        const query = new URL(request.url, 'http://fixture')
                            .searchParams;
                        const stats = query.get('kind') === 'stats';
                        const sport = query.get('sport') ?? 'nba';
                        const name = stats
                            ? 'SportPlayerStatsShell'
                            : 'SportPlayerShell';
                        const source =
                            query.get('before') === '1'
                                ? `/__BeforePlayer${name}.vue`
                                : `/${stats ? 'player-stats' : 'player-page'}/${name}.vue`;
                        response.setHeader('Content-Type', 'text/html');
                        response.end(
                            await vite.transformIndexHtml(
                                '/__player-shell' + request.url,
                                `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${stylesheet}"></head><body><div id="app"></div><script type="module">
import {createApp,h} from 'vue';
import Shell from '/resources/js/components${source}';
import {playerUi} from '/tests/frontend/fixtures/playerUi.mjs';
const fixture=playerUi(${JSON.stringify(sport)});globalThis.__playerUiApi=fixture.api;globalThis.__playerUiCalls=fixture.calls;
globalThis.__playerUiFailures={primary:${JSON.stringify(query.get('failure'))}==='primary',supplemental:${JSON.stringify(query.get('failure'))}==='supplemental',stats:${JSON.stringify(query.get('failure'))}==='stats'};
for(const [group,key,kind] of [['players','show','primary'],['stats','players','primary'],['players','playerProps','supplemental'],['leaderboards','players',${stats ? '"stats"' : '"supplemental"'}]]){const original=fixture.api[group][key];fixture.api[group][key]=(...args)=>__playerUiFailures[kind]?Promise.resolve(null):original(...args);}

window.requestIdleCallback=callback=>{queueMicrotask(()=>callback({didTimeout:false,timeRemaining:()=>50}));return 7;};
createApp({setup(){return()=>h(Shell,${stats ? 'fixture.statsProps' : 'fixture.playerProps'},{afterHeader:({player})=>h('p',{'data-testid':'player-details'},'Details for '+player.name)});}}).mount('#app');
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
                find: '@/layouts/AppLayout.vue',
                replacement: `${root}tests/frontend/fixtures/adminLayout.mjs`,
            },
            {
                find: '@/composables/useApiV2Client',
                replacement: `${root}tests/frontend/fixtures/playerUiApi.mjs`,
            },
            { find: '@', replacement: `${root}resources/js` },
        ],
    },
    server: { host: '127.0.0.1', port: 0, hmr: false, ws: false, watch: null },
});
let browser;
const images = new Map();
let lastPng;
async function screenshot(page) {
    for (const region of await page
        .getByRole('region')
        .filter({ has: page.locator('table') })
        .all()) {
        await region.scrollIntoViewIfNeeded();
        await region.evaluate((el) =>
            el.scrollTo({ left: 0, behavior: 'instant' }),
        );
    }
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await page.mouse.move(0, 0);
    await page.addStyleTag({
        content:
            '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}',
    });
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ animations: 'disabled', fullPage: true });
    let previous;
    for (let n = 0; n < 5; n++) {
        await page.evaluate(
            () =>
                new Promise((resolve) =>
                    requestAnimationFrame(() => requestAnimationFrame(resolve)),
                ),
        );
        const png = await page.screenshot({
            animations: 'disabled',
            fullPage: true,
        });
        lastPng = png;
        const hash = await page.evaluate(async (base64) => {
            const picture = new Image();
            picture.src = 'data:image/png;base64,' + base64;
            await picture.decode();
            const canvas = document.createElement('canvas');
            canvas.width = picture.width;
            canvas.height = picture.height;
            const context = canvas.getContext('2d');
            context.drawImage(picture, 0, 0);
            const pixels = context.getImageData(
                0,
                0,
                canvas.width,
                canvas.height,
            ).data;
            return Array.from(
                new Uint8Array(await crypto.subtle.digest('SHA-256', pixels)),
            )
                .map((b) => b.toString(16).padStart(2, '0'))
                .join('');
        }, png.toString('base64'));
        if (hash === previous) return hash;
        previous = hash;
    }
    throw new Error('Screenshot did not stabilize');
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
        for (const kind of ['player', 'stats'])
            for (const sport of ['nba', 'nfl']) {
                await page.goto(
                    `http://127.0.0.1:${server.httpServer.address().port}/__player-shell/${kind}-${sport}-${before}?kind=${kind}&sport=${sport}&before=${before ? 1 : 0}`,
                    { waitUntil: 'domcontentloaded' },
                );
                if (kind === 'player') {
                    await page
                        .getByText('Upcoming Props', { exact: true })
                        .waitFor();
                    assert.equal(
                        await page.getByTestId('player-details').textContent(),
                        'Details for Test Player',
                    );
                    await page
                        .getByText('Season Averages (2 games)', { exact: true })
                        .waitFor();
                } else {
                    await page.locator('tbody tr').first().waitFor();
                    assert.equal(await page.locator('tbody tr').count(), 100);
                }
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
                                await region.evaluate(
                                    (el) => (el.scrollLeft = 0),
                                );
                            }
                        }
                        await page.evaluate(() =>
                            document.activeElement?.blur(),
                        );
                    }

                    await page.evaluate(() => window.scrollTo(0, 0));
                    for (const dark of [false, true]) {
                        await page.evaluate(
                            (value) =>
                                document.documentElement.classList.toggle(
                                    'dark',
                                    value,
                                ),
                            dark,
                        );
                        const key = `${kind}-${sport}-${width}-${dark}`;
                        const image = await screenshot(page);
                        if (before) {
                            images.set(key, image);
                            writeFileSync(
                                '/tmp/player-baseline-' + key + '.png',
                                lastPng,
                            );
                            writeFileSync(
                                '/tmp/player-baseline-' + key + '.html',
                                await page.locator('#app').innerHTML(),
                            );
                        } else if (baseline) {
                            if (image !== images.get(key)) {
                                writeFileSync('/tmp/player-after.png', lastPng);
                                writeFileSync(
                                    '/tmp/player-after.html',
                                    await page.locator('#app').innerHTML(),
                                );
                            }
                            assert.equal(
                                image,
                                images.get(key),
                                `Layout changed: ${key}`,
                            );
                        }
                    }
                }
                if (kind === 'stats') {
                    await page
                        .getByRole('button', {
                            name: 'Show more players',
                            exact: true,
                        })
                        .click();
                    assert.equal(
                        await page.locator('tbody tr').count(),
                        sport === 'nfl' ? 105 : 110,
                    );
                    await page
                        .getByPlaceholder('Search by player or team name...')
                        .fill('Player 104');
                    await page.waitForFunction(
                        () =>
                            document.querySelectorAll('tbody tr').length === 1,
                    );
                    assert.match(
                        await page.locator('tbody').innerText(),
                        /Player 104/,
                    );
                    await page
                        .getByPlaceholder('Search by player or team name...')
                        .fill('');
                    await page.waitForFunction(
                        () =>
                            document.querySelectorAll('tbody tr').length ===
                            100,
                    );
                    await page
                        .locator('#player-stats-season')
                        .selectOption('2025');
                    await page.waitForFunction(
                        () =>
                            globalThis.__playerUiCalls.at(-1)?.query.season ===
                            '2025',
                    );
                    if (sport === 'nfl') {
                        await page
                            .getByRole('button', {
                                name: 'Rushing',
                                exact: true,
                            })
                            .click();
                        await page.waitForFunction(
                            () =>
                                document.querySelectorAll('tbody tr').length ===
                                5,
                        );
                    }
                }
                assert.deepEqual(errors, []);
                console.log(
                    `${kind} ${sport}: layouts, data and controls ${before ? 'baseline' : 'passed'}`,
                );
            }
        if (!before)
            for (const failure of ['primary', 'supplemental', 'stats']) {
                const kind = failure === 'stats' ? 'stats' : 'player';
                await page.goto(
                    `http://127.0.0.1:${server.httpServer.address().port}/__player-shell?kind=${kind}&sport=nba&failure=${failure}`,
                );
                await page
                    .getByRole('button', { name: 'Try again', exact: true })
                    .waitFor();
                if (failure === 'primary' || failure === 'stats')
                    assert.equal(await page.locator('table').count(), 0);
                await page.evaluate(() => {
                    Object.keys(__playerUiFailures).forEach(
                        (k) => (__playerUiFailures[k] = false),
                    );
                });
                await page
                    .getByRole('button', { name: 'Try again', exact: true })
                    .click();
                if (kind === 'stats')
                    await page.locator('tbody tr').first().waitFor();
                else
                    await page
                        .getByText('Season Averages (2 games)', { exact: true })
                        .waitFor();
                await page.waitForFunction(
                    () => !document.body.innerText.includes('Try again'),
                );
                if (failure === 'supplemental')
                    await page
                        .getByText('Upcoming Props', { exact: true })
                        .waitFor();
                console.log(`${kind} ${failure}: retry recovered`);
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
