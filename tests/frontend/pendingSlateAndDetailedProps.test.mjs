import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { readFileSync } from 'node:fs';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h, ref } from 'vue';
import { renderToString } from 'vue/server-renderer';
import ts from 'typescript';
import { harness, queue, flush } from './fixtures/boardCleanupHarness.mjs';
let env, server, propsModule, hero, results;
before(async () => {
    env = await harness();
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'consumer-boundaries',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useNflMatchupBoard.ts'))
                        return 'export const useNflMatchupBoardContext=()=>globalThis.__pendingBoardContext;';
                    if (id.endsWith('/components/nfl/NflMatchupCard.vue'))
                        return '<script setup>defineProps(["prediction"]);defineEmits(["select"]);</script><template><button @click="$emit(\'select\',prediction)">select-stored-{{prediction.id}}</button></template>';
                    if (id.endsWith('/platform/index.ts'))
                        return 'export {Link} from "/tests/frontend/fixtures/frontendCleanupInertia.mjs";';
                },
            },
            vue(),
        ],
        resolve: { alias: { '@': process.cwd() + '/resources/js' } },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    propsModule = await server.ssrLoadModule(
        '/resources/js/composables/useSportDetailedPageProps.ts',
    );
    hero = (
        await server.ssrLoadModule(
            '/resources/js/components/game-page/MatchupHero.vue',
        )
    ).default;
    results = (
        await server.ssrLoadModule(
            '/resources/js/components/nfl/NflMatchupBoardResults.vue',
        )
    ).default;
});
after(async () => {
    await env?.server.close();
    await server?.close();
});
const render = (c, p) =>
    renderToString(createSSRApp({ render: () => h(c, p) }));
async function board(t) {
    const seasons = queue(),
        dates = queue(),
        boards = queue();
    const x = await env.mount(t, 'useNflMatchupBoard', {
        predictions: {
            availableSeasons: seasons.run,
            availableDates: dates.run,
            index: boards.run,
        },
    });
    seasons.calls[0].resolve({ data: [2026] });
    await flush();
    dates.calls[0].resolve({ data: ['2026-11-06', '2026-11-07'] });
    await flush();
    boards.calls[0].resolve({
        data: [
            {
                id: 71,
                game: { game_date: '2026-11-06', short_name: 'OLD @ SLATE' },
            },
        ],
    });
    await flush();
    return { ...x, boards };
}
function context(s) {
    return Object.fromEntries(
        Object.entries(s).map(([k, v]) => [
            k,
            v && typeof v === 'object' && 'value' in v ? v.value : v,
        ]),
    );
}
test('different-date pending request removes old selectable results before rendering new heading', async (t) => {
    const x = await board(t);
    const old = x.state.selectedDate.value;
    x.state.selectMatchup(x.state.predictions.value[0]);
    x.state.selectedDate.value =
        old === '2026-11-07' ? '2026-11-06' : '2026-11-07';
    assert.equal(x.state.detailOpen.value, false);
    globalThis.__pendingBoardContext = context(x.state);
    const html = await render(results);
    assert.doesNotMatch(html, /select-stored-71/);
    assert.equal(x.state.predictions.value.length, 0);
    x.boards.calls[1].resolve({ data: [] });
    await flush();
});
test('same-query pending refresh retains selectable stored cards and marks failed refresh stale', async (t) => {
    const x = await board(t);
    const pending = x.state.refreshBoard();
    assert.equal(x.state.predictions.value[0].id, 71);
    globalThis.__pendingBoardContext = context(x.state);
    assert.match(await render(results), /select-stored-71/);
    x.boards.calls[1].reject(Error('synthetic refresh unavailable'));
    await pending;
    assert.equal(x.state.boardStale.value, true);
});
const options = (extra = {}) => ({
    title: 'Away @ Home',
    breadcrumbs: [],
    loading: false,
    awayTeam: { id: 1, abbreviation: 'AWY', name: 'Away' },
    homeTeam: { id: 2, abbreviation: 'HOM', name: 'Home' },
    game: {
        id: 9,
        status: 'STATUS_SCHEDULED',
        game_date: '2026-11-06',
        starts_at: null,
    },
    gameStatus: 'STATUS_SCHEDULED',
    formatDate: () => '-',
    config: { teamLink: () => '/cfb/predictions' },
    ...extra,
});
function cfbLinkPolicy() {
    const file = readFileSync(
        'resources/js/composables/useCfbDetailedGamePage.ts',
        'utf8',
    );
    const source = ts.createSourceFile(
        'cfb.ts',
        file,
        ts.ScriptTarget.Latest,
        true,
    );
    let found = [];
    function walk(n) {
        if (
            ts.isPropertyAssignment(n) &&
            n.name.getText(source) === 'linkTeams'
        )
            found.push(n.initializer.kind);
        ts.forEachChild(n, walk);
    }
    walk(source);
    assert.deepEqual(found, [ts.SyntaxKind.FalseKeyword]);
    return false;
}
test('actual CFB caller false team-link policy survives adapter into compiled hero', async () => {
    const p = propsModule.useSportDetailedPageProps(
        options({ linkTeams: cfbLinkPolicy() }),
    ).value;
    const html = await render(hero, p);
    assert.doesNotMatch(html, /href="\/cfb\/predictions"/);
    assert.equal(p.linkTeams, false);
});
test('default and explicit true team links remain navigable', async () => {
    for (const extra of [{}, { linkTeams: true }, { linkTeams: ref(true) }]) {
        const p = propsModule.useSportDetailedPageProps(options(extra)).value;
        assert.equal(p.linkTeams, true);
        assert.match(await render(hero, p), /href="\/cfb\/predictions"/);
    }
});
for (const field of [
    'awayScore',
    'homeScore',
    'awayStarterRating',
    'homeStarterRating',
    'awayStarterConfidence',
    'homeStarterConfidence',
])
    test(`adapter preserves raw known zero ${field} and unknown null`, () => {
        assert.equal(
            propsModule.useSportDetailedPageProps(options({ [field]: 0 }))
                .value[field],
            0,
        );
        assert.equal(
            propsModule.useSportDetailedPageProps(options({ [field]: null }))
                .value[field],
            null,
        );
        assert.equal(
            propsModule.useSportDetailedPageProps(options({ [field]: ref(0) }))
                .value[field],
            0,
        );
    });
