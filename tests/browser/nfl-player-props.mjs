// Synthetic data; tests the real card and application CSS without production calls.
// PLAYWRIGHT_MODULE_PATH can point to an existing Playwright index.mjs.
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';

const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const record = (wins, games) => ({
    wins,
    games,
    losses: games - wins,
    pushes: 0,
    win_rate: (wins / games) * 100,
});
const rec = {
    id: 1,
    player: {
        name: 'A Player With A Very Long Name',
        team: 'CAR',
        position: 'RB',
        url: null,
    },
    recommendation: 'Under',
    line: 13.5,
    market: 'Combined Rushing and Receiving Yards',
    odds: -110,
    bookmaker: 'Example Sportsbook',
    confidence: 80,
    fetched_at: '2026-09-20T15:10:00Z',
    freshness_hours: 24,
    game: {
        away_team: 'CAR',
        home_team: 'ATL',
        kickoff_label: 'Sep 20, 12:00 PM CDT',
    },
    stats: {
        season_avg: 7,
        historical_avg: 10,
        season_summary: {
            season: 2026,
            games: 1,
            opponent: 'ATL',
            opponent_conference: 'NFC',
        },
        cover_record: {
            season: record(1, 1),
            last_season: record(11, 15),
            all_time: record(44, 75),
            vs_opponent: record(4, 8),
            vs_conference: record(33, 53),
        },
    },
};
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        {
            name: 'nfl-prop-preview',
            configureServer(vite) {
                vite.middlewares.use(
                    '/__nfl-props',
                    async (_request, response) => {
                        response.setHeader('Content-Type', 'text/html');
                        response.end(
                            await vite.transformIndexHtml(
                                '/__nfl-props',
                                `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><div id="app"></div><script type="module">
import { createApp, h } from 'vue';
import Card from '/resources/js/components/player-props/NflPlayerPropCard.vue';
import '/resources/css/app.css';
const rec = ${JSON.stringify(rec)};
const nowMs = ${Date.parse('2026-09-20T16:00:00Z')};
createApp({ render: () => h('main', { class: 'mx-auto grid max-w-7xl grid-cols-1 items-start gap-4 p-3 md:grid-cols-2 xl:grid-cols-3' }, [h(Card, { rec, nowMs }), h(Card, { rec: { ...rec, id: 2 }, nowMs })]) }).mount('#app');
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
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
    });
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(
        `http://127.0.0.1:${server.httpServer.address().port}/__nfl-props`,
    );
    const card = page.getByRole('article').first();
    await card.waitFor();
    for (const width of [320, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        assert.equal(
            await card.getByText('1/1', { exact: true }).isVisible(),
            true,
        );
        assert.equal(
            await card.getByText('Signal score (not win %)').isVisible(),
            false,
        );
        assert.equal(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
            true,
            `Closed card overflow at ${width}`,
        );
        await card.locator('summary').focus();
        await page.keyboard.press('Enter');
        assert.equal(
            await card.getByText('Signal score (not win %)').isVisible(),
            true,
        );
        assert.equal(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
            true,
            `Expanded card overflow at ${width}`,
        );
        await card.locator('summary').click();
    }
    await page.setViewportSize({ width: 390, height: 900 });
    await page.screenshot({
        path: '/private/tmp/nfl-props-clean-mobile.png',
        fullPage: true,
    });
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await page.screenshot({
        path: '/private/tmp/nfl-props-clean-dark.png',
        fullPage: true,
    });
    assert.deepEqual(errors, []);
    console.log(
        'PASS: 320/390/768/1440px, no horizontal overflow, visible cover history, keyboard-expandable details. Screenshots: /private/tmp/nfl-props-clean-{mobile,dark}.png',
    );
} finally {
    await browser?.close();
    await server.close();
}
