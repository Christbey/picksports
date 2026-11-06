// Run against local fixtures; ADMIN_SETTINGS_BASELINE_REF enables exact screenshot comparisons.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { readFileSync } from 'node:fs';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const baseline = process.env.ADMIN_SETTINGS_BASELINE_REF;
const beforePaths = {
    AdminSettings: 'resources/js/pages/settings/Admin.vue',
    AdminBracketGroups: 'resources/js/components/admin/AdminBracketGroups.vue',
    AdminGroupCard: 'resources/js/components/admin/AdminGroupCard.vue',
    AdminGroupUserSearch:
        'resources/js/components/admin/AdminGroupUserSearch.vue',
    AdminFoundingUsers: 'resources/js/components/admin/AdminFoundingUsers.vue',
};
function beforeSource(name) {
    let source = execFileSync(
        'git',
        ['show', `${baseline}:${beforePaths[name]}`],
        { cwd: root, encoding: 'utf8' },
    );
    for (const key of Object.keys(beforePaths).filter(
        (key) => key !== 'AdminSettings',
    ))
        source = source.replaceAll(
            `@/components/admin/${key}.vue`,
            `@/components/admin/__Before${key}.vue`,
        );
    return source;
}
const manifest = JSON.parse(
    readFileSync(`${root}public/build/manifest.json`, 'utf8'),
);
const stylesheet = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-admin-settings-browser-vite',
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
            name: 'admin-settings-fixture',
            resolveId(id) {
                const name = id.match(/__Before(\w+)\.vue$/)?.[1];
                if (name && beforePaths[name])
                    return `${root}resources/js/components/admin/__Before${name}.vue`;
            },
            load(id) {
                const name = id.match(/__Before(\w+)\.vue$/)?.[1];
                if (name && beforePaths[name]) return beforeSource(name);
            },
            configureServer(vite) {
                vite.middlewares.use(
                    '/__admin-settings',
                    async (request, response) => {
                        const q = new URL(request.url, 'http://fixture')
                            .searchParams;
                        const scenario = q.get('scenario') || 'normal';
                        const component =
                            q.get('before') === '1'
                                ? '__BeforeAdminSettings'
                                : 'Admin';
                        response.setHeader('Content-Type', 'text/html');
                        response.end(
                            await vite.transformIndexHtml(
                                `/__admin-settings/${component}/${scenario}`,
                                `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${stylesheet}"></head><body><div id="app"></div><script type="module">
import {createApp} from 'vue';
import Page from '/resources/js/pages/settings/${component}.vue';
import {adminSettingsProps} from '/tests/frontend/fixtures/adminSettings.mjs';
globalThis.__adminSettingsCalls=[];globalThis.__adminSettingsForms=[];globalThis.__lookups=[];
globalThis.fetch=(url,options)=>new Promise((resolve,reject)=>__lookups.push({url,signal:options.signal,resolve:users=>resolve({ok:true,json:async()=>({users})}),reject}));
globalThis.__adminApp=createApp(Page,adminSettingsProps(${JSON.stringify(scenario)}));__adminApp.mount('#app');
</script></body></html>`,
                            ),
                        );
                    },
                );
            },
        },
        vue(),
    ],
    resolve: {
        alias: [
            {
                find: '@inertiajs/vue3',
                replacement: `${root}tests/frontend/fixtures/adminSettingsInertia.mjs`,
            },
            {
                find: '@/layouts/AppLayout.vue',
                replacement: `${root}tests/frontend/fixtures/adminLayout.mjs`,
            },
            {
                find: '@/layouts/settings/Layout.vue',
                replacement: `${root}tests/frontend/fixtures/adminLayout.mjs`,
            },
            { find: '@', replacement: `${root}resources/js` },
        ],
    },
    server: { host: '127.0.0.1', port: 0, hmr: false, ws: false, watch: null },
});
let browser;
const images = new Map();
async function imageHash(page) {
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
                new Promise((resolve) =>
                    requestAnimationFrame(() => requestAnimationFrame(resolve)),
                ),
        );
        const hash = createHash('sha256')
            .update(
                await page.screenshot({
                    animations: 'disabled',
                    fullPage: true,
                }),
            )
            .digest('hex');
        if (hash === previous) return hash;
        previous = hash;
    }
    throw new Error('Admin settings screenshot did not stabilize');
}
async function interactions(page) {
    const search = page.locator('#founding-user-search');
    await search.fill('Old user');
    await page.waitForFunction(() => __lookups.length === 1);
    await search.fill('');
    assert.equal(await page.evaluate(() => __lookups[0].signal.aborted), true);
    await page.evaluate(() =>
        __lookups[0].resolve([
            { id: 90, name: 'Stale User', email: 'stale@example.com' },
        ]),
    );
    assert.equal(
        await page.getByRole('option', { name: /Stale User/ }).count(),
        0,
    );
    await search.fill('New user');
    await page.waitForFunction(() => __lookups.length === 2);
    assert.equal(
        await page.evaluate(() => __lookups[1].url),
        '/settings/admin/founding-users/search?query=New+user',
    );
    await page.evaluate(() =>
        __lookups[1].resolve([
            { id: 91, name: 'New User', email: 'new@example.com' },
        ]),
    );
    await search.press('ArrowDown');
    await search.press('Enter');
    assert.equal(
        await page.locator('#founding-user-email').inputValue(),
        'new@example.com',
    );
    await page.waitForTimeout(300);
    assert.equal(await page.evaluate(() => __lookups.length), 2);
    await page
        .getByRole('button', { name: 'Grant Founding Access', exact: true })
        .click();
    assert.deepEqual(
        await page.evaluate(() => __adminSettingsCalls.at(-1).data),
        { email: 'new@example.com' },
    );
    await page.evaluate(() => __adminSettingsSuccess());
    assert.equal(await search.inputValue(), '');
    await page.locator('#founding-user-limit').fill('15');
    await page.getByRole('button', { name: 'Save Limit', exact: true }).click();
    assert.deepEqual(
        await page.evaluate(() => __adminSettingsCalls.at(-1).data),
        { limit: 15 },
    );
    await page.locator('#group-name').fill('New Pool');
    await page.locator('#group-season').fill('2025');
    await page
        .getByRole('button', { name: 'Create Group', exact: true })
        .click();
    assert.deepEqual(
        await page.evaluate(() => __adminSettingsCalls.at(-1).data),
        { name: 'New Pool', season: 2025 },
    );
    await page.evaluate(() => __adminSettingsSuccess());
    assert.equal(await page.locator('#group-name').inputValue(), '');
    await page.locator('#invite-group').selectOption('2');
    await page.locator('#invite-email').fill('guest@example.com');
    await page
        .getByRole('button', { name: 'Create Invite', exact: true })
        .click();
    assert.deepEqual(
        await page.evaluate(() => __adminSettingsCalls.at(-1).data),
        { group_id: 2, email: 'guest@example.com' },
    );
    await page.evaluate(() => __adminSettingsSuccess());
    assert.equal(await page.locator('#invite-email').inputValue(), '');
    const groupInputs = page.getByPlaceholder(
        'Search existing users by name or email',
    );
    await groupInputs.nth(0).fill('Alpha');
    await groupInputs.nth(1).fill('Bravo');
    await page.waitForFunction(() => __lookups.length === 4);
    assert.deepEqual(
        await page.evaluate(() => __lookups.slice(2).map((x) => x.url)),
        [
            '/settings/admin/groups/users/search?group_id=1&query=Alpha',
            '/settings/admin/groups/users/search?group_id=2&query=Bravo',
        ],
    );
    await page.evaluate(() => {
        __lookups[3].resolve([
            { id: 52, name: 'Bravo User', email: 'bravo@example.com' },
        ]);
        __lookups[2].resolve([
            { id: 51, name: 'Alpha User', email: 'alpha@example.com' },
        ]);
    });
    await groupInputs.nth(0).focus();
    await page
        .getByRole('option', { name: /Alpha User.*alpha@example.com/ })
        .click();
    assert.equal(
        await page
            .getByRole('button', { name: 'Add User', exact: true })
            .nth(0)
            .isEnabled(),
        true,
    );
    assert.equal(
        await page
            .getByRole('button', { name: 'Add User', exact: true })
            .nth(1)
            .isEnabled(),
        false,
    );
    await page
        .getByRole('button', { name: 'Add User', exact: true })
        .nth(0)
        .click();
    assert.deepEqual(
        await page.evaluate(() => __adminSettingsCalls.at(-1).data),
        { group_id: 1, user_id: 51 },
    );
    await page.evaluate(() => __adminSettingsSuccess());
    assert.equal(await groupInputs.nth(0).inputValue(), '');
    assert.equal(await groupInputs.nth(1).inputValue(), 'Bravo');
    await groupInputs.nth(1).focus();
    await page
        .getByRole('option', { name: /Bravo User.*bravo@example.com/ })
        .click();
    await page
        .getByRole('button', { name: 'Add User', exact: true })
        .nth(1)
        .click();
    assert.deepEqual(
        await page.evaluate(() => __adminSettingsCalls.at(-1).data),
        { group_id: 2, user_id: 52 },
    );
    await page
        .getByRole('button', { name: 'Create Join Link', exact: true })
        .click();
    assert.equal(
        await page.evaluate(() => __adminSettingsCalls.at(-1).url),
        '/settings/admin/groups/join-link',
    );
    const dialogs = [];
    page.on('dialog', async (dialog) => {
        dialogs.push(dialog.message());
        await dialog.accept();
    });
    await page.getByRole('button', { name: 'Remove', exact: true }).click();
    await page.getByRole('button', { name: 'Revoke', exact: true }).click();
    assert.deepEqual(dialogs, [
        'Remove Member User from this group?',
        'Revoke founding access for this user?',
    ]);
    assert.deepEqual(
        await page.evaluate(() =>
            __adminSettingsCalls.slice(-2).map((x) => x.data),
        ),
        [{ group_id: 1, user_id: 11 }, { user_id: 7 }],
    );
    await page
        .getByRole('button', { name: 'Copy Link', exact: true })
        .first()
        .click();
    await page.getByRole('button', { name: 'Copied', exact: true }).waitFor();
    assert.equal(
        await page.evaluate(() => navigator.clipboard.readText()),
        'https://example.com/join/fixture',
    );
    await page.evaluate(() => {
        __adminSettingsForms[0].errors.email = 'Email validation fixture';
        __adminSettingsForms[0].processing = true;
    });
    await page.getByText('Email validation fixture', { exact: true }).waitFor();
    assert.equal(
        await page
            .getByRole('button', { name: 'Granting...', exact: true })
            .isDisabled(),
        true,
    );
    const external = page.locator('a').filter({ hasText: 'Dashboard Payload' });
    assert.equal(await external.getAttribute('target'), '_blank');
    assert.equal(await page.locator('a[href="/admin/users"]').count(), 1);
    await search.fill('Closing');
    await page.waitForFunction(() => __lookups.length === 5);
    await page.evaluate(() => __adminApp.unmount());
    assert.equal(await page.evaluate(() => __lookups[4].signal.aborted), true);
    await page.evaluate(() =>
        __lookups[4].resolve([
            { id: 99, name: 'Late', email: 'late@example.com' },
        ]),
    );
}
try {
    await server.listen();
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
        args: ['--disable-gpu', '--force-color-profile=srgb'],
    });
    const context = await browser.newContext({
        permissions: ['clipboard-read', 'clipboard-write'],
        timezoneId: 'America/Chicago',
    });
    for (const before of baseline ? [true, false] : [false]) {
        for (const scenario of ['normal', 'empty', 'disabled']) {
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(
                `http://127.0.0.1:${server.httpServer.address().port}/__admin-settings?before=${before ? 1 : 0}&scenario=${scenario}`,
            );
            await page
                .getByRole('heading', { name: 'Admin Settings', exact: true })
                .last()
                .waitFor();
            for (const width of [390, 1280]) {
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
                    const key = `${scenario}-${width}-${dark}`,
                        hash = await imageHash(page);
                    if (before) images.set(key, hash);
                    else if (baseline)
                        assert.equal(
                            hash,
                            images.get(key),
                            `UI changed: ${key}`,
                        );
                }
            }
            if (!before && scenario === 'normal') await interactions(page);
            if (!before && scenario === 'disabled')
                assert.equal(
                    await page
                        .getByRole('button', {
                            name: 'Grant Founding Access',
                            exact: true,
                        })
                        .isDisabled(),
                    true,
                );
            assert.deepEqual(errors, []);
            console.log(
                `${scenario}: ${before ? 'baseline' : 'visuals and controls passed'}`,
            );
            await page.close();
        }
    }
    await context.close();
} finally {
    await browser?.close();
    await server.close();
}
