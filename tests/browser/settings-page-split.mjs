// Local fixture coverage. Set SETTINGS_SPLIT_BASELINE_REF for exact comparisons with a release.
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
const root = fileURLToPath(new URL('../../', import.meta.url));
const baseline = process.env.SETTINGS_SPLIT_BASELINE_REF;
const paths = {
    team: 'pages/settings/TeamMappings.vue',
    alerts: 'pages/settings/AlertPreferences.vue',
};
const manifest = JSON.parse(
    readFileSync(root + 'public/build/manifest.json', 'utf8'),
);
const stylesheet = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-settings-split-browser-vite',
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
            name: 'cleanup-browser-fixtures',
            resolveId(id) {
                if (id.includes('__CleanupBefore'))
                    return root + 'resources/js/' + id.split('/').at(-1);
                if (id.endsWith('/routes/alert-preferences'))
                    return 'virtual:settings-alert-route';
                if (id.endsWith('/routes/web-push'))
                    return 'virtual:settings-push-route';
                if (id.endsWith('/routes')) return 'virtual:cleanup-routes';
            },
            load(id) {
                const kind = id.match(/__CleanupBefore(\w+)\.vue$/)?.[1];
                if (kind)
                    return execFileSync(
                        'git',
                        ['show', `${baseline}:resources/js/${paths[kind]}`],
                        { cwd: root, encoding: 'utf8' },
                    );
                if (id.endsWith('/components/SubscriptionBanner.vue'))
                    return '<template><div/></template>';
                if (id.endsWith('/composables/useViewerAccess.ts'))
                    return `import {computed} from 'vue';export const useViewerAccess=()=>({isAdmin:computed(()=>false)});`;
                if (id.endsWith('/composables/useApiV2Client.ts'))
                    return 'export const useApiV2Client=()=>globalThis.__futuresApi;';
                if (id === 'virtual:settings-alert-route')
                    return `export const update={form:()=>({action:'/settings/alert-preferences',method:'patch'})};`;
                if (id === 'virtual:settings-push-route')
                    return `export default {subscriptions:{store:{url:()=>'/settings/web-push/subscriptions'},destroy:{url:()=>'/settings/web-push/subscriptions'}},test:{url:()=>'/settings/web-push/test'}};`;
                if (id === 'virtual:cleanup-routes')
                    return `export const home=()=>({url:'/'});export const dashboard=()=>({url:'/dashboard'});export const login=()=>({url:'/login'});export const register=()=>({url:'/register'});`;
            },
            configureServer(vite) {
                vite.middlewares.use('/__settings-split', async (req, res) => {
                    const q = new URL(req.url, 'http://fixture').searchParams,
                        kind = q.get('kind'),
                        variant = q.get('variant');
                    const path =
                        q.get('before') === '1'
                            ? `__CleanupBefore${kind}.vue`
                            : paths[kind];
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            '/__settings-split' + req.url,
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-csrf"><link rel="stylesheet" href="${stylesheet}"></head><body><div id="app"></div><script type="module">
import {createApp,nextTick} from 'vue';import Page from '/resources/js/${path}';import {mappingsProps,alertProps} from '/tests/frontend/fixtures/settingsPageSplit.mjs';import {installPushBrowser} from '/tests/frontend/fixtures/settingsPageSplitBrowser.mjs';
installPushBrowser(${JSON.stringify(variant)});const props=${JSON.stringify(kind)}==='team'?mappingsProps(${JSON.stringify(variant)}==='player',${JSON.stringify(variant)}==='empty'):alertProps(${JSON.stringify(variant)});
if(${JSON.stringify(variant)}==='cfbd'){props.currentProvider='cfbd';props.currentSport='americanfootball_ncaaf';props.queryParams={provider:'cfbd'};props.externalSourceLabel='CFBD';}
createApp(Page,props).mount('#app');await nextTick();globalThis.__settingsSplitReady=true;
</script></body></html>`,
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
                    root +
                    'tests/frontend/fixtures/settingsPageSplitInertia.mjs',
            },
            {
                find: '@/layouts/AppLayout.vue',
                replacement: root + 'tests/frontend/fixtures/adminLayout.mjs',
            },
            {
                find: '@/layouts/settings/Layout.vue',
                replacement: root + 'tests/frontend/fixtures/adminLayout.mjs',
            },
            {
                find: '@/components/predictions/PredictionsPageShell.vue',
                replacement: root + 'tests/frontend/fixtures/adminLayout.mjs',
            },
            { find: '@', replacement: root + 'resources/js' },
        ],
    },
    server: { host: '127.0.0.1', port: 0, hmr: false, ws: false, watch: null },
});
let browser;
const images = new Map();
let comparisons = 0;
let lastScreenshot;
async function imageHash(page) {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.mouse.move(0, 0);
    await page.addStyleTag({
        content:
            '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}',
    });
    await page.evaluate(() => document.fonts.ready);
    let previous;
    for (let i = 0; i < 5; i++) {
        await page.evaluate(
            () =>
                new Promise((r) =>
                    requestAnimationFrame(() => requestAnimationFrame(r)),
                ),
        );
        lastScreenshot = await page.screenshot({
            animations: 'disabled',
            fullPage: true,
        });
        const hash = createHash('sha256').update(lastScreenshot).digest('hex');
        if (hash === previous) return hash;
        previous = hash;
    }
    throw new Error('Screenshot did not stabilize');
}
async function compare(page, key, before) {
    const hash = await imageHash(page);
    if (before)
        images.set(key, {
            hash,
            png: lastScreenshot,
            html: await page.locator('#app').innerHTML(),
        });
    else if (baseline) {
        if (hash !== images.get(key).hash) {
            writeFileSync(
                '/tmp/frontend-cleanup-before.png',
                images.get(key).png,
            );
            writeFileSync('/tmp/frontend-cleanup-after.png', lastScreenshot);
            writeFileSync(
                '/tmp/frontend-cleanup-before.html',
                images.get(key).html,
            );
            writeFileSync(
                '/tmp/frontend-cleanup-after.html',
                await page.locator('#app').innerHTML(),
            );
        }
        assert.equal(
            hash,
            images.get(key).hash,
            `UI changed: ${key}; screenshots saved in /tmp/frontend-cleanup-{before,after}.png`,
        );
        comparisons++;
    }
}

const outcomes = new Map();
const canonical = (data) =>
    [...data].map(([key, value]) => [key, String(value)]);
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
    const context = await browser.newContext();
    for (const before of baseline ? [true, false] : [false])
        for (const kind of ['team', 'alerts'])
            for (const variant of kind === 'team'
                ? ['normal', 'player', 'empty', 'cfbd']
                : [
                      'normal',
                      'defaults',
                      'phone',
                      'subscribed',
                      'unconfigured',
                      'validation',
                  ]) {
                const page = await context.newPage(),
                    errors = [];
                page.setDefaultTimeout(15000);
                page.on('pageerror', (e) => errors.push(e.message));
                page.on('console', (m) => {
                    if (/Failed to resolve component/.test(m.text()))
                        errors.push(m.text());
                });
                await page.route('**/*', (route) =>
                    new URL(route.request().url()).hostname === '127.0.0.1'
                        ? route.continue()
                        : route.abort(),
                );
                await page.goto(
                    `http://127.0.0.1:${server.httpServer.address().port}/__settings-split/${kind}-${variant}-${before ? 1 : 0}?kind=${kind}&variant=${variant}&before=${before ? 1 : 0}`,
                );
                await page.waitForFunction(
                    () => globalThis.__settingsSplitReady,
                );
                for (const width of [1280, 390]) {
                    await page.setViewportSize({ width, height: 900 });
                    for (const dark of [false, true]) {
                        await page.evaluate(
                            (d) =>
                                document.documentElement.classList.toggle(
                                    'dark',
                                    d,
                                ),
                            dark,
                        );
                        await compare(
                            page,
                            `${kind}-${variant}-${width}-${dark}`,
                            before,
                        );
                    }
                }
                if (kind === 'team' && ['normal', 'player'].includes(variant)) {
                    await page
                        .getByLabel('Search', { exact: true })
                        .fill('suggested');
                    assert.equal(
                        await page
                            .getByRole('button', {
                                name: 'Accept Suggestion',
                                exact: true,
                            })
                            .count(),
                        1,
                    );
                    assert.equal(
                        await page.evaluate(() => __settingsSplitCalls.length),
                        0,
                    );
                    await page.getByLabel('Search', { exact: true }).clear();
                    await page
                        .getByRole('button', { name: 'Edit', exact: true })
                        .first()
                        .click();
                    const field = page.locator('#espn-1');
                    if (variant === 'player')
                        await field.fill('Changed Player');
                    else await field.selectOption('Away Team');
                    await compare(page, `team-${variant}-editing`, before);
                    await page
                        .getByRole('button', { name: 'Save', exact: true })
                        .click();
                    let call = await page.evaluate(() => {
                        const c = __settingsSplitCalls.at(-1);
                        return {
                            method: c.method,
                            url: c.url,
                            data: c.data,
                            preserveScroll: c.options?.preserveScroll,
                        };
                    });
                    assert.deepEqual(
                        call.data,
                        variant === 'player'
                            ? {
                                  espn_player_name: 'Changed Player',
                                  espn_player_id: null,
                              }
                            : { espn_team_name: 'Away Team' },
                    );
                    assert.equal(call.preserveScroll, true);
                    await page.evaluate(() =>
                        __settingsSplitCalls.at(-1).options.onSuccess(),
                    );
                    assert.equal(
                        await page
                            .getByRole('button', { name: 'Save', exact: true })
                            .count(),
                        0,
                    );
                    await page
                        .getByRole('button', {
                            name: 'Accept Suggestion',
                            exact: true,
                        })
                        .click();
                    assert.deepEqual(
                        await page.evaluate(
                            () => __settingsSplitCalls.at(-1).data,
                        ),
                        variant === 'player'
                            ? {
                                  espn_player_name: 'Away Player',
                                  espn_player_id: 0,
                              }
                            : { espn_team_name: 'Away Team' },
                    );
                    await page
                        .getByRole('button', { name: 'Remove', exact: true })
                        .first()
                        .click();
                    assert.equal(
                        await page.evaluate(
                            () => __settingsSplitCalls.at(-1).method,
                        ),
                        'delete',
                    );
                    await page
                        .getByRole('button', { name: 'Next', exact: true })
                        .click();
                    assert.equal(
                        new URL(
                            await page.evaluate(
                                () => __settingsSplitCalls.at(-1).url,
                            ),
                            'https://fixture.invalid',
                        ).searchParams.get('page'),
                        '2',
                    );
                    if (variant === 'normal') {
                        await page
                            .getByRole('button', {
                                name: 'Sync Odds API Teams',
                                exact: true,
                            })
                            .click();
                        assert.equal(
                            await page
                                .getByRole('button', {
                                    name: 'Syncing...',
                                    exact: true,
                                })
                                .isDisabled(),
                            true,
                        );
                        await page.evaluate(() =>
                            __settingsSplitCalls.at(-1).options.onFinish(),
                        );
                        await page
                            .getByRole('button', { name: 'CFBD', exact: true })
                            .click();
                        assert.equal(
                            new URL(
                                await page.evaluate(
                                    () => __settingsSplitCalls.at(-1).url,
                                ),
                                'https://fixture.invalid',
                            ).searchParams.get('sport'),
                            'americanfootball_ncaaf',
                        );
                    }
                }
                if (kind === 'alerts' && variant === 'normal') {
                    assert.equal(await page.locator('form').count(), 1);
                    await page
                        .getByRole('checkbox', {
                            name: 'Enable Betting Alerts',
                            exact: true,
                        })
                        .uncheck();
                    await page
                        .getByRole('checkbox', {
                            name: 'Receive Daily Digest Emails',
                            exact: true,
                        })
                        .uncheck();
                    await page
                        .getByRole('checkbox', {
                            name: 'Push Notifications',
                            exact: true,
                        })
                        .uncheck();
                    await page
                        .getByRole('checkbox', {
                            name: 'WhatsApp',
                            exact: true,
                        })
                        .check();
                    await page
                        .getByLabel('Phone Number (Optional)', { exact: true })
                        .fill('+15550000001');
                    await page
                        .locator('[data-test="save-alert-preferences-button"]')
                        .click();
                    assert.equal(
                        await page
                            .locator(
                                '[data-test="save-alert-preferences-button"]',
                            )
                            .isDisabled(),
                        true,
                    );
                    const call = await page.evaluate(() =>
                        __settingsSplitCalls.at(-1),
                    );
                    const data = canonical(call.data);
                    assert.deepEqual(
                        data.filter(([k]) => k === 'enabled'),
                        [['enabled', '0']],
                    );
                    assert.deepEqual(
                        data.filter(([k]) => k === 'daily_digest_subscribed'),
                        [['daily_digest_subscribed', '0']],
                    );
                    assert.equal(
                        data.find(([k]) => k === 'digest_mode')[1],
                        'daily_summary',
                    );
                    assert.equal(
                        data.find(([k]) => k === 'minimum_edge')[1],
                        '0',
                    );
                    assert.equal(
                        data.find(([k]) => k === 'digest_time')[1],
                        '10:00',
                    );
                    assert.equal(
                        data.find(([k]) => k === 'phone_number')[1],
                        '+15550000001',
                    );
                    if (before) outcomes.set('form', data);
                    else if (baseline)
                        assert.deepEqual(data, outcomes.get('form'));
                    await page.evaluate(() => {
                        __settingsSplitForm.processing = false;
                        __settingsSplitForm.recentlySuccessful = true;
                        __settingsSplitForm.errors = {
                            phone_number: 'Fixture invalid phone',
                        };
                    });
                    await page
                        .getByText('Fixture invalid phone', { exact: true })
                        .waitFor();
                    await page.getByText('Saved.', { exact: true }).waitFor();
                    await compare(page, 'alerts-form-result', before);
                }
                if (kind === 'alerts' && variant === 'defaults') {
                    await page
                        .getByRole('button', {
                            name: 'Enable Push On This Device',
                            exact: true,
                        })
                        .click();
                    await page
                        .getByText(
                            'Push notifications are enabled for this device.',
                            { exact: true },
                        )
                        .waitFor();
                    assert.equal(
                        await page
                            .getByRole('checkbox', {
                                name: 'Push Notifications',
                                exact: true,
                            })
                            .isChecked(),
                        true,
                    );
                    assert.equal(
                        await page.evaluate(
                            () =>
                                __settingsSplitFetchCalls[0].headers[
                                    'X-CSRF-TOKEN'
                                ],
                        ),
                        'fixture-csrf',
                    );
                    await page
                        .getByRole('button', {
                            name: 'Send Test Push',
                            exact: true,
                        })
                        .click();
                    await page
                        .getByText('Fixture push success', { exact: true })
                        .waitFor();
                    await page
                        .getByRole('button', {
                            name: 'Disable Push',
                            exact: true,
                        })
                        .click();
                    await page
                        .getByText(
                            'Push notifications were disabled for this device.',
                            { exact: true },
                        )
                        .waitFor();
                    assert.equal(
                        await page.evaluate(
                            () => __settingsSplitFetchCalls.at(-1).method,
                        ),
                        'DELETE',
                    );
                }
                assert.deepEqual(errors, []);
                console.log(
                    `${kind} ${variant}: ${before ? 'baseline' : 'passed'}`,
                );
                await page.close();
            }
    console.log(`Exact screenshot comparisons: ${comparisons}`);
} finally {
    await browser?.close();
    await server.close();
}
