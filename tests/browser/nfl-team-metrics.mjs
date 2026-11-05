// Real keyboard interaction against synthetic fixtures and application CSS only.
// Uses an existing Playwright installation; no production data or API calls.
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';

const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const metrics = [
    {
        id: 1,
        display_rank: 1,
        team: { id: 1, display_name: 'Detroit Lions' },
        record_label: '1-0-1',
        games_played: 2,
        points_per_game: 27.5,
        points_allowed_per_game: 21,
        net_true_epa_per_play: 0.125,
        sample_sizes: { games: 2, yards_per_game: 1 },
    },
    {
        id: 2,
        display_rank: 2,
        team: { id: 2, display_name: 'Buffalo Bills' },
        record_label: '2-0-0',
        games_played: 2,
        points_per_game: 24,
        sample_sizes: { games: 2, yards_per_game: 2 },
    },
    { id: 3, team: { id: 3, display_name: 'Legacy Team' }, sample_sizes: null },
];
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        {
            name: 'metrics-keyboard-fixture',
            configureServer(vite) {
                vite.middlewares.use(
                    '/__metrics-keyboard',
                    async (_, response) => {
                        response.setHeader('Content-Type', 'text/html');
                        response.end(
                            await vite.transformIndexHtml(
                                '/__metrics-keyboard',
                                `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><main class="mx-auto max-w-6xl p-4"><h1>NFL metrics keyboard QA — synthetic data</h1><div id="app"></div></main><script type="module">
import { createApp, h, ref } from 'vue';
import Board from '/resources/js/components/NflTeamMetricsBoard.vue';
import { nflTeamMetricsConfig } from '/resources/js/config/sport-team-metrics-configs.ts';
import '/resources/css/app.css';
createApp({ setup() {
    const rows = ref(${JSON.stringify(metrics)});
    const submitted = ref(0);
    return () => h('form', { onSubmit: event => { event.preventDefault(); submitted.value++; } }, [
        h('output', { id: 'submissions' }, String(submitted.value)),
        h(Board, { metrics: rows.value, columns: nflTeamMetricsConfig.columns, teamLink: id => '/team/' + id, sortLabel: 'Net EPA/Play' }),
        h('button', { type: 'button', onClick: () => { rows.value = [...rows.value].reverse(); } }, 'Reverse rankings'),
        h('button', { type: 'button', onClick: () => { rows.value = rows.value.map(row => ({ ...row, points_per_game: 30 })); } }, 'Refresh metrics'),
    ]);
} }).mount('#app');
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
    resolve: {
        alias: [
            {
                find: /^@\/actions\/App\/Http\/Controllers\/(?:CBB|MLB|NBA|NFL|WCBB|WNBA)\/TeamController$/,
                replacement: `${root}tests/frontend/fixtures/teamController.mjs`,
            },
            { find: '@', replacement: `${root}resources/js` },
        ],
    },
    server: { host: '127.0.0.1', port: 0, hmr: false, ws: false, watch: null },
});
let browser;
try {
    await server.listen();
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
    });
    for (const width of [390, 1280]) {
        for (const theme of ['light', 'dark']) {
            const page = await browser.newPage({
                viewport: { width, height: 900 },
            });
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.route('**/*', (route) =>
                new URL(route.request().url()).hostname === '127.0.0.1'
                    ? route.continue()
                    : route.abort(),
            );
            await page.goto(
                `http://127.0.0.1:${server.httpServer.address().port}/__metrics-keyboard`,
            );
            const board = page.getByRole('region', {
                name: 'NFL team rankings',
                exact: true,
            });
            await board.waitFor();
            await page.evaluate(
                (dark) =>
                    document.documentElement.classList.toggle('dark', dark),
                theme === 'dark',
            );
            const first = board.getByRole('button', {
                name: /offense, defense & situational details for Detroit Lions/,
            });
            const second = board.getByRole('button', {
                name: /offense, defense & situational details for Buffalo Bills/,
            });
            const firstId = await first.getAttribute('id');
            const secondId = await second.getAttribute('id');
            const firstPanelId = await first.getAttribute('aria-controls');
            const secondPanelId = await second.getAttribute('aria-controls');
            const firstPanel = page.locator(`[id="${firstPanelId}"]`);
            const secondPanel = page.locator(`[id="${secondPanelId}"]`);
            assert.equal(await first.getAttribute('aria-expanded'), 'false');
            assert.equal(await firstPanel.isVisible(), false);
            assert.equal(await firstPanel.textContent(), '');
            assert.equal(
                await firstPanel.getAttribute('aria-labelledby'),
                firstId,
            );
            assert.equal(
                await secondPanel.getAttribute('aria-labelledby'),
                secondId,
            );
            assert.equal(await board.getByRole('button').count(), 2);
            assert.equal(
                await page.locator('form').count(),
                1,
                'The rankings section must not add a nested form',
            );

            await page.keyboard.press('Tab');
            assert.equal(
                await board
                    .getByRole('link', { name: /Detroit Lions/ })
                    .evaluate((element) => element === document.activeElement),
                true,
            );
            await page.keyboard.press('Tab');
            assert.equal(
                await first.evaluate(
                    (element) => element === document.activeElement,
                ),
                true,
            );
            const focus = await first.evaluate((element) => ({
                visible: element.matches(':focus-visible'),
                width: getComputedStyle(element).outlineWidth,
                style: getComputedStyle(element).outlineStyle,
            }));
            assert.equal(focus.visible, true);
            assert.ok(parseFloat(focus.width) >= 2);
            assert.notEqual(focus.style, 'none');
            const size = await first.boundingBox();
            assert.ok(
                size.height >= 44,
                'Disclosure target must be at least 44px high',
            );

            await page.keyboard.press('Enter');
            await page.waitForFunction(
                (id) =>
                    document
                        .getElementById(id)
                        ?.getAttribute('aria-expanded') === 'true',
                firstId,
            );
            assert.equal(await firstPanel.isVisible(), true);
            assert.equal(
                await first.evaluate(
                    (element) => element === document.activeElement,
                ),
                true,
            );
            await page.keyboard.press('Tab');
            assert.equal(
                await board
                    .getByRole('link', { name: /Buffalo Bills/ })
                    .evaluate((element) => element === document.activeElement),
                true,
            );
            await page.keyboard.press('Tab');
            assert.equal(
                await second.evaluate(
                    (element) => element === document.activeElement,
                ),
                true,
            );
            await page.keyboard.press('Space');
            await page.waitForFunction(
                (id) =>
                    document
                        .getElementById(id)
                        ?.getAttribute('aria-expanded') === 'true',
                secondId,
            );
            assert.equal(await firstPanel.isVisible(), true);
            assert.equal(await secondPanel.isVisible(), true);

            await page.keyboard.press('Shift+Tab');
            await page.keyboard.press('Shift+Tab');
            assert.equal(
                await first.evaluate(
                    (element) => element === document.activeElement,
                ),
                true,
            );
            await page.keyboard.press('Space');
            await page.waitForFunction(
                (id) =>
                    document
                        .getElementById(id)
                        ?.getAttribute('aria-expanded') === 'false',
                firstId,
            );
            assert.equal(await firstPanel.isVisible(), false);
            assert.equal(await firstPanel.textContent(), '');
            assert.equal(await secondPanel.isVisible(), true);
            assert.equal(
                await first.evaluate(
                    (element) => element === document.activeElement,
                ),
                true,
            );
            await page.keyboard.press('Enter');
            await page.waitForFunction(
                (id) =>
                    document
                        .getElementById(id)
                        ?.getAttribute('aria-expanded') === 'true',
                firstId,
            );
            assert.equal(
                await page.locator('#submissions').textContent(),
                '0',
                'Disclosure keys must never submit the surrounding form',
            );

            await page
                .getByRole('button', { name: 'Reverse rankings' })
                .click();
            assert.equal(await first.getAttribute('id'), firstId);
            assert.equal(await second.getAttribute('id'), secondId);
            assert.equal(await first.getAttribute('aria-expanded'), 'true');
            assert.equal(await second.getAttribute('aria-expanded'), 'true');
            await page.getByRole('button', { name: 'Refresh metrics' }).click();
            assert.equal(
                await first.getAttribute('aria-controls'),
                firstPanelId,
            );
            assert.equal(await firstPanel.isVisible(), true);
            assert.equal(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
                true,
            );
            assert.deepEqual(errors, []);
            console.log(
                `Passed keyboard and disclosure checks: ${width}px, ${theme}`,
            );
            await page.close();
        }
    }
} finally {
    await browser?.close();
    await server.close();
}
