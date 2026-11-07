// Local-only fixtures; compare with TEAM_NFL_BASELINE_REF to verify the shipped UI.
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
const root = fileURLToPath(new URL('../../', import.meta.url)),
    baseline = process.env.TEAM_NFL_BASELINE_REF;
const paths = {
    team: 'components/SportTeam.vue',
    board: 'components/nfl/NflMatchupBoard.vue',
};
const manifest = JSON.parse(
        readFileSync(root + 'public/build/manifest.json', 'utf8'),
    ),
    css = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-team-nfl-browser-vite',
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
            name: 'team-nfl-browser',
            enforce: 'pre',
            resolveId(id) {
                if (id.includes('__BoardBefore'))
                    return root + 'resources/js/' + id.split('/').at(-1);
                if (id.includes('__TeamBeforeData'))
                    return (
                        root + 'resources/js/composables/__TeamBeforeData.ts'
                    );
                if (id.endsWith('/routes')) return 'virtual:board-routes';
            },
            load(id) {
                const kind = id.match(/__BoardBefore(\w+)\.vue$/)?.[1];
                if (kind) {
                    let source = execFileSync(
                        'git',
                        ['show', `${baseline}:resources/js/${paths[kind]}`],
                        { cwd: root, encoding: 'utf8' },
                    );
                    if (kind === 'team')
                        source = source.replace(
                            '@/composables/useSportTeamData',
                            '@/composables/__TeamBeforeData',
                        );
                    return source;
                }
                if (id.includes('__TeamBeforeData'))
                    return execFileSync(
                        'git',
                        [
                            'show',
                            `${baseline}:resources/js/composables/useSportTeamData.ts`,
                        ],
                        { cwd: root, encoding: 'utf8' },
                    );
                if (id.endsWith('/composables/useApiV2Client.ts'))
                    return 'export const useApiV2Client=()=>globalThis.__boardCleanupApi;';
                if (id === 'virtual:board-routes')
                    return "export const home=()=>({url:'/'});export const dashboard=()=>({url:'/dashboard'});export const login=()=>({url:'/login'});export const register=()=>({url:'/register'});";
            },
            configureServer(vite) {
                vite.middlewares.use('/__boards', async (req, res) => {
                    const q = new URL(req.url, 'http://fixture').searchParams,
                        kind = q.get('kind'),
                        sport = q.get('sport') || 'nfl',
                        failure = q.get('failure') || '',
                        empty = q.get('empty') === '1',
                        before = q.get('before') === '1';
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            '/__boards' + req.url,
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${css}"></head><body><div id="app"></div><script type="module">import {createApp,nextTick} from 'vue';import Page from '/resources/js/${before ? '__BoardBefore' + kind + '.vue' : paths[kind]}';import {installTeamNflApi} from '/tests/frontend/fixtures/teamNflRecovery.mjs';import * as configs from '/resources/js/config/team-page-configs.ts';installTeamNflApi(${empty},${JSON.stringify(failure)});const sport=${JSON.stringify(sport)},kind=${JSON.stringify(kind)};const factory=configs['create'+sport[0].toUpperCase()+sport.slice(1)+'TeamConfig'];createApp(Page,kind==='team'?{teamId:1,config:factory(id=>'/'+sport+'/games/'+id)}:{}).mount('#app');await nextTick();globalThis.__boardReady=true;</script></body></html>`,
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
                    root + 'tests/frontend/fixtures/teamNflInertia.mjs',
            },
            {
                find: '@/layouts/AppLayout.vue',
                replacement: root + 'tests/frontend/fixtures/adminLayout.mjs',
            },
            { find: '@', replacement: root + 'resources/js' },
        ],
    },
    server: {
        host: '127.0.0.1',
        port: 0,
        watch: { ignored: ['**/frontend/dist/**', '**/public/build/**'] },
    },
});
await server.listen();
let browser;
const shots = new Map();
let comparisons = 0;
async function stable(page, drawer = false) {
    await page.evaluate(() => document.fonts.ready);
    await page.mouse.move(0, 0);
    await page.addStyleTag({
        content:
            '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}',
    });
    let prev, shot;
    for (let i = 0; i < 8; i++) {
        shot = drawer
            ? await page
                  .getByRole('dialog')
                  .screenshot({ animations: 'disabled' })
            : await page.screenshot({ fullPage: true, animations: 'disabled' });
        const hash = createHash('sha256').update(shot).digest('hex');
        if (hash === prev) return shot;
        prev = hash;
    }
    return shot;
}
async function pixelHash(page, shot) {
    return page.evaluate(async (base64) => {
        const img = new Image();
        img.src = 'data:image/png;base64,' + base64;
        await img.decode();
        const canvas = document.createElement('canvas');
        canvas.width = img.width;
        canvas.height = img.height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0);
        return Array.from(
            new Uint8Array(
                await crypto.subtle.digest(
                    'SHA-256',
                    ctx.getImageData(0, 0, img.width, img.height).data,
                ),
            ),
        ).join(',');
    }, shot.toString('base64'));
}
async function pixelDifference(page, before, after) {
    return page.evaluate(
        async (urls) => {
            const images = [];
            for (const url of urls) {
                const image = new Image();
                image.src = url;
                await image.decode();
                const canvas = document.createElement('canvas');
                canvas.width = image.width;
                canvas.height = image.height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(image, 0, 0);
                images.push({
                    width: image.width,
                    height: image.height,
                    data: ctx.getImageData(0, 0, image.width, image.height)
                        .data,
                });
            }
            const [a, b] = images;
            if (a.width !== b.width || a.height !== b.height)
                return { matched: false };
            let count = 0,
                maxDelta = 0;
            for (let i = 0; i < a.data.length; i += 4) {
                let delta = 0;
                for (let c = 0; c < 4; c++)
                    delta = Math.max(
                        delta,
                        Math.abs(a.data[i + c] - b.data[i + c]),
                    );
                if (delta) {
                    count++;
                    maxDelta = Math.max(maxDelta, delta);
                }
            }
            return {
                matched:
                    maxDelta <= 1 &&
                    count <=
                        Math.min(100, Math.floor((a.width * a.height) / 10000)),
                count,
                maxDelta,
            };
        },
        [before, after].map(
            (shot) => 'data:image/png;base64,' + shot.toString('base64'),
        ),
    );
}
async function compare(page, key, before) {
    const shot = await stable(page, key.endsWith('-drawer')),
        hash = await pixelHash(page, shot);
    if (before) shots.set(key, { shot, hash });
    else if (baseline) {
        if (hash !== shots.get(key).hash) {
            // Chrome occasionally rounds a few translucent border pixels by one channel value.
            const difference = await pixelDifference(
                page,
                shots.get(key).shot,
                shot,
            );
            if (!difference.matched) {
                writeFileSync('/tmp/team-nfl-before.png', shots.get(key).shot);
                writeFileSync('/tmp/team-nfl-after.png', shot);
            }
            assert.equal(
                difference.matched,
                true,
                'UI changed: ' + key + ' ' + JSON.stringify(difference),
            );
            console.log(
                key +
                    ' border rounding: ' +
                    difference.count +
                    ' pixels, max channel delta ' +
                    difference.maxDelta,
            );
        }
        comparisons++;
    }
}

try {
    browser = await chromium.launch({
        channel: process.env.PLAYWRIGHT_CHANNEL || undefined,
        headless: true,
        args: [
            '--disable-gpu',
            '--force-color-profile=srgb',
            '--disable-smooth-scrolling',
        ],
    });
    for (const width of [1280, 390])
        for (const theme of ['light', 'dark'])
            for (const [kind, sport, empty] of [
                ...['nba', 'wnba', 'cbb', 'wcbb', 'mlb', 'nfl'].map((sport) => [
                    'team',
                    sport,
                    false,
                ]),
                ['team', 'nba', true],
                ['board', 'nfl', false],
                ['board', 'nfl', true],
            ])
                for (const before of baseline ? [true, false] : [false]) {
                    const page = await browser.newPage({
                            viewport: { width, height: 900 },
                            colorScheme: theme,
                            timezoneId: 'America/Chicago',
                        }),
                        errors = [];
                    page.setDefaultTimeout(10000);
                    page.on('pageerror', (e) => {
                        errors.push(e.message);
                        console.log('Browser error', e.message);
                    });
                    await page.goto(
                        server.resolvedUrls.local[0] +
                            `__boards?kind=${kind}&sport=${sport}&empty=${empty ? 1 : 0}&before=${before ? 1 : 0}&width=${width}&theme=${theme}`,
                    );
                    await page.evaluate(
                        (theme) =>
                            document.documentElement.classList.toggle(
                                'dark',
                                theme === 'dark',
                            ),
                        theme,
                    );
                    await page.waitForFunction(() => globalThis.__boardReady);
                    await page.waitForFunction(() =>
                        globalThis.__teamNflCalls.some(
                            (c) =>
                                c.section === 'games' || c.section === 'board',
                        ),
                    );
                    if (kind === 'team')
                        await page
                            .getByRole('heading', {
                                name:
                                    sport === 'mlb'
                                        ? 'Home City Home'
                                        : 'Home Team',
                                exact: true,
                            })
                            .waitFor();
                    else await page.getByLabel('Game date').waitFor();
                    const key = `${kind}-${sport}-${empty}-${width}-${theme}`;
                    await compare(page, key, before);
                    if (kind === 'team' && sport === 'nba' && !empty)
                        for (const tab of [
                            'Advanced Stats',
                            'Trends & Insights',
                            'Roster',
                            'Depth Chart',
                            'Schedule',
                        ]) {
                            await page
                                .getByRole('tab', { name: tab, exact: true })
                                .click();
                            await compare(page, key + '-' + tab, before);
                        }
                    if (kind === 'board' && !empty) {
                        await page.locator('button.group').first().click();
                        await page.getByRole('dialog').waitFor();
                        await compare(page, key + '-drawer', before);
                        await page.keyboard.press('Escape');
                        await page
                            .getByRole('dialog')
                            .waitFor({ state: 'detached' });
                        await page
                            .getByRole('button', {
                                name: 'More insights',
                                exact: true,
                            })
                            .click();
                        await page
                            .getByText('Super Bowl', { exact: true })
                            .waitFor();
                        await compare(page, key + '-insights', before);
                    }
                    assert.deepEqual(errors, []);
                    console.log(key + (before ? ' baseline' : ' passed'));
                    await page.close();
                }
    console.log('Visual comparisons:', comparisons);
    for (const [kind, failure] of [
        ['team', 'team'],
        ['team', 'roster'],
        ['board', 'seasons'],
        ['board', 'dates'],
        ['board', 'board'],
    ]) {
        const page = await browser.newPage({
            viewport: { width: 390, height: 900 },
        });
        page.setDefaultTimeout(10000);
        await page.goto(
            server.resolvedUrls.local[0] +
                `__boards?kind=${kind}&sport=nba&failure=${failure}&recovery=1`,
        );
        await page
            .getByRole('alert')
            .filter({ hasText: 'Fixture ' + failure + ' unavailable' })
            .waitFor();
        const beforeCalls = await page.evaluate(() => __teamNflCalls.length);
        await page.evaluate((f) => (__teamNflFailures[f] = false), failure);
        await page
            .getByRole('button', { name: 'Try again', exact: true })
            .click();
        await page.getByRole('alert').waitFor({ state: 'detached' });
        assert.ok(
            (await page.evaluate(() => __teamNflCalls.length)) > beforeCalls,
        );
        if (failure === 'roster')
            assert.equal(
                await page.evaluate(
                    () =>
                        __teamNflCalls.filter((c) => c.section === 'team')
                            .length,
                ),
                1,
            );
        console.log(kind + ' ' + failure + ' recovery passed');
        await page.close();
    }
} finally {
    await browser?.close();
    await server.close();
}
