import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { parse } from '@vue/compiler-dom';
import vue from '@vitejs/plugin-vue';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const baseline = process.env.CONTROLS_BASELINE_REF;
const oldPaths = {
    Tabs: 'components/ui/tabs/Tabs.vue',
    TabsList: 'components/ui/tabs/TabsList.vue',
    TabsTrigger: 'components/ui/tabs/TabsTrigger.vue',
    TabsContent: 'components/ui/tabs/TabsContent.vue',
    SheetContent: 'components/ui/sheet/SheetContent.vue',
    DialogContent: 'components/ui/dialog/DialogContent.vue',
};
function oldSearch() {
    const src = execFileSync(
        'git',
        [
            'show',
            `${baseline}:resources/js/components/admin/AdminFoundingUsers.vue`,
        ],
        { cwd: root, encoding: 'utf8' },
    );
    const template = src.slice(
        src.indexOf('<template>') + 10,
        src.lastIndexOf('</template>'),
    );
    let node;
    function walk(n) {
        if (
            n.type === 1 &&
            n.tag === 'div' &&
            n.props.some(
                (p) =>
                    p.name === 'class' &&
                    p.value?.content === 'relative flex-1',
            )
        )
            node = n;
        if (n.children) n.children.forEach(walk);
    }
    walk(parse(template));
    return `<script setup lang="ts">defineOptions({inheritAttrs:false});import {computed} from 'vue';const userSearch=defineModel<string>({default:''});const props=defineProps<{suggestions:{id:number;name:string;email:string}[];loading?:boolean}>();const emit=defineEmits<{select:[user:{id:number;name:string;email:string}]} >();const userSuggestions=computed(()=>props.suggestions);const isSearchingUsers=computed(()=>props.loading);const selectUserSuggestion=user=>emit('select',user);</script><template>${template.slice(node.loc.start.offset, node.loc.end.offset)}</template>`;
}
const manifest = JSON.parse(
    readFileSync(root + 'public/build/manifest.json', 'utf8'),
);
const stylesheet = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-shared-controls-browser-vite',
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
            name: 'shared-control-fixtures',
            resolveId(id) {
                if (id.endsWith('/SharedControls.vue'))
                    return root + 'resources/js/__SharedControlsFixture.vue';
                if (id === 'virtual:before-tabs') return id;
                if (
                    id === 'virtual:before-sheet' ||
                    id === 'virtual:before-dialog'
                )
                    return id;
                const name = id.match(/__BeforeControl(\w+)\.vue$/)?.[1];
                if (name) {
                    const path = oldPaths[name];
                    return path
                        ? root +
                              'resources/js/' +
                              path.slice(0, path.lastIndexOf('/')) +
                              `/__BeforeControl${name}.vue`
                        : root +
                              `tests/frontend/fixtures/__BeforeControl${name}.vue`;
                }
            },
            load(id) {
                if (id.endsWith('/__SharedControlsFixture.vue'))
                    return readFileSync(
                        root +
                            'tests/frontend/fixtures/SharedControls.vue.fixture',
                        'utf8',
                    );
                if (id === 'virtual:before-tabs')
                    return Object.keys(oldPaths)
                        .filter((x) => x.startsWith('Tabs'))
                        .map(
                            (name) =>
                                `export {default as ${name}} from '/tests/frontend/fixtures/__BeforeControl${name}.vue';`,
                        )
                        .join('\n');
                if (id === 'virtual:before-sheet')
                    return `export * from '/resources/js/components/ui/sheet/index.ts';export {default as SheetContent} from '/tests/frontend/fixtures/__BeforeControlSheetContent.vue';`;
                if (id === 'virtual:before-dialog')
                    return `export * from '/resources/js/components/ui/dialog/index.ts';export {default as DialogContent} from '/tests/frontend/fixtures/__BeforeControlDialogContent.vue';`;
                const name = id.match(/__BeforeControl(\w+)\.vue$/)?.[1];
                if (name === 'Fixture')
                    return readFileSync(
                        root +
                            'tests/frontend/fixtures/SharedControls.vue.fixture',
                        'utf8',
                    )
                        .replace('@/components/ui/tabs', 'virtual:before-tabs')
                        .replace(
                            '@/components/ui/sheet',
                            'virtual:before-sheet',
                        )
                        .replace(
                            '@/components/ui/dialog',
                            'virtual:before-dialog',
                        )
                        .replace(
                            '@/components/UserSearchInput.vue',
                            '/tests/frontend/fixtures/__BeforeControlSearch.vue',
                        );
                if (name === 'Search') return oldSearch();
                if (name && oldPaths[name])
                    return execFileSync(
                        'git',
                        ['show', `${baseline}:resources/js/${oldPaths[name]}`],
                        { cwd: root, encoding: 'utf8' },
                    );
            },
            configureServer(vite) {
                vite.middlewares.use('/__shared-controls', async (req, res) => {
                    const q = new URL(req.url, 'http://fixture').searchParams;
                    const before = q.get('before') === '1';
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            `/__shared-controls/${before ? 'before' : 'after'}`,
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${stylesheet}"></head><body><div id="app"></div><script type="module">import{createApp}from'vue';import Page from '/tests/frontend/fixtures/${before ? '__BeforeControlFixture' : 'SharedControls'}.vue';createApp(Page).mount('#app');globalThis.__controlsReady=true;</script></body></html>`,
                        ),
                    );
                });
            },
        },
        vue(),
    ],
    resolve: { alias: { '@': root + 'resources/js' } },
    server: { host: '127.0.0.1', port: 0, hmr: false, ws: false, watch: null },
});
let browser;
const images = new Map();
let comparisons = 0;
async function screenshot(page, key, before) {
    await page.mouse.move(0, 0);
    await page.addStyleTag({
        content:
            '*,*::before,*::after{transition:none!important;animation:none!important;caret-color:transparent!important}',
    });
    await page.evaluate(() => document.fonts.ready);
    let previous, png, hash;
    for (let i = 0; i < 5; i++) {
        await page.evaluate(
            () =>
                new Promise((r) =>
                    requestAnimationFrame(() => requestAnimationFrame(r)),
                ),
        );
        png = await page.screenshot({ fullPage: true, animations: 'disabled' });
        hash = createHash('sha256').update(png).digest('hex');
        if (hash === previous) break;
        previous = hash;
    }
    if (before) images.set(key, { hash, png });
    else if (baseline) {
        if (hash !== images.get(key).hash) {
            writeFileSync(
                '/tmp/shared-controls-before.png',
                images.get(key).png,
            );
            writeFileSync('/tmp/shared-controls-after.png', png);
        }
        assert.equal(hash, images.get(key).hash, `UI changed: ${key}`);
        comparisons++;
    }
}
async function keyboard(page) {
    const list = page.getByRole('tablist', { name: 'Example sections' }),
        overview = list.getByRole('tab', { name: 'Overview', exact: true }),
        details = list.getByRole('tab', { name: 'Details', exact: true }),
        history = list.getByRole('tab', { name: 'History', exact: true });
    await overview.focus();
    assert.equal(await overview.getAttribute('aria-selected'), 'true');
    assert.equal(await details.getAttribute('tabindex'), '-1');
    await page.keyboard.press('ArrowRight');
    assert.equal(
        await details.evaluate((e) => e === document.activeElement),
        true,
    );
    assert.equal(await overview.getAttribute('aria-selected'), 'true');
    assert.equal(await page.locator('#tab-events').textContent(), '');
    await page.keyboard.press('Enter');
    assert.equal(await details.getAttribute('aria-selected'), 'true');
    assert.equal(await page.locator('#tab-events').textContent(), 'details');
    const panel = page.getByRole('tabpanel', { name: 'Details', exact: true });
    assert.equal(
        await details.getAttribute('aria-controls'),
        await panel.getAttribute('id'),
    );
    assert.equal(
        await panel.getAttribute('aria-labelledby'),
        await details.getAttribute('id'),
    );
    await page.keyboard.press('Tab');
    assert.equal(
        await panel.evaluate((e) => e === document.activeElement),
        true,
    );
    await details.focus();
    await page.keyboard.press('End');
    assert.equal(
        await history.evaluate((e) => e === document.activeElement),
        true,
    );
    await page.keyboard.press('Space');
    assert.equal(await history.getAttribute('aria-selected'), 'true');
    await page.keyboard.press('ArrowRight');
    assert.equal(
        await overview.evaluate((e) => e === document.activeElement),
        true,
    );
    await page.keyboard.press('Home');
    assert.equal(
        await overview.evaluate((e) => e === document.activeElement),
        true,
    );
    await page.keyboard.press('Enter');
    await page.locator('#external-tab-update').click();
    assert.equal(await history.getAttribute('aria-selected'), 'true');
    const vertical = page.getByRole('tablist', { name: 'Vertical examples' });
    await vertical.getByRole('tab', { name: 'Vertical first' }).focus();
    await page.keyboard.press('ArrowDown');
    assert.equal(
        await vertical
            .getByRole('tab', { name: 'Vertical second' })
            .evaluate((e) => e === document.activeElement),
        true,
    );
    await page.keyboard.press('Enter');
    assert.equal(
        await page.getByRole('tabpanel', { name: 'Vertical second' }).count(),
        1,
    );
    assert.equal(await history.getAttribute('aria-selected'), 'true');
    const search = page.getByRole('combobox', { name: 'User Search' });
    await search.fill('Ma');
    await page.getByRole('listbox').waitFor();
    await page.keyboard.press('ArrowDown');
    let option = page.getByRole('option', { name: /Maria One/ });
    assert.equal(
        await search.getAttribute('aria-activedescendant'),
        await option.getAttribute('id'),
    );
    await page.keyboard.press('ArrowUp');
    assert.equal(
        await page
            .getByRole('option', { name: /Martin Two/ })
            .getAttribute('aria-selected'),
        'true',
    );
    await page.keyboard.press('Enter');
    assert.equal(
        await page.locator('#picked').textContent(),
        'martin@example.com',
    );
    assert.equal(
        (await page.locator('#tab-events').textContent()).includes(
            'form-submitted',
        ),
        false,
    );
    assert.equal(await search.getAttribute('aria-expanded'), 'false');
    await search.fill('Ma');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Escape');
    assert.equal(await search.getAttribute('aria-expanded'), 'false');
    assert.equal(await search.inputValue(), 'Ma');
    await page.keyboard.press('ArrowDown');
    assert.equal(await search.getAttribute('aria-expanded'), 'true');
    await page.keyboard.press('Tab');
    assert.equal(
        await page
            .locator('#after-search')
            .evaluate((e) => e === document.activeElement),
        true,
    );
    assert.equal(await page.getByRole('listbox').count(), 0);
    await page.setViewportSize({ width: 390, height: 150 });
    await search.fill('Ma');
    await search.press('ArrowUp');
    await page
        .waitForFunction(() => {
            const field = document.querySelector('[role="combobox"]');
            const id = field.getAttribute('aria-activedescendant');
            if (!id) return false;
            const rect = document.getElementById(id).getBoundingClientRect();
            // Scrolling rounds to physical pixels; DOM rectangles can remain fractional.
            return rect.top >= -1 && rect.bottom <= window.innerHeight + 1;
        })
        .catch(async (error) => {
            console.log(
                await page.evaluate(() => {
                    const field = document.querySelector('[role="combobox"]');
                    const id = field.getAttribute('aria-activedescendant');
                    const rect = id
                        ? document.getElementById(id)?.getBoundingClientRect()
                        : null;
                    return {
                        activeId: id,
                        expanded: field.getAttribute('aria-expanded'),
                        focus: document.activeElement?.id,
                        rect: rect
                            ? { top: rect.top, bottom: rect.bottom }
                            : null,
                        height: innerHeight,
                        scrollY,
                    };
                }),
            );
            throw error;
        });
    assert.equal(
        await search.evaluate((e) => e === document.activeElement),
        true,
    );
    await search.press('Escape');
    await page.setViewportSize({ width: 1280, height: 900 });
    const disclosure = page.getByRole('button', {
        name: 'Show supporting details',
        exact: true,
    });
    await disclosure.focus();
    await page.keyboard.press('Space');
    assert.equal(await disclosure.getAttribute('aria-expanded'), 'true');
    await page
        .getByText('Supporting details content', { exact: true })
        .waitFor();
    await page.keyboard.press('Enter');
    assert.equal(await disclosure.getAttribute('aria-expanded'), 'false');
    await page.locator('#drawer-opener').focus();
    await page.keyboard.press('Enter');
    let dialog = page.getByRole('dialog', { name: 'Example drawer' });
    await dialog.waitFor();
    assert.equal(
        await page
            .locator('#drawer-input')
            .evaluate((e) => e === document.activeElement),
        true,
    );
    await dialog.getByRole('button', { name: 'Close', exact: true }).focus();
    await page.keyboard.press('Tab');
    assert.equal(
        await page
            .locator('#drawer-input')
            .evaluate((e) => e === document.activeElement),
        true,
    );
    await page.keyboard.press('Shift+Tab');
    assert.equal(
        await dialog
            .getByRole('button', { name: 'Close', exact: true })
            .evaluate((e) => e === document.activeElement),
        true,
    );
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });
    assert.equal(
        await page
            .locator('#drawer-opener')
            .evaluate((e) => e === document.activeElement),
        true,
    );
    assert.equal(
        await page.locator('#drawer-events').textContent(),
        'open,close',
    );
    await page.locator('#dialog-opener').click();
    dialog = page.getByRole('dialog', { name: 'Example dialog' });
    await dialog.waitFor();
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });
    assert.equal(
        await page
            .locator('#dialog-opener')
            .evaluate((e) => e === document.activeElement),
        true,
    );
    await page.locator('#custom-dialog-opener').click();
    await dialog.waitFor();
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });
    assert.equal(
        await page
            .locator('#custom-target')
            .evaluate((e) => e === document.activeElement),
        true,
    );
    await page.locator('#drawer-opener').click();
    dialog = page.getByRole('dialog', { name: 'Example drawer' });
    await dialog.waitFor();
    await dialog
        .getByRole('button', { name: 'Remove opener', exact: true })
        .click();
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });
}
try {
    await server.listen();
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
        args: ['--disable-gpu', '--force-color-profile=srgb'],
    });
    const context = await browser.newContext();
    context.setDefaultTimeout(15000);
    for (const before of baseline && !process.env.CONTROLS_KEYBOARD_ONLY
        ? [true, false]
        : [false]) {
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(
            `http://127.0.0.1:${server.httpServer.address().port}/__shared-controls?before=${before ? 1 : 0}`,
        );
        await page.waitForFunction(() => globalThis.__controlsReady);
        for (const width of process.env.CONTROLS_KEYBOARD_ONLY
            ? []
            : [390, 1280]) {
            await page.setViewportSize({ width, height: 900 });
            for (const dark of [false, true]) {
                await page.evaluate(
                    (d) => document.documentElement.classList.toggle('dark', d),
                    dark,
                );
                await page.evaluate(() => {
                    if (document.activeElement instanceof HTMLElement)
                        document.activeElement.blur();
                });
                await screenshot(page, `initial-${width}-${dark}`, before);
                await page
                    .getByRole(before ? 'button' : 'tab', {
                        name: 'Details',
                        exact: true,
                    })
                    .click();
                await screenshot(page, `details-${width}-${dark}`, before);
                await page
                    .getByRole(before ? 'button' : 'tab', {
                        name: 'Overview',
                        exact: true,
                    })
                    .click();
                await page.locator('#founding-user-search').fill('Ma');
                await screenshot(page, `search-${width}-${dark}`, before);
                await page.locator('#founding-user-search').fill('');
                await page.locator('#drawer-opener').click();
                await page
                    .getByRole('dialog', { name: 'Example drawer' })
                    .waitFor();
                await screenshot(page, `drawer-${width}-${dark}`, before);
                await page.keyboard.press('Escape');
                await page
                    .getByRole('dialog', { name: 'Example drawer' })
                    .waitFor({ state: 'hidden' });
            }
        }
        if (!before) {
            await page.goto(
                `http://127.0.0.1:${server.httpServer.address().port}/__shared-controls?before=0`,
            );
            await page.waitForFunction(() => globalThis.__controlsReady);
            await keyboard(page);
        }
        assert.deepEqual(errors, []);
        console.log(
            before
                ? 'Baseline shared controls captured'
                : 'Keyboard interactions and focus restoration passed',
        );
        await page.close();
    }
    console.log(`Exact screenshot comparisons: ${comparisons}`);
    await context.close();
} finally {
    await browser?.close();
    await server.close();
}
