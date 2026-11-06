// Local fixtures only. Set ADMIN_BASELINE_REF to compare PNG bytes with a previous revision.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { readFileSync } from 'node:fs';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url));
const baseline = process.env.ADMIN_BASELINE_REF;
const manifest = JSON.parse(
    readFileSync(`${root}public/build/manifest.json`, 'utf8'),
);
const stylesheet = '/build/' + manifest['resources/js/app.ts'].css[0];
const originals = baseline
    ? Object.fromEntries(
          ['PlayerPropExports', 'NflModelMonitoring'].map((name) => [
              name,
              execFileSync(
                  'git',
                  ['show', `${baseline}:resources/js/pages/Admin/${name}.vue`],
                  { cwd: root, encoding: 'utf8' },
              ),
          ]),
      )
    : {};
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-admin-browser-vite',
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
            name: 'admin-fixtures',
            resolveId(id) {
                if (id.includes('__BeforeAdmin'))
                    return `${root}resources/js/pages/Admin/${id.split('/').at(-1)}`;
            },
            load(id) {
                const name = id.match(/__BeforeAdmin(\w+)\.vue$/)?.[1];
                if (name) return originals[name];
            },
            configureServer(vite) {
                vite.middlewares.use(
                    '/__admin-pages',
                    async (request, response) => {
                        const query = new URL(request.url, 'http://fixture')
                            .searchParams;
                        const monitoring = query.get('kind') === 'monitor';
                        const component = monitoring
                            ? 'NflModelMonitoring'
                            : 'PlayerPropExports';
                        const prefix =
                            query.get('before') === '1' ? '__BeforeAdmin' : '';
                        response.setHeader('Content-Type', 'text/html');
                        response.end(
                            await vite.transformIndexHtml(
                                '/__admin-pages' + request.url,
                                `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${stylesheet}"></head><body><div id="app"></div><script type="module">
import {createApp} from 'vue';
import Page from '/resources/js/pages/Admin/${prefix}${component}.vue';
import {exportProps,monitorProps} from '/tests/frontend/fixtures/adminPages.mjs';
globalThis.__adminCalls=[];
globalThis.__adminRouter={get:(...args)=>__adminCalls.push({type:'get',args}),reload:options=>{__adminCalls.push({type:'reload',only:options.only});queueMicrotask(()=>options.onFinish?.());}};
const tab=${JSON.stringify(query.get('tab') || 'props')};
const props=${monitoring ? 'monitorProps()' : 'exportProps({activeTab:tab})'};
createApp(Page,props).mount('#app');
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
                replacement: `${root}tests/frontend/fixtures/adminInertia.mjs`,
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
const pngResults = new Map();
const screenshots = new Map();
async function stableScreenshot(page) {
    await page.mouse.move(0, 0);
    await page.addStyleTag({
        content:
            '*,*::before,*::after { transition: none !important; animation: none !important; caret-color: transparent !important; }',
    });
    await page.evaluate(() => document.fonts.ready);
    let previous;
    for (let frame = 0; frame < 5; frame++) {
        await page.evaluate(
            () =>
                new Promise((resolve) =>
                    requestAnimationFrame(() => requestAnimationFrame(resolve)),
                ),
        );
        const hash = createHash('sha256')
            .update(await page.screenshot({ animations: 'disabled' }))
            .digest('hex');
        if (hash === previous) return hash;
        previous = hash;
    }
    throw new Error('Admin screenshot did not stabilize');
}

try {
    await server.listen();
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL,
    });
    const url = () =>
        `http://127.0.0.1:${server.httpServer.address().port}/__admin-pages`;
    for (const before of baseline ? [true, false] : [false]) {
        const page = await browser.newPage({
            viewport: { width: 1280, height: 900 },
        });
        page.setDefaultTimeout(15000);
        const errors = [];
        page.on('pageerror', (error) => {
            errors.push(error.message);
            console.error('Browser error:', error.message);
        });
        await page.route('**/*', (route) =>
            new URL(route.request().url()).hostname === '127.0.0.1'
                ? route.continue()
                : route.abort(),
        );
        await page.addInitScript(() => {
            const blobs = new Map();
            const create = URL.createObjectURL.bind(URL);
            URL.createObjectURL = (blob) => {
                const url = create(blob);
                blobs.set(url, blob);
                return url;
            };
            const click = HTMLAnchorElement.prototype.click;
            globalThis.__adminDownloads = [];
            HTMLAnchorElement.prototype.click = function () {
                if (!this.download) return click.call(this);
                const name = this.download.replace(/-\d{13}\.png$/, '.png');
                const href = this.href;
                const captured = (async () => {
                    const blob =
                        blobs.get(href) ?? (await (await fetch(href)).blob());
                    const buffer = await blob.arrayBuffer();
                    const hash = [
                        ...new Uint8Array(
                            await crypto.subtle.digest('SHA-256', buffer),
                        ),
                    ]
                        .map((v) => v.toString(16).padStart(2, '0'))
                        .join('');
                    const image = await createImageBitmap(blob);
                    const dimensions = [image.width, image.height];
                    image.close();
                    return { hash, name, dimensions };
                })();
                globalThis.__adminDownloads.push(captured);
            };
        });
        async function download(button, key, dimensions) {
            const index = await page.evaluate(() => __adminDownloads.length);
            await button.click();
            await page.waitForFunction(
                (i) => __adminDownloads.length > i,
                index,
            );
            const value = await page.evaluate(
                (i) => __adminDownloads[i],
                index,
            );
            if (dimensions) assert.deepEqual(value.dimensions, dimensions);
            if (before) pngResults.set(key, value);
            else if (baseline)
                assert.deepEqual(
                    value,
                    pngResults.get(key),
                    `PNG changed: ${key}`,
                );
        }
        for (const tab of ['props', 'predictions', 'futures', 'tournament']) {
            await page.goto(
                `${url()}/exports-${tab}-${before}?tab=${tab}&before=${before ? 1 : 0}`,
                {
                    waitUntil: 'domcontentloaded',
                },
            );
            await page
                .getByRole('button', { name: 'Export PNG', exact: true })
                .waitFor();
            for (const width of [390, 1280]) {
                await page.setViewportSize({ width, height: 900 });
                for (const dark of [false, true]) {
                    await page.evaluate(
                        (value) =>
                            document.documentElement.classList.toggle(
                                'dark',
                                value,
                            ),
                        dark,
                    );
                    const key = `exports-${tab}-${width}-${dark}`;
                    const shot = await stableScreenshot(page);
                    if (before) screenshots.set(key, shot);
                    else if (baseline)
                        assert.deepEqual(
                            shot,
                            screenshots.get(key),
                            `Layout changed: ${key}`,
                        );
                }
            }
            await page.evaluate(() =>
                document.documentElement.classList.remove('dark'),
            );
            for (const [preset, dimensions] of [
                ['instagram', [1080, 1350]],
                ['facebook', [1200, 630]],
            ]) {
                await page.getByLabel('Export Size').selectOption(preset);
                for (const safe of [false, true]) {
                    await page.locator('#ad-safe').setChecked(safe);
                    await download(
                        page.getByRole('button', {
                            name: 'Export PNG',
                            exact: true,
                        }),
                        `${tab}-${preset}-${safe}`,
                        dimensions,
                    );
                }
            }
            for (const safe of [false, true]) {
                await page.locator('#ad-safe').setChecked(safe);
                await download(
                    page.getByRole('button', {
                        name: 'Export All Shown',
                        exact: true,
                    }),
                    `${tab}-table-${safe}`,
                );
                assert.equal(
                    await page
                        .getByRole('button', {
                            name: 'Export All Shown',
                            exact: true,
                        })
                        .isEnabled(),
                    true,
                );
            }
            console.log(
                `Admin ${tab} exports: presets, ad-safe modes, bulk PNGs ${before ? 'baseline' : 'passed'}`,
            );
        }
        for (const width of [390, 1280]) {
            await page.setViewportSize({ width, height: 900 });
            for (const dark of [false, true]) {
                await page.goto(
                    `${url()}/monitor-${width}-${dark}-${before}?kind=monitor&before=${before ? 1 : 0}`,
                    { waitUntil: 'domcontentloaded' },
                );
                await page.waitForFunction(
                    () =>
                        document.querySelector('#app')?.textContent.length > 0,
                );
                await page
                    .getByRole('tab', { name: 'Observations', exact: true })
                    .waitFor();
                await page.evaluate(
                    (value) =>
                        document.documentElement.classList.toggle(
                            'dark',
                            value,
                        ),
                    dark,
                );
                for (const name of [
                    'Observations',
                    'Evaluation',
                    'Signal Grades',
                    'Lineage',
                ]) {
                    await page.getByRole('tab', { name, exact: true }).click();
                    assert.ok(
                        (
                            await page
                                .getByRole('tab', { name, exact: true })
                                .getAttribute('class')
                        )
                            .split(' ')
                            .includes('bg-background'),
                    );
                    const key = `${width}-${dark}-${name}`;
                    // Screenshots check the rendered layout independently of generated ARIA IDs.
                    const shot = await stableScreenshot(page);
                    if (before) screenshots.set(key, shot);
                    else if (baseline)
                        assert.deepEqual(
                            shot,
                            screenshots.get(key),
                            `Layout changed: ${key}`,
                        );
                }
                const calls = await page.evaluate(
                    () => globalThis.__adminCalls,
                );
                assert.equal(
                    calls.filter(
                        (call) =>
                            call.type === 'reload' && call.only.length === 2,
                    ).length,
                    1,
                );
                console.log(
                    `NFL monitoring tabs and lazy load: ${width}px ${dark ? 'dark' : 'light'} ${before ? 'baseline' : 'passed'}`,
                );
            }
        }
        assert.deepEqual(errors, []);
        await page.close();
    }
} catch (error) {
    console.error(error);
    throw error;
} finally {
    await browser?.close();
    await server.close();
}
