// Local-only fixtures; compare with BETS_MLB_BASELINE_REF to verify the shipped UI.
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
    baseline = process.env.BETS_MLB_BASELINE_REF;
const paths = {
    bets: 'pages/MyBets.vue',
    matchup: 'components/mlb/MlbMatchupBoard.vue',
    daily: 'components/mlb/MlbDailyPicksPanel.vue',
};
const manifest = JSON.parse(
        readFileSync(root + 'public/build/manifest.json', 'utf8'),
    ),
    css = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-bets-mlb-browser-vite',
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
            name: 'bets-mlb-browser',
            enforce: 'pre',
            resolveId(id) {
                if (id.includes('__BoardBefore'))
                    return root + 'resources/js/' + id.split('/').at(-1);
                if (id.endsWith('/routes')) return 'virtual:board-routes';
            },
            load(id) {
                const kind = id.match(/__BoardBefore(\w+)\.vue$/)?.[1];
                if (kind)
                    return execFileSync(
                        'git',
                        ['show', `${baseline}:resources/js/${paths[kind]}`],
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
                        empty = q.get('empty') === '1',
                        before = q.get('before') === '1';
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            '/__boards' + req.url,
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${css}"></head><body><div id="app"></div><script type="module">import {createApp,nextTick} from 'vue';import Page from '/resources/js/${before ? '__BoardBefore' + kind + '.vue' : paths[kind]}';import {installBoardApi} from '/tests/frontend/fixtures/betsMlbBoards.mjs';installBoardApi(${JSON.stringify(kind)},${empty});createApp(Page,{season:2026}).mount('#app');await nextTick();globalThis.__boardReady=true;</script></body></html>`,
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
            { find: '@', replacement: root + 'resources/js' },
        ],
    },
    server: { host: '127.0.0.1', port: 0 },
});
await server.listen();
let browser;
const shots = new Map();
let comparisons = 0;
async function stable(page) {
    await page.evaluate(() => document.fonts.ready);
    await page.mouse.move(0, 0);
    await page.addStyleTag({
        content:
            '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}',
    });
    let prev, shot;
    for (let i = 0; i < 8; i++) {
        shot = await page.screenshot({
            fullPage: true,
            animations: 'disabled',
        });
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
async function compare(page, key, before) {
    const shot = await stable(page),
        hash = await pixelHash(page, shot);
    if (before) shots.set(key, { shot, hash });
    else if (baseline) {
        if (hash !== shots.get(key).hash) {
            writeFileSync('/tmp/bets-mlb-before.png', shots.get(key).shot);
            writeFileSync('/tmp/bets-mlb-after.png', shot);
        }
        assert.equal(hash, shots.get(key).hash, 'UI changed: ' + key);
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
            for (const kind of ['bets', 'matchup', 'daily'])
                for (const empty of [false, true])
                    for (const before of baseline ? [true, false] : [false]) {
                        console.log('Start', kind, empty, width, theme, before);
                        const page = await browser.newPage({
                                viewport: { width, height: 900 },
                                colorScheme: theme,
                            }),
                            errors = [];
                        page.setDefaultTimeout(10000);
                        page.on('pageerror', (e) => {
                            errors.push(e.message);
                            console.log('Browser error', e.message);
                        });
                        await page.goto(
                            server.resolvedUrls.local[0] +
                                `__boards?kind=${kind}&empty=${empty ? 1 : 0}&before=${before ? 1 : 0}&width=${width}&theme=${theme}`,
                        );
                        await page.evaluate(
                            (theme) =>
                                document.documentElement.classList.toggle(
                                    'dark',
                                    theme === 'dark',
                                ),
                            theme,
                        );
                        await page.waitForFunction(
                            () => globalThis.__boardReady,
                        );
                        await page
                            .getByText(
                                kind === 'bets'
                                    ? 'Bet History'
                                    : kind === 'daily'
                                      ? 'Best Available Per Game'
                                      : 'Matchups',
                                { exact: true },
                            )
                            .waitFor();
                        const key = `${kind}-${empty}-${width}-${theme}`;
                        await compare(page, key, before);
                        if (kind === 'bets') {
                            await page
                                .getByRole('button', { name: 'Log New Bet' })
                                .click();
                            await page
                                .getByLabel('Bet Amount', { exact: true })
                                .waitFor();
                            await compare(page, key + '-form', before);
                        }
                        if (!empty && kind !== 'bets') {
                            const card = page
                                .locator(
                                    kind === 'daily'
                                        ? 'button.group'
                                        : 'button.group',
                                )
                                .first();
                            await card.click();
                            await page.getByRole('dialog').waitFor();
                            await compare(page, key + '-drawer', before);
                        }
                        assert.deepEqual(errors, []);
                        console.log(key + (before ? ' baseline' : ' passed'));
                        await page.close();
                    }
    for (const kind of ['bets', 'matchup', 'daily']) {
        const page = await browser.newPage({
                viewport: { width: 390, height: 900 },
            }),
            errors = [];
        page.setDefaultTimeout(10000);
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(
            server.resolvedUrls.local[0] +
                `__boards?kind=${kind}&empty=0&before=0&recovery=1`,
        );
        await page.waitForFunction(() => globalThis.__boardReady);
        await page
            .getByText(
                kind === 'bets'
                    ? 'Bet History'
                    : kind === 'daily'
                      ? 'Best Available Per Game'
                      : 'Matchups',
                { exact: true },
            )
            .waitFor();
        await page.evaluate(() => (globalThis.__boardFail = true));
        if (kind === 'bets')
            await page
                .getByRole('button', { name: 'Mark bet won', exact: true })
                .click();
        else
            await page
                .getByRole('button', {
                    name: kind === 'daily' ? 'Load' : 'Refresh',
                    exact: true,
                })
                .click();
        await page
            .getByRole('status')
            .filter({ hasText: 'Showing the previous results.' })
            .waitFor();
        assert.equal(
            await page
                .getByText(
                    kind === 'bets'
                        ? 'Bet History'
                        : kind === 'daily'
                          ? 'Best Available Per Game'
                          : 'Matchups',
                    { exact: true },
                )
                .isVisible(),
            true,
        );
        await page.evaluate(() => (globalThis.__boardFail = false));
        await page
            .getByRole('button', { name: 'Try again', exact: true })
            .click();
        await page
            .getByRole('button', { name: 'Try again', exact: true })
            .waitFor({ state: 'detached' });
        if (kind === 'bets') {
            await page
                .getByRole('button', { name: 'Log New Bet', exact: true })
                .click();
            await page.getByLabel('Bet Amount', { exact: true }).fill('25');
            await page.getByLabel('Odds', { exact: true }).fill('-110');
            await page
                .getByLabel('Notes (Optional)', { exact: true })
                .fill('Keep this note');
            await page.evaluate(() => (globalThis.__writeFail = true));
            await page
                .getByRole('button', { name: 'Save Bet', exact: true })
                .click();
            await page
                .getByRole('alert')
                .filter({ hasText: 'Fixture save failed' })
                .waitFor();
            assert.equal(
                await page
                    .getByLabel('Notes (Optional)', { exact: true })
                    .inputValue(),
                'Keep this note',
            );
            assert.equal(
                await page.getByLabel('Odds', { exact: true }).inputValue(),
                '-110',
            );
        } else {
            await page.locator('button.group').first().focus();
            await page.keyboard.press('Enter');
            await page.getByRole('dialog').waitFor();
            await page.keyboard.press('Escape');
            await page.getByRole('dialog').waitFor({ state: 'detached' });
        }
        assert.deepEqual(errors, []);
        await page.close();
        console.log(kind + ' recovery and keyboard interactions passed');
    }
    console.log('Exact visual comparisons:', comparisons);
} finally {
    await browser?.close();
    await server.close();
}
