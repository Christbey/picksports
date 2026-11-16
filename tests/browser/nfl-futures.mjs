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
    is_eliminated: false,
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
const bracketTeams = ['AFC', 'NFC'].flatMap((conference, index) =>
    Array.from({ length: 7 }, (_, position) => ({
        ...row,
        team_id: 10 + index * 7 + position,
        team_name: `${conference} Seed ${position + 1}`,
        conference,
        division: ['East', 'North', 'South', 'West'][position % 4],
        division_winner_probability: position < 4 ? 0.7 : 0.2,
        wild_card_probability: position < 4 ? 0.1 : 0.55,
        make_playoffs_probability: position < 4 ? 0.8 : 0.75,
        market_odds: null,
    })),
);
const response = {
    data: [
        row,
        {
            ...row,
            team_id: 2,
            team_name: 'Fresh Team',
            make_playoffs_probability: 0.01,
            projected_seed: 3.4,
            super_bowl_champion_probability: 0.1,
            market_odds: { ...row.market_odds, stale: false },
            market_edge: { edge_probability: 0.02 },
        },
        {
            ...row,
            team_id: 3,
            team_name: 'Eliminated Team',
            is_eliminated: true,
            market_odds: null,
        },
        {
            ...row,
            team_id: 4,
            team_name: 'Long Shot Team',
            make_playoffs_probability: 0.0099,
            market_odds: null,
        },
        ...bracketTeams,
    ],
    meta: {
        simulations: 1200,
        attempted_simulations: 5000,
        completed_postseason_games: 3,
        scheduled_postseason_games: 2,
        projected_bracket: ['AFC', 'NFC'].map((conference) => ({
            conference,
            seeds: bracketTeams
                .filter((team) => team.conference === conference)
                .map((team, index) => ({
                    team_id: team.team_id,
                    seed: index + 1,
                })),
        })),
        warnings: [
            'Only simulated brackets compatible with every known postseason matchup and result were counted.',
        ],
    },
};
const olderResponse = { data: response.data, meta: { simulations: 500 } };
const withheldResponse = {
    data: [],
    meta: {
        ...response.meta,
        simulations: 0,
        warnings: [
            'No simulated bracket could reproduce every known postseason matchup and result.',
        ],
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
                    return `const responses = ${JSON.stringify([response, olderResponse, withheldResponse])};
                        export const useApiV2Client = () => ({
                            forecasts: { index: async (_sport, { query }) =>
                                responses[new Date().getFullYear() - query.season] },
                        });`;
                if (id.endsWith('/useViewerAccess.ts'))
                    return 'import { ref } from "vue"; export const useViewerAccess = () => ({ isAdmin: ref(true) });';
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
    const page = await browser.newPage({ locale: 'en-US' });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    for (const width of [320, 390, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`${server.resolvedUrls.local[0]}__futures`);
        const outlook = page.getByRole('table', {
            name: 'Playoff and Super Bowl Outlook',
        });
        const stale = outlook
            .getByRole('row')
            .filter({ hasText: 'Stored Team' });
        await stale.waitFor();
        await page
            .getByText('1,200 of 5,000 simulations accepted.', { exact: false })
            .waitFor();
        for (const conference of ['AFC', 'NFC']) {
            const field = page.getByRole('table', {
                name: conference,
                exact: true,
            });
            await field
                .getByRole('rowheader', { name: `${conference} Seed 7` })
                .waitFor();
            assert.deepEqual(await field.locator('tbody').count(), 2);
            assert.deepEqual(
                await field
                    .locator('tbody')
                    .nth(0)
                    .locator('tr td:first-child')
                    .allTextContents(),
                ['1', '2', '3', '4'],
            );
            assert.deepEqual(
                await field
                    .locator('tbody')
                    .nth(1)
                    .locator('tr td:first-child')
                    .allTextContents(),
                ['5', '6', '7'],
            );
            assert.match(
                await field.locator('tbody').nth(0).innerText(),
                /Division winners[\s\S]*70\.0%/,
            );
            assert.match(
                await field.locator('tbody').nth(1).innerText(),
                /Wild cards[\s\S]*55\.0%/,
            );
        }
        const hunt = page
            .getByRole('heading', { name: 'In the hunt' })
            .locator('..');
        assert.match(await hunt.innerText(), /Fresh Team[\s\S]*1\.0%/);
        assert.doesNotMatch(
            await hunt.innerText(),
            /Stored Team|Long Shot Team/,
        );
        const virtual = page
            .getByRole('heading', { name: 'Virtually eliminated', exact: true })
            .locator('..');
        assert.match(await virtual.innerText(), /Stored Team[\s\S]*0%\*/);
        assert.match(await virtual.innerText(), /Long Shot Team[\s\S]*0\.99%/);
        assert.doesNotMatch(
            await virtual.innerText(),
            /Eliminated Team|Fresh Team/,
        );
        const eliminated = page
            .getByRole('heading', { name: 'Eliminated', exact: true })
            .locator('..');
        assert.match(await eliminated.innerText(), /Eliminated Team/);
        await page
            .getByText(
                'Known postseason games include 3 completed and 2 scheduled.',
                { exact: false },
            )
            .waitFor();
        assert.match(await stale.innerText(), /Stale/);
        assert.match(await stale.innerText(), /\+2600/);
        assert.match(await stale.innerText(), /0%\*/);
        assert.deepEqual(
            await outlook.getByRole('columnheader').allTextContents(),
            ['Team', 'Playoffs', 'Super Bowl', 'Super Bowl odds'],
        );
        assert.doesNotMatch(
            await stale.innerText(),
            /Avg\. seed|model difference/,
        );
        assert.equal(
            await stale.locator('time').getAttribute('datetime'),
            row.market_odds.fetched_at,
        );
        const fresh = outlook
            .getByRole('row')
            .filter({ hasText: 'Fresh Team' });
        assert.match(await fresh.innerText(), /Avg\. seed 3\.4/);
        assert.match(await fresh.innerText(), /\+2\.0pp model difference/);
        await page
            .getByRole('checkbox', { name: 'Positive model differences' })
            .check();
        assert.equal(
            await page.getByRole('table', { name: 'AFC', exact: true }).count(),
            1,
        );
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
    const season = page.getByLabel('Season', { exact: true });
    await season.selectOption({ index: 1 });
    await page.getByText('500 simulations.', { exact: true }).waitFor();
    assert.equal(
        await page
            .getByRole('heading', { name: 'Projected Playoff Field' })
            .count(),
        0,
    );
    assert.equal(await page.getByText(/simulations accepted/).count(), 0);
    assert.equal(await page.getByText(/Known postseason games/).count(), 0);

    await season.selectOption({ index: 2 });
    await page.getByText(/0 of 5,000 simulations accepted/).waitFor();
    await page.getByText(/No simulated bracket could reproduce/).waitFor();
    await page.getByText('No NFL futures available for this season.').waitFor();
    assert.equal(await page.getByRole('row').count(), 0);

    assert.deepEqual(errors, []);
    console.log(
        'PASS: NFL futures mobile and desktop, forecast counts, season changes, older responses, and withheld forecasts',
    );
} finally {
    await browser?.close();
    await server.close();
}
