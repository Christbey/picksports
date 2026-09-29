// Synthetic API responses only: no provider refreshes or production mutations.
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../frontend', import.meta.url));
const server = await createServer({
    root,
    configFile: `${root}/vite.config.ts`,
    server: {
        port: 5174,
        host: '127.0.0.1',
        hmr: false,
        ws: false,
        watch: null,
    },
});
let browser;
try {
    await server.listen();
    console.log('Preview ready');
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
    });
    console.log('Browser ready');
    const page = await browser.newPage({ reducedMotion: 'reduce' });
    page.setDefaultTimeout(10000);
    const errors = [];
    const requests = [];
    let authenticated = false;
    let challenge = false;
    page.on('pageerror', (error) => errors.push(error.message));
    const team = (id, abbreviation, display_name) => ({
        id,
        abbreviation,
        display_name,
        name: display_name,
        active_injuries: [],
    });
    const home = team(2, 'CHI', 'Chicago Bears');
    const away = team(1, 'PHI', 'Philadelphia Eagles');
    await page.route('**/*', async (route) => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        if (path === '/login' && request.method() === 'GET')
            return route.continue();
        if (
            !path.startsWith('/api/') &&
            ![
                '/sanctum/csrf-cookie',
                '/login',
                '/logout',
                '/two-factor-challenge',
            ].includes(path)
        )
            return route.continue();
        requests.push(path);
        let status = 200;
        let body = { data: [] };
        if (path === '/api/v2/auth/context') {
            status = authenticated ? 200 : 401;
            body = authenticated
                ? {
                      data: {
                          name: 'PickSports',
                          auth: {
                              user: { id: 1, name: 'Test', is_admin: false },
                          },
                          subscription: {
                              tiers_enabled: false,
                              tiers_bypassed: false,
                              is_subscribed: true,
                              tier: 'free',
                              features: [],
                          },
                      },
                  }
                : { message: 'Unauthenticated.' };
        } else if (path === '/login') {
            challenge = true;
            body = { two_factor: true };
        } else if (path === '/two-factor-challenge') {
            assert.ok(challenge);
            authenticated = true;
            body = {};
        } else if (path === '/logout') {
            authenticated = false;
            status = 204;
        } else if (path === '/sanctum/csrf-cookie') status = 204;
        else if (path.endsWith('/available-seasons')) body = { data: [2026] };
        else if (path.endsWith('/available-dates'))
            body = { data: ['2026-09-28'] };
        else if (path.endsWith('/games/1753/page'))
            body = {
                data: {
                    game: {
                        id: 1753,
                        home_team: home,
                        away_team: away,
                        home_team_id: 2,
                        away_team_id: 1,
                        season: 2026,
                        season_type: '2',
                        week: 3,
                        game_date: '2026-09-28',
                        starts_at: '2026-09-29T00:15:00Z',
                        status: 'STATUS_SCHEDULED',
                        home_score: null,
                        away_score: null,
                    },
                    prediction: null,
                    team_stats: [],
                },
            };
        else if (path.endsWith('/trends'))
            body = {
                data: {
                    sample_size: 0,
                    trends: {},
                    locked_trends: {},
                    scored_signals: [],
                },
            };
        else if (path.endsWith('/research')) body = { data: null };
        await route.fulfill({
            status,
            contentType: 'application/json',
            body: status === 204 ? '' : JSON.stringify(body),
        });
    });
    await page.goto('http://127.0.0.1:5174/nfl/predictions');
    await page.getByLabel('Email', { exact: true }).waitFor();
    console.log('Login rendered');
    assert.match(page.url(), /\/login\?next=/);
    await page.reload(); // Direct /login navigation must render Vue, not proxy HTML from Laravel.
    await page
        .getByLabel('Email', { exact: true })
        .fill('synthetic@example.test');
    await page
        .getByLabel('Password', { exact: true })
        .fill('synthetic-password');
    await page
        .getByRole('button', { name: 'Continue', exact: true })
        .press('Enter');
    await page.getByLabel('Authentication code').fill('123456');
    await page
        .getByRole('button', { name: 'Continue', exact: true })
        .press('Enter');
    await page
        .getByRole('heading', { name: 'NFL predictions', exact: true })
        .waitFor();
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 950 });
        await page.goto('http://127.0.0.1:5174/nfl/games/1753');
        await page
            .getByText('Chicago Bears', { exact: true })
            .first()
            .waitFor({ state: 'attached' });
        await page.screenshot({
            path: `/tmp/standalone-${width}.png`,
            fullPage: true,
        });
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
            `Overflow at ${width}px`,
        );
    }
    assert.equal(
        requests.filter((path) => path.endsWith('/games/1753/page')).length,
        2,
    );
    assert.equal(
        requests.filter((path) => path.endsWith('/games/1753/prediction'))
            .length,
        0,
    );
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await page.getByLabel('Email', { exact: true }).waitFor();
    assert.deepEqual(errors, []);
    console.log(
        'Standalone login, 2FA, NFL board, game page, desktop/mobile layout and logout passed.',
    );
} catch (error) {
    console.error(error);
    process.exitCode = 1;
} finally {
    await browser?.close();
    await server.close();
}
