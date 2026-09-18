// Run with Playwright installed, or PLAYWRIGHT_MODULE_PATH pointing to its index.mjs.
// Exercises real game-page components/CSS with synthetic data; app chrome is stubbed.
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { mkdir } from 'node:fs/promises';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';

const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        {
            name: 'nfl-mobile-fixture',
            enforce: 'pre',
            load(id) {
                if (id.endsWith('/composables/useNflDetailedGamePage.ts')) {
                    return "export { useFixturePage as useNflDetailedGamePage } from '/tests/browser/fixtures/nfl-mobile.mjs';";
                }
                if (id.endsWith('/game-page/GamePageShell.vue')) {
                    return '<template><main class="mx-auto flex w-full max-w-7xl flex-col gap-4 p-3"><slot /></main></template>';
                }
            },
            configureServer(vite) {
                vite.middlewares.use(
                    '/__nfl-mobile',
                    async (_request, response) => {
                        response.setHeader('Content-Type', 'text/html');
                        response.end(
                            await vite.transformIndexHtml(
                                '/__nfl-mobile',
                                `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><div id="app"></div><script type="module">
import { createApp } from 'vue';
import Game from '/resources/js/pages/NFL/Game.vue';
import '/resources/css/app.css';
createApp(Game, { gameId: 1722 }).mount('#app');
</script></body></html>`,
                            ),
                        );
                    },
                );
            },
        },
        vue(),
        tailwind(),
    ],
    resolve: { alias: { '@': `${root}resources/js` } },
    server: { host: '127.0.0.1', port: 0, hmr: false, ws: false, watch: null },
});
let browser;
try {
    await server.listen();
    const { revision } = await server.ssrLoadModule(
        '/tests/browser/fixtures/nfl-mobile.mjs',
    );
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
    });
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (error) => {
        errors.push(error.message);
        console.error(error.message);
    });
    let researchRequests = 0;
    await page.route('**/api/v1/nfl/games/1722/research', (route) => {
        researchRequests++;
        return route.fulfill({ json: { revisions: [revision] } });
    });
    const assertVisible = async (locator, expected = true) =>
        assert.equal(await locator.isVisible(), expected);
    const checkWidth = async () =>
        assert.equal(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
            true,
            'No page-level horizontal overflow',
        );
    const nav = page.getByRole('navigation', { name: 'Game sections' });
    const checkMatchupRow = async () => {
        const cells = await page.locator('[data-matchup-row] > *').all();
        assert.equal(cells.length, 3);
        const boxes = await Promise.all(
            cells.map((cell) => cell.boundingBox()),
        );
        const centers = boxes.map((box) => box.y + box.height / 2);
        assert.ok(
            Math.max(...centers) - Math.min(...centers) <= 2,
            'Teams and score stay aligned in one row',
        );
        assert.ok(
            boxes[0].x + boxes[0].width <= boxes[1].x &&
                boxes[1].x + boxes[1].width <= boxes[2].x,
            'Matchup columns do not overlap',
        );
        await assertVisible(
            page.getByRole('link', {
                name: 'Detroit Lions (Away)',
                exact: true,
            }),
        );
        await assertVisible(
            page.getByRole('link', {
                name: 'Buffalo Bills (Home)',
                exact: true,
            }),
        );
    };
    const select = async (name) => {
        await nav.getByRole('button', { name, exact: true }).click();
        assert.equal(
            await nav
                .getByRole('button', { name, exact: true })
                .getAttribute('aria-pressed'),
            'true',
        );
        await checkWidth();
    };
    const output = process.env.MOBILE_SCREENSHOT_DIR;
    if (output) await mkdir(output, { recursive: true });
    for (const width of [320, 390, 767]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(`${server.resolvedUrls.local[0]}__nfl-mobile`);
        await page.getByText('weather stale', { exact: true }).waitFor();
        await checkMatchupRow();
        const count = researchRequests;
        await assertVisible(
            page.getByText('Prediction Model', { exact: true }),
        );
        await assertVisible(page.getByText('Model total', { exact: true }));
        await assertVisible(page.getByText('48.5', { exact: true }));
        await assertVisible(page.getByText('1500', { exact: true }), false);
        await assertVisible(
            page.getByText('Total Yards', { exact: true }),
            false,
        );
        await page
            .locator('summary')
            .filter({ hasText: 'Team ratings (Elo)' })
            .click();
        await assertVisible(page.getByText('1500', { exact: true }));
        await page
            .locator('summary')
            .filter({ hasText: 'Team ratings (Elo)' })
            .click();
        await page
            .locator('summary')
            .filter({ hasText: 'Box score & game statistics' })
            .click();
        await assertVisible(page.getByText('Total Yards', { exact: true }));
        assert.equal(
            await page
                .locator('.ui-table-wrap')
                .evaluateAll((elements) =>
                    elements.every(
                        (element) =>
                            element.scrollWidth <= element.clientWidth + 1,
                    ),
                ),
            true,
            'Box score needs no horizontal swipe',
        );
        await checkWidth();
        await page
            .locator('summary')
            .filter({ hasText: 'Box score & game statistics' })
            .click();
        await assertVisible(
            page.getByText('Trends & Matchup History', { exact: true }),
            false,
        );
        await assertVisible(
            page.getByText('Verified supporting claim.', { exact: true }),
            false,
        );
        await assertVisible(
            page.getByText('DET Starter 1', { exact: true }),
            false,
        );
        assert.equal(
            await page.getByText('Betting Plan', { exact: true }).count(),
            0,
        );
        for (const button of await nav.getByRole('button').all()) {
            assert.ok((await button.boundingBox()).height >= 44);
        }
        await checkWidth();
        if (output && width === 390)
            await page.screenshot({
                path: `${output}/overview-390.png`,
                fullPage: true,
            });
        await select('Research');
        await assertVisible(
            page.getByText('Prediction Model', { exact: true }),
            false,
        );
        await assertVisible(page.getByText('weather stale', { exact: true }));
        await page
            .locator('summary')
            .filter({ hasText: 'Supporting evidence' })
            .click();
        await assertVisible(
            page.getByText('Verified supporting claim.', { exact: true }),
        );
        await checkWidth();
        await select('Roster');
        await page
            .locator('summary')
            .filter({ hasText: 'Depth charts & starters' })
            .click();
        await assertVisible(page.getByText('DET Starter 1', { exact: true }));
        await checkWidth();
        await select('Trends');
        const evidence = page.getByRole('region', {
            name: 'Team evidence windows',
        });
        await assertVisible(evidence.locator('article').nth(0));
        await assertVisible(evidence.locator('article').nth(1), false);
        await evidence
            .getByRole('button', { name: 'BUF', exact: true })
            .click();
        await assertVisible(evidence.locator('article').nth(1));
        await assertVisible(evidence.locator('article').nth(0), false);
        await evidence
            .getByRole('button', { name: 'Previous 3 seasons', exact: true })
            .click();
        await assertVisible(
            evidence.getByRole('heading', { name: 'BUF · Previous 3 seasons' }),
        );
        await checkWidth();
        if (output && width === 390)
            await page.screenshot({
                path: `${output}/trends-390.png`,
                fullPage: true,
            });
        await select('Overview');
        await assertVisible(page.getByText('weather stale', { exact: true }));
        assert.equal(
            researchRequests,
            count,
            'Navigation must not refetch research',
        );
        // Resize after choosing a hidden-on-mobile section: desktop must show all panels.
        await select('Research');
        await page.setViewportSize({ width: 1280, height: 900 });
        await checkMatchupRow();
        await assertVisible(nav, false);
        await assertVisible(
            page.getByText('Prediction Model', { exact: true }),
        );
        await assertVisible(
            page.getByText('Trends & Matchup History', { exact: true }),
        );
        await assertVisible(evidence.locator('article').nth(0));
        await assertVisible(evidence.locator('article').nth(1));
        await checkWidth();
        console.log(
            `PASS: ${width}px mobile navigation, evidence, holds, overflow, 1280px desktop restoration`,
        );
    }
    for (const phase of ['scheduled', 'live']) {
        await page.setViewportSize({ width: 320, height: 844 });
        await page.goto(
            `${server.resolvedUrls.local[0]}__nfl-mobile?phase=${phase}`,
        );
        await page.getByText('weather stale', { exact: true }).waitFor();
        await checkMatchupRow();
        await checkWidth();
        console.log(`PASS: 320px ${phase} matchup header`);
    }
    assert.deepEqual(errors, [], 'No browser runtime errors');
} finally {
    await browser?.close();
    await server.close();
}
