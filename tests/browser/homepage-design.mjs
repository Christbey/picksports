import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);
const root = fileURLToPath(new URL('../../', import.meta.url)),
    baseline = process.env.HOMEPAGE_BASELINE_REF;
const manifest = JSON.parse(
        readFileSync(root + 'public/build/manifest.json', 'utf8'),
    ),
    css = '/build/' + manifest['resources/js/app.ts'].css[0];
const server = await createServer({
    root,
    configFile: false,
    cacheDir: '/tmp/picksports-homepage-browser-vite',
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
            name: 'homepage-browser',
            enforce: 'pre',
            resolveId(id) {
                if (id.includes('__HomepageBefore'))
                    return root + 'resources/js/__HomepageBefore.vue';
                if (id.endsWith('/routes')) return 'virtual:homepage-routes';
            },
            load(id) {
                if (id.includes('__HomepageBefore'))
                    return execFileSync(
                        'git',
                        ['show', `${baseline}:resources/js/pages/Welcome.vue`],
                        { cwd: root, encoding: 'utf8' },
                    );
                if (id === 'virtual:homepage-routes')
                    return [
                        'dashboard',
                        'login',
                        'register',
                        'performance',
                        'terms',
                        'privacy',
                        'responsibleGambling',
                    ]
                        .map(
                            (name) =>
                                `export const ${name}=()=>({url:'/${name === 'responsibleGambling' ? 'responsible-gambling' : name}'});`,
                        )
                        .join('\n');
            },
            configureServer(vite) {
                vite.middlewares.use('/__homepage', async (req, res) => {
                    const q = new URL(req.url, 'http://fixture').searchParams,
                        before = q.get('before') === '1',
                        kind = q.get('kind') || 'measured',
                        auth = q.get('auth') === '1',
                        register = q.get('register') !== '0';
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            '/__homepage' + req.url,
                            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${css}"></head><body><div id="app"></div><script type="module">import {createApp,h,reactive,nextTick} from 'vue';import Home from '/resources/js/${before ? '__HomepageBefore.vue' : 'pages/Welcome.vue'}';import {homepagePerformance} from '/tests/frontend/fixtures/homepageDesign.mjs';globalThis.__homepageProps=reactive({performance:homepagePerformance(${JSON.stringify(kind)}),canRegister:${register}});globalThis.__homepagePage=reactive({props:{auth:{user:${auth ? '{id:1}' : 'null'}}}});const app=createApp({setup(){return()=>h(Home,__homepageProps);}});app.config.globalProperties.$page=__homepagePage;app.mount('#app');await nextTick();globalThis.__homepageReady=true;</script></body></html>`,
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
                    root + 'tests/frontend/fixtures/homepageInertia.mjs',
            },
            { find: '@', replacement: root + 'resources/js' },
        ],
    },
    server: {
        host: '127.0.0.1',
        port: 0,
        watch: { ignored: ['**/public/build/**', '**/frontend/dist/**'] },
    },
});
await server.listen();
let browser;
const previous = new Map();
let checks = 0;
const links = (page) =>
    page
        .locator('a[href]')
        .evaluateAll((nodes) =>
            [
                ...new Set(
                    nodes
                        .map((n) => n.getAttribute('href'))
                        .filter((h) => h && !h.startsWith('#')),
                ),
            ].sort(),
        );
async function checkOverflow(page) {
    const sizes = await page.evaluate(() => ({
        document: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    assert.ok(sizes.document <= sizes.viewport, JSON.stringify(sizes));
}
async function contrast(page) {
    const result = await page.evaluate(() => {
        const el = document.querySelector('.ps-theme'),
            style = getComputedStyle(el);
        function rgb(color) {
            const temp = document.createElement('span');
            temp.style.color = color;
            el.append(temp);
            const rgb = getComputedStyle(temp)
                .color.match(/[\d.]+/g)
                .slice(0, 3)
                .map(Number);
            temp.remove();
            return rgb;
        }
        function luminance(values) {
            return values
                .map((c) => {
                    c /= 255;
                    return c <= 0.04045
                        ? c / 12.92
                        : ((c + 0.055) / 1.055) ** 2.4;
                })
                .reduce(
                    (sum, c, i) => sum + c * [0.2126, 0.7152, 0.0722][i],
                    0,
                );
        }
        function ratio(a, b) {
            const x = luminance(rgb(style.getPropertyValue(a))),
                y = luminance(rgb(style.getPropertyValue(b)));
            return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
        }
        return {
            text: ratio('--foreground', '--background'),
            muted: ratio('--muted-foreground', '--background'),
            button: ratio('--primary-foreground', '--primary'),
            primary: ratio('--primary', '--background'),
        };
    });
    for (const [key, value] of Object.entries(result))
        assert.ok(value >= 4.5, `${key} contrast ${value}`);
}
try {
    browser = await chromium.launch({
        channel: process.env.PLAYWRIGHT_CHANNEL || undefined,
        headless: true,
        args: ['--disable-gpu', '--force-color-profile=srgb'],
    });
    for (const width of [1440, 768, 390, 320])
        for (const theme of ['light', 'dark'])
            for (const scenario of [
                { kind: 'measured', auth: false, register: true },
                { kind: 'empty', auth: false, register: false },
                { kind: 'negative', auth: true, register: true },
            ])
                for (const before of baseline &&
                width === 1440 &&
                theme === 'light'
                    ? [true, false]
                    : [false]) {
                    const key = `${scenario.kind}-${scenario.auth}-${scenario.register}`,
                        page = await browser.newPage({
                            viewport: { width, height: 960 },
                            colorScheme: theme,
                            reducedMotion: 'reduce',
                        }),
                        errors = [];
                    page.setDefaultTimeout(10000);
                    page.on('pageerror', (e) => errors.push(e.message));
                    await page.goto(
                        server.resolvedUrls.local[0] +
                            `__homepage?kind=${scenario.kind}&auth=${scenario.auth ? 1 : 0}&register=${scenario.register ? 1 : 0}&before=${before ? 1 : 0}&width=${width}&theme=${theme}`,
                    );
                    await page.waitForFunction(
                        () => globalThis.__homepageReady,
                    );
                    await page.evaluate(
                        (theme) =>
                            document.documentElement.classList.toggle(
                                'dark',
                                theme === 'dark',
                            ),
                        theme,
                    );
                    await page.evaluate(() => document.fonts.ready);
                    await page.mouse.move(0, 0);
                    const destinations = await links(page);
                    if (before) {
                        previous.set(key, destinations);
                        await page.close();
                        continue;
                    }
                    if (previous.has(key))
                        assert.deepEqual(destinations, previous.get(key));
                    await checkOverflow(page);
                    await contrast(page);
                    assert.equal(
                        await page
                            .locator('a button,button a,button button')
                            .count(),
                        0,
                    );
                    assert.equal(await page.locator('h1').count(), 1);
                    for (const sport of [
                        'mlb',
                        'nfl',
                        'nba',
                        'wnba',
                        'cfb',
                        'cbb',
                        'wcbb',
                    ])
                        assert.ok(destinations.includes('/' + sport));
                    if (scenario.kind === 'empty') {
                        assert.equal(
                            await page.getByTestId('hero-accuracy').innerText(),
                            'Pending',
                        );
                        assert.equal(
                            await page.getByRole('progressbar').count(),
                            0,
                        );
                    } else
                        assert.equal(
                            await page.getByTestId('hero-accuracy').innerText(),
                            '62.8%',
                        );
                    if (!scenario.auth) {
                        await page.keyboard.press('Tab');
                        assert.equal(
                            await page.evaluate(() =>
                                document.activeElement.textContent.trim(),
                            ),
                            'Skip to content',
                        );
                        await page.keyboard.press('Enter');
                        assert.equal(
                            await page.evaluate(
                                () => document.activeElement.id,
                            ),
                            'main-content',
                        );
                    }
                    if (width === 1440 || width === 390) {
                        await page.evaluate(() => window.scrollTo(0, 0));
                        await page.screenshot({
                            path: `/tmp/homepage-${width}-${theme}-${scenario.kind}.png`,
                            fullPage: true,
                            animations: 'disabled',
                        });
                        if (scenario.kind === 'measured')
                            await page.screenshot({
                                path: `/tmp/homepage-${width}-${theme}-hero.png`,
                                animations: 'disabled',
                            });
                    }
                    const nav = page.getByRole('navigation', {
                        name:
                            width >= 768
                                ? 'Primary navigation'
                                : 'Explore PickSports',
                        exact: true,
                    });
                    await nav
                        .getByRole('link', { name: 'Sports', exact: true })
                        .click();
                    assert.equal(new URL(page.url()).hash, '#sports');
                    assert.ok(
                        await page
                            .getByRole('heading', {
                                name: 'One platform. Seven sports.',
                            })
                            .isVisible(),
                    );
                    await nav
                        .getByRole('link', {
                            name: 'How it works',
                            exact: true,
                        })
                        .click();
                    assert.equal(new URL(page.url()).hash, '#method');
                    if (
                        width === 390 &&
                        theme === 'light' &&
                        scenario.kind === 'measured'
                    ) {
                        await page.evaluate(() => {
                            __homepageProps.canRegister = false;
                            __homepagePage.props.auth.user = { id: 1 };
                        });
                        await page
                            .getByRole('link', {
                                name: 'Dashboard',
                                exact: true,
                            })
                            .waitFor();
                        assert.equal(
                            await page.locator('a[href="/register"]').count(),
                            0,
                        );
                        await page.evaluate(() => {
                            __homepageProps.performance.roi.roi_percentage = -5;
                        });
                        await page
                            .getByTestId('performance-record')
                            .getByText('-5.00%', { exact: true })
                            .waitFor();
                    }
                    assert.deepEqual(errors, []);
                    checks++;
                    console.log(`${width} ${theme} ${key}: passed`);
                    await page.close();
                }
    console.log('Homepage browser scenarios passed:', checks);
} finally {
    await browser?.close();
    await server.close();
}
