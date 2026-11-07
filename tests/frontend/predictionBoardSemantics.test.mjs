import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createSSRApp, h, ref } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { parse } from '@vue/compiler-dom';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { predictionBoardUi } from './fixtures/predictionBoardUi.mjs';

let server, Results, key, mapPrediction;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [vue()],
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    Results = (
        await server.ssrLoadModule(
            '/resources/js/components/predictions/PredictionBoardResults.vue',
        )
    ).default;
    ({ predictionBoardKey: key } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionBoard.ts',
    ));
    ({ mapV2Prediction: mapPrediction } = await server.ssrLoadModule(
        '/resources/js/lib/predictionBoardPresentation.ts',
    ));
});
after(async () => server?.close());
async function render(rows, overrides = {}) {
    const app = createSSRApp({ render: () => h(Results) });
    app.provide(key, {
        filterMode: ref('seasonWeek'),
        availableDates: ref([]),
        clearFilters() {},
        filteredPredictions: ref(
            rows.map((row) => ({ ...mapPrediction(row), id: null })),
        ),
        emptyStateTitle: ref('No predictions'),
        emptyStateDescription: ref('Try a different week.'),
        showEmptyClearAction: ref(true),
        gameHref: (row) => `/cfb/games/${row.game.id}`,
        loading: ref(false),
        error: ref(null),
        config: ref({ sport: 'cfb' }),
        ...overrides,
    });
    return renderToString(app);
}
function find(node, predicate) {
    if (predicate(node)) return node;
    for (const child of node.children ?? []) {
        const result = find(child, predicate);
        if (result) return result;
    }
}
test('a full weather-populated slate is a native list with one navigable matchup per item', async () => {
    const fixture = predictionBoardUi('cfb', 'seasonWeek', 'dense');
    const { data } = await fixture.api.predictions.index('cfb', {
        query: { page: 1 },
    });
    const html = await render(data);
    const list = find(
        parse(html),
        (node) =>
            node.tag === 'ul' &&
            node.props.some(
                (prop) =>
                    prop.name === 'aria-label' &&
                    prop.value?.content === 'Prediction matchups',
            ),
    );
    assert.ok(
        list,
        'matchup results need native list semantics so a large slate is not an article',
    );
    const items = list.children.filter((node) => node.type === 1);
    assert.equal(items.length, 69);
    assert.ok(items.every((node) => node.tag === 'li'));
    for (let id = 1; id <= 69; id++)
        assert.match(html, new RegExp(`href="/cfb/games/${id}"`));
    assert.equal(
        (html.match(/aria-label="Game weather forecast"/g) ?? []).length,
        69,
    );
    assert.equal(
        (html.match(/aria-label="Show analysis for/g) ?? []).length,
        69,
    );
    assert.match(html, /52.1°F/);
    assert.match(html, /58.9°F/);
});
test('loading and empty results preserve recovery states without an empty matchup list', async () => {
    const loading = await render([], { loading: ref(true) });
    assert.match(loading, /aria-label="Loading predictions"/);
    assert.doesNotMatch(loading, /aria-label="Prediction matchups"/);
    const empty = await render([]);
    assert.match(empty, /No predictions/);
    assert.match(empty, /Clear Filters/);
    assert.doesNotMatch(empty, /aria-label="Prediction matchups"/);
});
