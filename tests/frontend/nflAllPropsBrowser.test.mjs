import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, nextTick } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server;
let component;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [vue()],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
    });
    component = (
        await server.ssrLoadModule(
            '/resources/js/components/player-props/NflAllPropsBrowser.vue',
        )
    ).default;
});
after(async () => {
    await server?.close();
});
const fixture = () => ({
    data: [
        {
            id: 1,
            player_name: 'Unscored Receiver',
            market: 'player_receptions',
            line: 0,
            over_price: 150,
            under_price: null,
            bookmaker: 'draftkings',
            recommendation: { side: null },
            fetched_at: '2020-01-01T00:00:00Z',
            game: {
                status: 'STATUS_SCHEDULED',
                away_team: { abbreviation: 'SEA' },
                home_team: { abbreviation: 'KC' },
            },
        },
    ],
    meta: { pagination: { total: 821, current_page: 1, last_page: 17 } },
});
async function mount() {
    let bindings;
    const wrapper = {
        ...component,
        setup(props, context) {
            bindings ??= component.setup(props, context);
            return bindings;
        },
    };
    const props = {
        date: '2026-10-25',
        game: '1811',
        market: 'player_receptions',
    };
    await renderToString(createSSRApp(wrapper, props));
    await bindings.load();
    await new Promise((resolve) => setImmediate(resolve));
    return {
        bindings,
        html: () => renderToString(createSSRApp(wrapper, props)),
    };
}
test('all props preserve unscored and one-sided quotes, show server totals, and paginate beyond the board cap', async () => {
    const previous = globalThis.fetch;
    const requests = [];
    globalThis.fetch = async (url) => {
        requests.push(String(url));
        return new Response(JSON.stringify(fixture()), {
            headers: { 'Content-Type': 'application/json' },
        });
    };
    try {
        const { bindings, html } = await mount();
        const result = await html();
        assert.match(result, /821 stored quotes/);
        assert.match(result, /Unscored Receiver/);
        assert.match(result, /No recommendation/);
        assert.match(result, /Under unavailable/);
        assert.match(result, /\+150/);
        assert.match(result, /Stale stored quote/);
        assert.match(result, /receptions · 0/);
        const query = new URL(requests[0], 'https://example.test').searchParams;
        assert.equal(query.get('date'), '2026-10-25');
        assert.equal(query.get('game_id'), '1811');
        assert.equal(query.get('market'), 'player_receptions');
        bindings.movePage(2);
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(
            new URL(requests.at(-1), 'https://example.test').searchParams.get(
                'page',
            ),
            '2',
        );
        bindings.search.value = 'Receiver';
        bindings.scheduleSearch();
        await nextTick();
        await new Promise((resolve) => setTimeout(resolve, 350));
        assert.equal(
            new URL(requests.at(-1), 'https://example.test').searchParams.get(
                'search',
            ),
            'Receiver',
        );
        assert.equal(
            new URL(requests.at(-1), 'https://example.test').searchParams.get(
                'page',
            ),
            '1',
        );
    } finally {
        globalThis.fetch = previous;
    }
});
test('failed all-props requests expose retry without claiming no quotes exist', async () => {
    const previous = globalThis.fetch;
    globalThis.fetch = async () =>
        new Response('{}', {
            status: 500,
            headers: { 'Content-Type': 'application/json' },
        });
    try {
        const { bindings, html } = await mount();
        assert.match(await html(), /Unable to load all props/);
        assert.match(await html(), /Retry/);
        assert.doesNotMatch(await html(), /No stored quotes match/);
        globalThis.fetch = async () =>
            new Response(JSON.stringify(fixture()), {
                headers: { 'Content-Type': 'application/json' },
            });
        await bindings.load();
        assert.match(await html(), /Unscored Receiver/);
    } finally {
        globalThis.fetch = previous;
    }
});
