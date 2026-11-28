import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';
import { createSSRApp, h, provide, ref } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server, helpers, keys, components;
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
    helpers = await server.ssrLoadModule(
        '/resources/js/lib/futuresPresentation.ts',
    );
    keys = {};
    components = {};
    for (const [sport, name] of [
        ['nba', 'NbaFutures'],
        ['mlb', 'MlbFutures'],
        ['wcbb', 'WcbbForecast'],
        ['cbb', 'CbbForecast'],
    ]) {
        const mod = await server.ssrLoadModule(
            `/resources/js/composables/use${name}.ts`,
        );
        keys[sport] =
            mod[
                `${sport}${sport === 'nba' || sport === 'mlb' ? 'Futures' : 'Forecast'}Key`
            ];
        components[sport] = (
            await server.ssrLoadModule(
                `/resources/js/components/predictions/${sport === 'cbb' ? 'CbbForecastField' : sport === 'wcbb' ? 'WcbbForecastTeams' : `${name}Forecasts`}.vue`,
            )
        ).default;
    }
});
after(async () => server?.close());
async function render(sport, row) {
    const state = {
        ...helpers,
        topByMake: ref([row]),
        statusTag: () => 'High probability',
        regionalField: ref([{ region: 'East', teams: [row] }]),
        firstFourTeams: ref([]),
    };
    return renderToString(
        createSSRApp({
            setup() {
                provide(keys[sport], state);
                return () => h(components[sport]);
            },
        }),
    );
}
for (const sport of ['nba', 'mlb', 'wcbb']) {
    test(`${sport}: championship comparisons are identified and never claim betting EV`, async () => {
        const html = await render(sport, {
            id: 1,
            team_id: 1,
            team: null,
            playoff_make_probability: 0.8,
            tournament_make_probability: 0.8,
            play_in_tournament_probability: 0.2,
            auto_bid_probability: 0.2,
            at_large_probability: 0.6,
            first_four_probability: 0.1,
            market_edge: {
                has_edge: true,
                edge_probability: 0.3,
                edge_percent_points: 30,
            },
        });
        assert.doesNotMatch(html, /\+EV|-EV/);
        assert.match(html, /Title model difference/);
        assert.match(html, /\+30\.0pp/);
    });
}
test('confirmed CBB field keeps unknown title probabilities distinct from observed zero', async () => {
    const row = {
        id: 1,
        team_id: 1,
        team: null,
        actual_seed: 1,
        champion_probability: null,
    };
    assert.match(await render('cbb', row), /Unavailable/);
    assert.doesNotMatch(await render('cbb', row), /0\.00%/);
    assert.match(
        await render('cbb', { ...row, champion_probability: 0 }),
        /0\.00%/,
    );
});

test('stored quote rendering exposes stale state and observation time to every viewer', async () => {
    const Quote = (
        await server.ssrLoadModule(
            '/resources/js/components/predictions/FuturesQuote.vue',
        )
    ).default;
    const renderQuote = (quote) =>
        renderToString(createSSRApp({ render: () => h(Quote, { quote }) }));
    const stale = await renderQuote({
        price: 400,
        fetched_at: '2026-11-20T12:00:00Z',
        stale: true,
    });
    assert.match(stale, /\+400/);
    assert.match(stale, /Stored quote/);
    assert.match(stale, /Stale \/ unavailable comparison/);
    assert.match(stale, /datetime="2026-11-20T12:00:00.000Z"/);
    const missing = await renderQuote({
        price: 400,
        fetched_at: null,
        stale: true,
    });
    assert.match(missing, /Observation time unavailable/);
    const fresh = await renderQuote({
        price: -110,
        fetched_at: '2026-11-26T12:00:00Z',
        stale: false,
    });
    assert.doesNotMatch(fresh, /Stale \/ unavailable/);
    assert.match(fresh, /-110/);
});
