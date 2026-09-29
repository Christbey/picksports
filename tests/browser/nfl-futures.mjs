import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const row = {
    team_id: 1,
    team_name: 'Stored Team',
    conference: 'AFC',
    division: 'East',
    projected_wins: 8.5,
    projected_seed: null,
    division_winner_probability: 0,
    make_playoffs_probability: 0,
    conference_champion_probability: 0,
    super_bowl_champion_probability: 0,
    market_odds: {
        price: 2600,
        bookmaker: 'draftkings',
        fetched_at: '2026-09-26T17:10:10Z',
        stale: true,
    },
    market_edge: { edge_probability: null },
};
const response = {
    data: [
        row,
        {
            ...row,
            team_id: 2,
            team_name: 'Fresh Team',
            projected_seed: 3.4,
            super_bowl_champion_probability: 0.1,
            market_odds: { ...row.market_odds, stale: false },
            market_edge: { edge_probability: 0.02 },
        },
    ],
    meta: {
        simulations: 5000,
        warnings: ['Equal-record tiebreakers are randomized.'],
    },
};
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        {
            name: 'futures-fixture',
            enforce: 'pre',
            load(id) {
                if (id.endsWith('/useApiV2Client.ts'))
                    return `export const useApiV2Client = () => ({ forecasts: { index: async () => (${JSON.stringify(response)}) } });`;
                if (id.endsWith('/PredictionsPageShell.vue'))
                    return '<template><main class="p-4"><slot /></main></template>';
            },
            configureServer(vite) {
                vite.middlewares.use('/__futures', async (_req, res) => {
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            '/__futures',
                            '<html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><div id="app"></div><script type="module">import {createApp} from "vue"; import Page from "/resources/js/pages/NFL/Futures.vue"; import "/resources/css/app.css"; createApp(Page).mount("#app");</script></body></html>',
                        ),
                    );
                });
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
    browser = await chromium.launch({
        channel: process.env.PLAYWRIGHT_CHANNEL,
    });
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    for (const width of [390, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`${server.resolvedUrls.local[0]}__futures`);
        const stale = page.getByRole('row').filter({ hasText: 'Stored Team' });
        await stale.waitFor();
        assert.match(await stale.innerText(), /Stale/);
        assert.match(await stale.innerText(), /\+2600/);
        assert.match(await stale.innerText(), /0%\*/);
        assert.equal(
            (await stale.locator('td').nth(2).innerText()).trim(),
            '-',
        );
        assert.equal(
            (await stale.locator('td').last().innerText()).trim(),
            '-',
        );
        await page
            .getByRole('checkbox', { name: 'Positive model differences' })
            .check();
        assert.equal(await stale.count(), 0);
        assert.equal(
            await page
                .getByRole('row')
                .filter({ hasText: 'Fresh Team' })
                .count(),
            1,
        );
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
    }
    assert.deepEqual(errors, []);
    console.log(
        'PASS: futures freshness labels, conditional seeds, model filter, zero-sample explanation, mobile and desktop',
    );
} finally {
    await browser?.close();
    await server.close();
}
