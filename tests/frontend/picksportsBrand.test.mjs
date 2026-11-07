import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createHomepageTestServer } from './fixtures/homepageTestServer.mjs';
let server, Account, Brand, presentation;
before(async () => {
    server = await createHomepageTestServer();
    Account = (
        await server.ssrLoadModule(
            '/resources/js/components/brand/PickSportsAccountButton.vue',
        )
    ).default;
    Brand = (
        await server.ssrLoadModule(
            '/resources/js/components/brand/PickSportsBrand.vue',
        )
    ).default;
    presentation = await server.ssrLoadModule(
        '/resources/js/lib/homePresentation.ts',
    );
});
after(() => server.close());
test('PickSports account buttons preserve access destinations and accessible link semantics', async () => {
    for (const [authenticated, canRegister, url, label] of [
        [false, false, 'login', 'Log in'],
        [false, true, 'register', 'Create account'],
        [true, true, 'dashboard', 'Open dashboard'],
    ]) {
        const html = await renderToString(
            createSSRApp(Account, { authenticated, canRegister }),
        );
        assert.match(html, new RegExp(`href="/${url}"`));
        assert.ok(html.includes(label));
        assert.doesNotMatch(html, /<button/);
        assert.match(html, /data-slot="button"/);
    }
    const compact = await renderToString(
        createSSRApp(Account, {
            authenticated: true,
            canRegister: true,
            compact: true,
        }),
    );
    assert.match(compact, />Dashboard/);
});
test('PickSports brand keeps its home destination and accessible name in compact navigation', async () => {
    for (const compact of [true, false]) {
        const html = await renderToString(createSSRApp(Brand, { compact }));
        assert.match(html, /aria-label="PickSports home"/);
        assert.match(html, /href="\/"/);
        assert.match(html, /PickSports/);
    }
});
test('PickSports performance formatting retains the existing percentage and sample rules', () => {
    assert.equal(presentation.formatPercent(12.34, true), '12.3%');
    assert.equal(presentation.formatPercent(0, false), 'Pending');
    assert.equal(presentation.formatSignedPercent(3.925, true), '+3.92%');
    assert.equal(presentation.formatSignedPercent(-3.925, true), '-3.92%');
    assert.equal(presentation.formatSignedPercent(null, true), 'Pending');
    assert.equal(presentation.formatSignedPercent(99, false), 'Pending');
});
