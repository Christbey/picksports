import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createHomepageTestServer } from './fixtures/homepageTestServer.mjs';
import { homepagePerformance } from './fixtures/homepageDesign.mjs';
let server, Home;
before(async () => {
    server = await createHomepageTestServer();
    Home = (await server.ssrLoadModule('/resources/js/pages/Welcome.vue'))
        .default;
});
after(() => server.close());
async function render(
    kind = 'measured',
    authenticated = false,
    canRegister = true,
) {
    const app = createSSRApp(Home, {
        performance: homepagePerformance(kind),
        canRegister,
    });
    app.config.globalProperties.$page = {
        props: { auth: { user: authenticated ? { id: 1 } : null } },
    };
    return renderToString(app);
}
test('homepage preserves measured performance values, samples and methodology links', async () => {
    const html = await render();
    for (const text of [
        '62.8%',
        '61.0%',
        '+3.92%',
        '7.25',
        '785-465 across 1,250 graded predictions',
        '61-39 from 100 predictions',
        '80 priced pregame-safe settled bets',
    ])
        assert.ok(html.includes(text), text);
    assert.match(html, /href="\/performance"/);
    assert.match(html, /aria-label="All-time winner accuracy"/);
});
test('homepage keeps missing and unpriced samples pending instead of claiming zero performance', async () => {
    for (const kind of ['empty', 'unpriced', 'null']) {
        const html = await render(kind);
        assert.match(html, /Pending/);
        if (kind === 'empty') {
            assert.match(html, /Awaiting a graded prediction sample/);
            assert.doesNotMatch(html, /role="progressbar"|0\.0%|0\.00%/);
        }
        if (kind === 'unpriced')
            assert.match(html, /No settled qualifying bets yet/);
    }
});
test('homepage preserves zero, negative returns and the legacy priced-bet fallback', async () => {
    const zero = await render('zero');
    assert.match(zero, /0\.0%/);
    assert.match(zero, /0\.00%/);
    assert.match(zero, />0\.00</);
    const negative = await render('negative');
    assert.match(negative, /-3\.92%/);
    const legacy = await render('legacy');
    assert.match(legacy, /100 priced pregame-safe settled bets/);
});
test('homepage preserves guest registration, login-only and authenticated dashboard destinations', async () => {
    const guest = await render();
    assert.match(guest, /href="\/register"/);
    assert.match(guest, /href="\/login"/);
    assert.doesNotMatch(guest, /href="\/dashboard"/);
    const closed = await render('measured', false, false);
    assert.doesNotMatch(closed, /href="\/register"/);
    assert.match(closed, /href="\/login"/);
    const signedIn = await render('measured', true);
    assert.match(signedIn, /href="\/dashboard"/);
    assert.doesNotMatch(signedIn, /href="\/(login|register)"/);
});
test('homepage keeps all seven public sport destinations and existing legal information', async () => {
    const html = await render();
    for (const sport of ['mlb', 'nfl', 'nba', 'wnba', 'cfb', 'cbb', 'wcbb'])
        assert.match(html, new RegExp(`href="/${sport}"`));
    for (const route of ['terms', 'privacy', 'responsible-gambling'])
        assert.match(html, new RegExp(`href="/${route}"`));
    assert.match(html, /Gambling involves risk of loss/);
    assert.match(html, /Not affiliated with any professional/);
    assert.doesNotMatch(html, /GATED|TRACKED|LIVE|guaranteed returns/);
});
test('homepage retains SEO metadata and uses navigable landmarks and non-nested button links', async () => {
    const html = await render();
    assert.match(
        html,
        /data-head-title="PickSports - Model-Based Sports Decisions"/,
    );
    assert.match(html, /name="description"/);
    assert.match(html, /property="og:description"/);
    assert.match(html, /name="twitter:description"/);
    assert.equal((html.match(/<h1\b/g) || []).length, 1);
    assert.equal((html.match(/<main\b/g) || []).length, 1);
    assert.equal((html.match(/<footer\b/g) || []).length, 1);
    assert.match(html, /href="#main-content"/);
    assert.match(html, /id="sports"/);
    assert.match(html, /id="method"/);
    assert.doesNotMatch(html, /<button\b/);
    assert.match(
        html,
        /data-slot="button"[^>]*href=|href="\/register"[^>]*data-slot="button"/,
    );
});
