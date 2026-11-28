import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, ref } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server, Results, Header, usePredictionBoardView;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            vue(),
            {
                name: 'board-scope-context',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/usePredictionBoard.ts'))
                        return 'export const usePredictionBoardContext = () => globalThis.__boardScopeState;';
                },
            },
        ],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: [
                {
                    find: '@/components/predictions/UnifiedPredictionCard.vue',
                    replacement: fileURLToPath(
                        new URL(
                            './fixtures/predictionBoardCandidate.mjs',
                            import.meta.url,
                        ),
                    ),
                },
                {
                    find: '@',
                    replacement: fileURLToPath(
                        new URL('../../resources/js', import.meta.url),
                    ),
                },
            ],
        },
    });
    ({ default: Results } = await server.ssrLoadModule(
        '/resources/js/components/predictions/PredictionBoardResults.vue',
    ));
    ({ default: Header } = await server.ssrLoadModule(
        '/resources/js/components/predictions/PredictionBoardHeader.vue',
    ));
    ({ usePredictionBoardView } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionBoardView.ts',
    ));
});
after(async () => {
    delete globalThis.__boardScopeState;
    await server?.close();
});
const candidate = {
    id: 1,
    value_signal: { has_playable_value: true },
    game: {
        id: 1,
        home_team: { abbreviation: 'HOME' },
        away_team: { abbreviation: 'AWAY' },
    },
};
function state(search, rows = [candidate]) {
    const config = { sport: 'wnba', title: 'Synthetic board' };
    const input = {
        predictions: ref(rows),
        filterMode: ref('date'),
        searchQuery: ref(search),
        betViewMode: ref('recommended'),
        availableDates: ref(['2026-11-27']),
        selectedDate: ref('2026-11-27'),
        seasonType: ref(''),
        week: ref(''),
        formatDateLabel: (value) => value,
    };
    const view = usePredictionBoardView({ config }, input);
    globalThis.__boardScopeState = {
        ...input,
        ...view,
        config: ref(config),
        loading: ref(false),
        error: ref(null),
        clearFilters() {},
        gameHref: () => '/wnba/games/1',
    };
    return view;
}
test('empty search results do not deny the recommended candidate already present on this page', async () => {
    const view = state('NO-MATCH');
    assert.equal(view.recommendedBetCount.value, 1);
    assert.equal(view.filteredPredictions.value.length, 0);
    const html = await renderToString(createSSRApp(Results));
    assert.match(html, /No recommended bets match these filters/);
    assert.doesNotMatch(
        html,
        /model does not currently have a playable edge on this slate/,
    );
});
test('a page without recommendations does not assert a whole-slate model conclusion', async () => {
    state('', []);
    const html = await renderToString(createSSRApp(Results));
    assert.match(html, /No recommended bets match these filters/);
    assert.doesNotMatch(
        html,
        /model does not currently have a playable edge on this slate/,
    );
});
test('the existing stored candidate remains visible when the search filter is cleared', async () => {
    const view = state('');
    assert.equal(view.recommendedBetCount.value, 1);
    assert.equal(view.filteredPredictions.value.length, 1);
    const html = await renderToString(createSSRApp(Results));
    assert.match(html, /data-testid="stored-candidate"/);
    assert.doesNotMatch(html, /No recommended bets match/);
});

async function countHeader(error, total) {
    state('', []);
    Object.assign(globalThis.__boardScopeState, {
        error: ref(error),
        meta: ref(total === null ? null : { total }),
        selectedSeason: ref('2026'),
    });
    return renderToString(createSSRApp(Header));
}
test('failed initial board load keeps an unknown count distinct from zero games', async () => {
    const html = await countHeader('Synthetic access failure', null);
    assert.doesNotMatch(html, /0 games/);
    assert.match(html, /count unavailable/i);
});
test('failed scope change does not present the previous scope total as the current count', async () => {
    const html = await countHeader('Synthetic request failure', 8);
    assert.doesNotMatch(html, /8 games/);
    assert.match(html, /count unavailable/i);
});
test('a successful empty scope retains a genuine zero game count', async () => {
    assert.match(await countHeader(null, 0), /0 games/);
});
test('a successful known scope retains its observed game count', async () => {
    assert.match(await countHeader(null, 7), /7 games/);
});
