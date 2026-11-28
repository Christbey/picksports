// Selected real-component accessibility fixtures; synthetic transport, no auth or delivery.
import assert from 'node:assert/strict';
import { appendFileSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';
import {
    listenFixtureServer,
    fixtureOrigin,
    fixtureNoHmrPlugin,
    launchFixtureBrowser,
    isolateFixtureNetwork,
    admitsFixtureRequest,
    writeFixtureNetworkReceipt,
    configureOwnedFixtureOutputs,
} from './runtime.mjs';
const { chromium } = await import('playwright');
const root = fileURLToPath(new URL('../../../', import.meta.url));
const group = process.argv[2];
if (group !== '--list') configureOwnedFixtureOutputs();
const groups = {
    'navigation-board': [
        'A01',
        'A02',
        'A03',
        'A04',
        'B01',
        'B02',
        'B03',
        'B04',
        'B05',
    ],
    'forms-states': [
        'C01',
        'C02',
        'C03',
        'C04',
        'C05',
        'D01',
        'D02',
        'D03',
        'D04',
    ],
    standalone: ['E01', 'E02'],
};
assert(groups[group] || group === '--list');
if (group !== '--list')
    assert(process.env.REVIEW_A11Y_TRACE && process.env.REVIEW_A11Y_OUTCOMES);
const standalone = group === 'standalone';
const manifest = JSON.parse(
    readFileSync(root + 'public/build/manifest.json', 'utf8'),
);
const css = '/build/' + manifest['resources/js/app.ts'].css[0];
let server;
const makeServer = async () =>
    createServer({
        root,
        configFile: false,
        envFile: false,
        envPrefix: 'A11Y_NO_ENV_',
        define: { 'import.meta.env.VITE_API_ORIGIN': '""' },
        cacheDir: process.env.REVIEW_BROWSER_CACHE_DIR,
        optimizeDeps: {
            noDiscovery: true,
            include: [
                'vue',
                'vue-router',
                'lucide-vue-next',
                'reka-ui',
                '@vueuse/core',
                'class-variance-authority',
                'clsx',
                'tailwind-merge',
            ],
        },
        plugins: [
            fixtureNoHmrPlugin(root),
            {
                name: 'a11y-owned-synthetic-entry',
                enforce: 'pre',
                load(id) {
                    if (
                        !standalone &&
                        id.endsWith('/composables/useApiV2Client.ts')
                    )
                        return "export {useApiV2Client} from '/tests/browser/accessibility/board-api.mjs';";
                },
                configureServer(vite) {
                    vite.middlewares.use(async (req, res, next) => {
                        const url = new URL(req.url, 'http://fixture');
                        if (
                            !req.headers.accept?.includes('text/html') ||
                            !(
                                url.pathname === '/__accessibility' ||
                                (standalone &&
                                    [
                                        '/login',
                                        '/nfl/predictions',
                                        '/',
                                    ].includes(url.pathname))
                            )
                        )
                            return next();
                        const id = url.searchParams.get('__a11y');
                        if (!groups[group].includes(id)) {
                            res.statusCode = 404;
                            res.end('Unknown owned fixture');
                            return;
                        }
                        const entry = standalone
                            ? '/frontend/src/main.ts'
                            : '/tests/browser/accessibility/entry.mjs';
                        res.setHeader('Content-Type', 'text/html');
                        res.end(
                            await vite.transformIndexHtml(
                                url.pathname,
                                `<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Owned accessibility fixture</title><link rel="stylesheet" href="${css}"></head><body><div id="app"></div><script>globalThis.__A11Y_CASE_ID=${JSON.stringify(id)};</script><script type="module" src="${entry}"></script></body></html>`,
                            ),
                        );
                    });
                },
            },
            vue(),
            ...(standalone ? [tailwind()] : []),
        ],
        resolve: {
            alias: [
                ...(!standalone
                    ? [
                          {
                              find: '@inertiajs/vue3',
                              replacement:
                                  root +
                                  'tests/browser/accessibility/inertia.mjs',
                          },
                      ]
                    : [
                          {
                              find: '@/platform/AppLayout.vue',
                              replacement: root + 'frontend/src/AppLayout.vue',
                          },
                          {
                              find: '@/platform',
                              replacement:
                                  root + 'frontend/src/platform/index.ts',
                          },
                      ]),
                { find: '@', replacement: root + 'resources/js' },
            ],
        },
        server: {
            host: '127.0.0.1',
            port: 0,
            hmr: false,
            ws: false,
            watch: null,
            allowedHosts: ['127.0.0.1'],
            fs: { strict: true, allow: [root] },
        },
    });
let browser;
const outcomes = [];
let currentId;
const trace = async (page, step) => {
    let aria, dom;
    try {
        aria = (await page.locator('body').ariaSnapshot()).slice(0, 60000);
    } catch (e) {
        aria = 'Snapshot unavailable: ' + e.message;
    }
    try {
        dom = await page.evaluate(() => ({
            text: document.body.innerText.slice(0, 12000),
            focus: {
                tag: document.activeElement?.tagName,
                id: document.activeElement?.id,
                role: document.activeElement?.getAttribute('role'),
                label: document.activeElement?.getAttribute('aria-label'),
                outline: document.activeElement
                    ? getComputedStyle(document.activeElement).outline
                    : null,
                boxShadow: document.activeElement
                    ? getComputedStyle(document.activeElement).boxShadow
                    : null,
            },
            nativeSelects: [...document.querySelectorAll('select')].map(
                (e) => ({
                    id: e.id,
                    value: e.value,
                    selectedIndex: e.selectedIndex,
                    focused: e === document.activeElement,
                    options: [...e.options].map((o) => ({
                        value: o.value,
                        text: o.text,
                        selected: o.selected,
                        disabled: o.disabled,
                    })),
                }),
            ),
            nativeKeyboardEvents: globalThis.__a11yNativeKeyboardEvents ?? [],
            forms: [...document.querySelectorAll('form')].map((f) => ({
                action: f.getAttribute('action'),
                controls: [...f.querySelectorAll('input,select,button')].map(
                    (e) => ({
                        tag: e.tagName,
                        id: e.id,
                        type: e.type,
                        ariaInvalid: e.getAttribute('aria-invalid'),
                        describedby: e.getAttribute('aria-describedby'),
                    }),
                ),
            })),
        }));
    } catch (e) {
        dom = { error: e.message };
    }
    appendFileSync(
        process.env.REVIEW_A11Y_TRACE,
        JSON.stringify({ id: currentId, step, aria, dom }) + '\n',
        { mode: 0o600 },
    );
};
async function tabTo(page, locator, max = 90) {
    await locator.waitFor({ state: 'visible' });
    await page.waitForFunction(
        (e) => !e.disabled && e.getAttribute('aria-disabled') !== 'true',
        await locator.elementHandle(),
    );
    for (let i = 0; i < max; i++) {
        if (await locator.evaluate((e) => e === document.activeElement)) return;
        await page.keyboard.press('Tab');
    }
    throw new assert.AssertionError({
        message: 'Required control not reachable through Tab',
    });
}
async function waitFocus(page, locator) {
    await page.waitForFunction(
        (e) => e === document.activeElement,
        await locator.elementHandle(),
    );
}
async function activate(page, locator) {
    await tabTo(page, locator);
    await page.keyboard.press('Enter');
}
async function focusInside(page, dialog) {
    await page.waitForFunction(
        (e) => e.contains(document.activeElement),
        await dialog.elementHandle(),
    );
    assert(await dialog.evaluate((e) => e.contains(document.activeElement)));
}
async function trap(page, dialog) {
    await focusInside(page, dialog);
    const n = await dialog.evaluate(
        (e) =>
            [
                ...e.querySelectorAll(
                    'a[href],button,input,select,textarea,[tabindex]',
                ),
            ].filter(
                (x) =>
                    !x.disabled && x.tabIndex >= 0 && x.getClientRects().length,
            ).length,
    );
    assert(n > 0 && n <= 58, 'Bounded supported dialog control count');
    for (const key of ['Tab', 'Shift+Tab'])
        for (let i = 0; i < n + 2; i++) {
            await page.keyboard.press(key);
            await focusInside(page, dialog);
        }
}
async function fieldError(page, input, message) {
    await message.waitFor({ state: 'visible' });
    await trace(page, 'validation-before-association-assert');
    const associated = await input.evaluate(
        (el, text) => {
            const ids = (
                (el.getAttribute('aria-describedby') ?? '') +
                ' ' +
                (el.getAttribute('aria-errormessage') ?? '')
            )
                .split(/\s+/)
                .filter(Boolean);
            return (
                el.getAttribute('aria-invalid') === 'true' &&
                ids.some((id) =>
                    document.getElementById(id)?.textContent.includes(text),
                )
            );
        },
        await message.innerText(),
    );
    assert(
        associated,
        'Visible validation error must be programmatically associated with its invalid field',
    );
}
const tests = {
    A01: async (p) => {
        const links = p.getByRole('link', {
            name: /Get started|Create account|Sign up|Log in|Sign in/i,
        });
        assert((await links.count()) > 0);
        const target = links.first();
        await tabTo(p, target);
        assert((await target.getAttribute('href'))?.startsWith('/'));
        const before = await target.getAttribute('href');
        await p.route('**' + before, (r) =>
            r.fulfill({
                contentType: 'text/html',
                body: '<!doctype html><html lang="en"><title>Synthetic destination</title><h1>Synthetic account destination</h1></html>',
            }),
        );
        await p.keyboard.press('Enter');
        await p
            .getByRole('heading', { name: 'Synthetic account destination' })
            .waitFor();
    },
    A02: async (p) => {
        const opener = p.locator('[data-test="sidebar-menu-button"]');
        await activate(p, opener);
        const item = p.getByRole('menuitem', { name: 'Settings' });
        await item.waitFor();
        await p.keyboard.press('ArrowDown');
        await trace(p, 'menu-open');
        await p.keyboard.press('Escape');
        await waitFocus(p, opener);
        assert(await opener.evaluate((e) => e === document.activeElement));
    },
    A03: async (p) => {
        const opener = p.getByRole('button', { name: 'Toggle Sidebar' });
        await activate(p, opener);
        const dialog = p.getByRole('dialog', { name: 'Sidebar' });
        await dialog.waitFor();
        await trap(p, dialog);
        await trace(p, 'sidebar-open');
        await p.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        await waitFocus(p, opener);
        assert(await opener.evaluate((e) => e === document.activeElement));
    },
    A04: async (p) => {
        const opener = p.getByRole('button', {
            name: 'Open feedback form',
            exact: true,
        });
        await activate(p, opener);
        const dialog = p.getByRole('dialog', { name: 'Send feedback' });
        await dialog.waitFor();
        await trap(p, dialog);
        await trace(p, 'feedback-open');
        await p.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        await waitFocus(p, opener);
        assert(await opener.evaluate((e) => e === document.activeElement));
    },
    B01: async (p) => {
        await activate(
            p,
            p.getByRole('button', { name: 'Filters', exact: true }),
        );
        const date = p.getByRole('combobox', {
            name: 'Game date',
            exact: true,
        });
        await tabTo(p, date);
        const oldValue = await date.inputValue();
        assert(
            await date.evaluate((e) => e === document.activeElement),
            'Game date must be keyboard reachable',
        );
        await trace(
            p,
            'date-keyboard-reachable-native-selection-manual-unverified',
        );
        const target = await date.evaluate(
            (e) =>
                [...e.options].find((o) => !o.disabled && o.value !== e.value)
                    ?.value,
        );
        assert(target, 'Fixture requires another enabled actual option');
        await date.selectOption(target);
        await p.waitForFunction(
            ({ element, value }) => element.value === value,
            { element: await date.elementHandle(), value: target },
        );
        assert.notEqual(await date.inputValue(), oldValue);
        await trace(p, 'date-programmatic-selection-functional-only');
        const season = p.getByLabel('Season', { exact: true });
        await tabTo(p, season);
        const search = p.getByRole('textbox', { name: /Search/i });
        await tabTo(p, search);
        await p.keyboard.type('Home');
        assert.equal(await search.inputValue(), 'Home');
        await trace(p, 'filters-keyboard');
    },
    B02: async (p) => {
        const open = p.getByRole('button', { name: /^Show analysis/ }).first();
        await activate(p, open);
        const close = p.getByRole('button', { name: /^Hide analysis/ }).first();
        await close.waitFor();
        await trace(p, 'analysis-open');
        assert.equal(await close.getAttribute('aria-expanded'), 'true');
        await p.keyboard.press('Space');
        await close.waitFor({ state: 'hidden' });
        await open.waitFor();
        assert.equal(await open.getAttribute('aria-expanded'), 'false');
    },
    B03: async (p) => {
        const nav = p.getByRole('navigation', { name: 'Prediction pages' });
        const prev = nav.getByRole('button', { name: 'Previous', exact: true }),
            next = nav.getByRole('button', { name: 'Next', exact: true });
        assert(await prev.isDisabled());
        await activate(p, next);
        await nav.getByText('Page 2 of 2', { exact: true }).waitFor();
        await p.waitForFunction(
            (e) => e.isConnected && !e.disabled,
            await prev.elementHandle(),
        );
        await trace(p, 'pagination-updated-controls-enabled');
        assert(await next.isDisabled());
        assert(
            await p.evaluate(() => document.activeElement !== document.body),
            'Pagination must not abandon keyboard focus to document body',
        );
        await activate(p, prev);
        await nav.getByText('Page 1 of 2', { exact: true }).waitFor();
        await p.waitForFunction(
            (e) => e.isConnected && !e.disabled,
            await next.elementHandle(),
        );
        assert(
            await p.evaluate(() => document.activeElement !== document.body),
            'Completed reverse pagination must retain keyboard focus',
        );
    },
    B04: async (p) => {
        const region = p.getByRole('region', {
            name: 'CBB forecast Championship',
            exact: true,
        });
        await tabTo(p, region);
        assert(await region.getAttribute('aria-label'));
        const scrollable = await region.evaluate(
            (e) => e.scrollWidth > e.clientWidth + 1,
        );
        await trace(p, 'overflow-region');
        if (scrollable) {
            await p.keyboard.press('ArrowRight');
            await p.waitForFunction(() =>
                [...document.querySelectorAll('[role="region"]')].some(
                    (e) => e.scrollLeft > 0,
                ),
            );
            await p.keyboard.press('ArrowLeft');
        }
        assert(
            await p.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
    },
    B05: async (p) => {
        const summary = p.locator('details summary').first();
        await activate(p, summary);
        assert(await summary.evaluate((e) => e.parentElement.open));
        await trace(p, 'details-open');
        await p.keyboard.press('Space');
        assert(!(await summary.evaluate((e) => e.parentElement.open)));
    },
    C01: async (p) => {
        await activate(
            p,
            p.getByRole('button', { name: 'Use email instead', exact: true }),
        );
        const email = p.getByLabel('Email address', { exact: true }),
            password = p.getByLabel('Password', { exact: true });
        await tabTo(p, email);
        await p.keyboard.type('synthetic@example.test');
        await tabTo(p, password);
        await p.keyboard.type('fixture-not-real');
        const remember = p.getByRole('checkbox', { name: /Remember/i });
        await tabTo(p, remember);
        await p.keyboard.press('Space');
        await activate(
            p,
            p.getByRole('button', { name: 'Log in', exact: true }),
        );
        await fieldError(
            p,
            email,
            p.getByText('Synthetic email rejected', { exact: true }),
        );
    },
    C02: async (p) => {
        const name = p.getByLabel('Name', { exact: true }),
            email = p.getByLabel('Email address', { exact: true });
        await tabTo(p, name);
        await p.keyboard.press('ControlOrMeta+A');
        await p.keyboard.type('Fixture viewer edited');
        await tabTo(p, email);
        await p.keyboard.press('ControlOrMeta+A');
        await p.keyboard.type('synthetic@example.test');
        await activate(
            p,
            p.getByRole('button', { name: 'Save', exact: true }).first(),
        );
        await fieldError(
            p,
            email,
            p.getByText('Synthetic email rejected', { exact: true }),
        );
        assert.equal(await email.inputValue(), 'synthetic@example.test');
    },
    C03: async (p) => {
        const phone = p.getByLabel('Phone Number (Optional)', { exact: true });
        await tabTo(p, phone);
        await p.keyboard.type('+15550000001');
        const enabled = p.getByRole('checkbox', {
            name: 'Enable Betting Alerts',
            exact: true,
        });
        await tabTo(p, enabled);
        await p.keyboard.press('Space');
        await activate(
            p,
            p.locator('[data-test="save-alert-preferences-button"]'),
        );
        await fieldError(
            p,
            phone,
            p.getByText('Synthetic phone rejected', { exact: true }),
        );
    },
    C04: async (p) => {
        await p.getByText('Loading passkeys...', { exact: true }).waitFor();
        await p.evaluate(() =>
            __a11yPasskeyRequests[0].resolve(
                new Response(JSON.stringify({ message: 'Synthetic failure' }), {
                    status: 500,
                    headers: { 'Content-Type': 'application/json' },
                }),
            ),
        );
        const error = p.getByText('Failed to load passkeys.', { exact: false });
        await error.waitFor();
        const retry = p.getByRole('button', {
            name: 'Retry loading passkeys',
            exact: true,
        });
        await tabTo(p, retry);
        await trace(p, 'passkey-error-before-announcement-assert');
        assert(
            await error
                .first()
                .evaluate(
                    (e) =>
                        !!e.closest(
                            '[role="alert"],[role="status"],[aria-live]',
                        ),
                ),
            'Passkey listing error must be announced',
        );
        await p.keyboard.press('Enter');
        await p.waitForFunction(() => __a11yPasskeyRequests.length === 2);
        await p.evaluate(() =>
            __a11yPasskeyRequests[1].resolve(
                new Response(JSON.stringify({ passkeys: [] }), {
                    headers: { 'Content-Type': 'application/json' },
                }),
            ),
        );
        await p.getByText('No passkeys added yet.', { exact: true }).waitFor();
    },
    C05: async (p) => {
        const opener = p.locator('[data-test="delete-user-button"]');
        await activate(p, opener);
        const dialog = p.getByRole('dialog', {
            name: 'Are you sure you want to delete your account?',
        });
        await dialog.waitFor();
        await dialog.getByLabel('Password', { exact: true }).waitFor();
        await trap(p, dialog);
        await trace(p, 'delete-dialog-cancel-only');
        await activate(
            p,
            dialog.getByRole('button', { name: 'Cancel', exact: true }),
        );
        await dialog.waitFor({ state: 'hidden' });
        await waitFocus(p, opener);
        assert(await opener.evaluate((e) => e === document.activeElement));
    },
    D01: async (p) => {
        await p.getByRole('status', { name: 'Loading predictions' }).waitFor();
        await trace(p, 'loading-before-release');
        await p.waitForFunction(() => typeof __a11yReleaseEmpty === 'function');
        await p.evaluate(() => __a11yReleaseEmpty());
        await p
            .getByText(/No predictions|No games|No matchups/i)
            .first()
            .waitFor();
        assert.equal(
            await p
                .getByRole('status', { name: 'Loading predictions' })
                .count(),
            0,
        );
    },
    D02: async (p) => {
        const retry = p
            .getByRole('button', { name: /Retry|Try again/i })
            .first();
        await retry.waitFor();
        await trace(p, 'board-error');
        assert(
            (await p.getByRole('alert').count()) > 0,
            'Board failure must be exposed as error',
        );
        await p.evaluate(() => {
            __a11yBoard.flags.fail = false;
        });
        await activate(p, retry);
        await p
            .getByRole('button', { name: /^Show analysis/ })
            .first()
            .waitFor();
    },
    D03: async (p) => {
        await p
            .getByText(/Pregame.*unavailable/i)
            .first()
            .waitFor();
        await trace(p, 'nullable-live-consumer');
        await p
            .getByRole('status')
            .filter({ hasText: 'Synthetic live estimate' })
            .waitFor();
        assert(!(await p.locator('body').innerText()).includes('Pregame: 0%'));
    },
    D04: async (p) => {
        const list = p.getByRole('tablist', { name: 'Monitoring sections' }),
            evaluation = list.getByRole('tab', {
                name: 'Evaluation',
                exact: true,
            }),
            details = list.getByRole('tab', { name: 'Details', exact: true });
        await tabTo(p, evaluation);
        await p.keyboard.press('ArrowRight');
        await waitFocus(p, details);
        await trace(p, 'manual-tab-details-focused');
        await p.keyboard.press('Enter');
        await p.waitForFunction(
            (e) => e.getAttribute('aria-selected') === 'true',
            await details.elementHandle(),
        );
        assert.equal(await details.getAttribute('aria-selected'), 'true');
        await p
            .getByRole('tabpanel', { name: 'Details', exact: true })
            .waitFor();
        await p.keyboard.press('Home');
        await waitFocus(p, evaluation);
        await p.keyboard.press('Space');
        await p.waitForFunction(
            (e) => e.getAttribute('aria-selected') === 'true',
            await evaluation.elementHandle(),
        );
        assert.equal(await evaluation.getAttribute('aria-selected'), 'true');
        await trace(p, 'monitoring-tab-and-count');
        assert(
            (await p.locator('body').innerText()).includes(
                '2 home-win probability observations',
            ),
        );
    },
    E01: async (p) => {
        const email = p.getByLabel('Email', { exact: true }),
            password = p.getByLabel('Password', { exact: true });
        await tabTo(p, email);
        await p.keyboard.type('synthetic@example.test');
        await tabTo(p, password);
        await p.keyboard.type('fixture-not-real');
        await activate(
            p,
            p.getByRole('button', { name: 'Continue', exact: true }),
        );
        const code = p.getByLabel('Authentication code', { exact: true });
        await tabTo(p, code);
        await p.keyboard.type('123456');
        await activate(
            p,
            p.getByRole('button', { name: 'Continue', exact: true }),
        );
        await p
            .getByRole('alert')
            .filter({ hasText: 'Synthetic code rejected' })
            .waitFor();
        await trace(p, 'standalone-synthetic-challenge-error');
    },
    E02: async (p) => {
        const nav = p.getByRole('navigation', { name: 'Main navigation' });
        await nav.waitFor();
        const link = nav.getByRole('link', {
            name: 'NFL predictions',
            exact: true,
        });
        await tabTo(p, link);
        await p.keyboard.press('Enter');
        await p
            .getByRole('heading', { name: 'NFL predictions', exact: true })
            .waitFor();
        await trace(p, 'standalone-navigation');
        assert(
            await p.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
    },
};
async function standaloneRoutes(page, origin, id) {
    const team = (n, name) => ({
        id: n,
        abbreviation: name,
        display_name: name,
        name,
        active_injuries: [],
    });
    const home = team(2, 'Home team'),
        away = team(1, 'Away team');
    await page.route('**/*', async (route) => {
        if (!admitsFixtureRequest(route, origin)) {
            await route.abort('blockedbyclient');
            return;
        }
        const url = new URL(route.request().url()),
            path = url.pathname,
            method = route.request().method();
        let body,
            status = 200;
        if (path === '/api/v2/auth/context') {
            body =
                id === 'E02'
                    ? {
                          data: {
                              name: 'PickSports',
                              auth: {
                                  user: {
                                      id: 1,
                                      name: 'Fixture viewer',
                                      is_admin: false,
                                  },
                              },
                              subscription: {
                                  tiers_enabled: false,
                                  is_subscribed: true,
                                  tier: 'free',
                                  features: [],
                              },
                          },
                      }
                    : { message: 'Unauthenticated.' };
            status = id === 'E02' ? 200 : 401;
        } else if (path === '/login' && method === 'POST')
            body = { two_factor: true };
        else if (path === '/two-factor-challenge' && method === 'POST') {
            status = 422;
            body = { message: 'Synthetic code rejected' };
        } else if (path === '/sanctum/csrf-cookie') {
            status = 204;
            body = null;
        } else if (path === '/api/v2/user-bets')
            body = { data: [], tracking: null };
        else if (path.endsWith('/available-seasons')) body = { data: [2026] };
        else if (path.endsWith('/available-dates'))
            body = { data: ['2026-09-28'] };
        else if (path.startsWith('/api/') && path.endsWith('/predictions'))
            body = {
                data: [
                    {
                        id: 1,
                        game_id: 1753,
                        status: 'STATUS_SCHEDULED',
                        game: {
                            id: 1753,
                            home_team: home,
                            away_team: away,
                            kickoff_at: '2026-09-29T00:15:00Z',
                        },
                        win_probability: 0.3,
                        predicted_spread: -5.5,
                        predicted_total: 42,
                        nfl_board: {
                            research: {
                                status: 'refresh_blocked',
                                decision: 'pass',
                            },
                            market: {
                                home_spread: 4.5,
                                total: 41.5,
                                bookmaker: 'Fixture',
                                observed_at: '2026-09-26T16:38:53Z',
                                spread_stale: true,
                                total_stale: true,
                            },
                        },
                    },
                ],
                meta: {
                    pagination: { current_page: 1, last_page: 1, total: 1 },
                },
            };
        else if (
            path.startsWith('/api/') ||
            [
                '/logout',
                '/two-factor-challenge',
                '/sanctum/csrf-cookie',
            ].includes(path)
        ) {
            throw new Error(
                'Unsupported synthetic standalone request ' +
                    method +
                    ' ' +
                    path,
            );
        } else {
            await route.continue();
            return;
        }
        await route.fulfill({
            status,
            contentType: 'application/json',
            body: status === 204 ? '' : JSON.stringify(body),
        });
    });
}
if (group === '--list') {
    const ids = Object.values(groups).flat();
    assert.equal(ids.length, 20);
    assert.equal(new Set(ids).size, 20);
    assert.deepEqual(Object.keys(tests).sort(), [...ids].sort());
    console.log(JSON.stringify({ groups, ids }));
    process.exit(0);
}
try {
    server = await makeServer();
    await listenFixtureServer(server);
    const origin = fixtureOrigin(server);
    browser = await launchFixtureBrowser(chromium);
    for (const id of groups[group]) {
        currentId = id;
        let context,
            page,
            mounted = false;
        const errors = [];
        try {
            const mobile = ['A03', 'B04', 'B05', 'C03', 'E02'].includes(id);
            context = await browser.newContext({
                viewport: {
                    width: id === 'B04' ? 320 : mobile ? 390 : 1440,
                    height: 900,
                },
                serviceWorkers: 'block',
                acceptDownloads: false,
                reducedMotion: 'reduce',
            });
            await isolateFixtureNetwork(context, origin);
            page = await context.newPage();
            page.setDefaultTimeout(6000);
            page.setDefaultNavigationTimeout(12000);
            page.on('pageerror', (e) => errors.push(e.message));
            if (standalone) await standaloneRoutes(page, origin, id);
            await page.goto(
                origin +
                    (standalone
                        ? id === 'E01'
                            ? '/login'
                            : '/nfl/predictions'
                        : '/__accessibility') +
                    '?__a11y=' +
                    id,
            );
            if (standalone) await page.getByRole('heading').first().waitFor();
            else
                await page.waitForFunction(
                    () => globalThis.__a11yReady === true,
                );
            mounted = true;
            await trace(page, 'mounted-before-assertions');
            await tests[id](page);
            await trace(page, 'after-controls');
            assert.deepEqual(errors, []);
            outcomes.push({
                id,
                status: 'passed',
                mounted,
                errors,
                ...(id === 'B01'
                    ? {
                          coverageLimits: {
                              nativeKeyboardDateSelection: 'manual_unverified',
                              dateSelectionFunctionalControl:
                                  'programmatic_selectOption',
                              keyboardReachability: true,
                          },
                      }
                    : {}),
            });
        } catch (e) {
            if (page) await trace(page, 'failed-before-outcome');
            outcomes.push({
                id,
                status: 'failed',
                classification: !mounted
                    ? 'fixture_setup'
                    : e.name === 'AssertionError' && errors.length === 0
                      ? 'actual_accessibility_assertion'
                      : 'post_mount_transition_or_fixture_needs_triage',
                error: { name: e.name, message: e.message },
                mounted,
                errors,
            });
        } finally {
            await context?.close();
        }
    }
    assert.deepEqual(
        outcomes.map((x) => x.id),
        groups[group],
    );
} catch (e) {
    console.error('A11Y_PRIMARY_ERROR', e.stack ?? e);
    throw e;
} finally {
    await browser?.close();
    await server?.close();
    appendFileSync(
        process.env.REVIEW_A11Y_OUTCOMES,
        JSON.stringify({ group, expected: groups[group], outcomes }) + '\n',
        { mode: 0o600 },
    );
    writeFixtureNetworkReceipt();
}
assert(
    outcomes.length === groups[group].length &&
        outcomes.every((x) => x.status === 'passed'),
    'Accessibility assertions failed; raw states and setup classifications retained',
);
console.log('A11Y_GROUP_PASSED', JSON.stringify({ group, ids: groups[group] }));
