import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createRenderer, createSSRApp, h, nextTick, ref, reactive } from 'vue';
import { renderToString } from '@vue/server-renderer';
let server;
before(async () => {
    globalThis.__gameReviewPage = {
        url: '/nfl/games/1',
        props: { auth: { user: null }, subscription: {} },
    };
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'game-integrity-fixtures',
                enforce: 'pre',
                resolveId(id) {
                    if (id === '@inertiajs/vue3' || id === '/@game-inertia')
                        return '\0game-inertia';
                },
                load(id) {
                    if (id === '\0game-inertia')
                        return `import {h} from 'vue'; export const usePage=()=>globalThis.__gameReviewPage; export const router={}; export const Head={setup(){return()=>null;}}; export const Link={setup(_, {slots,attrs}){return()=>h('a',attrs,slots.default?.());}};`;
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client=()=>globalThis.__gameReviewApi;';
                    if (id.endsWith('/composables/useApiV2Resource.ts'))
                        return 'export const useApiV2Resource=()=>globalThis.__gameReviewResource;';
                },
            },
            vue(),
        ],
        resolve: {
            alias: {
                '@inertiajs/vue3': '/@game-inertia',
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
});
after(() => server?.close());
const flush = async () => {
    await new Promise((r) => setImmediate(r));
    await nextTick();
};
const pending = () => {
    let resolve;
    const promise = new Promise((r) => (resolve = r));
    return { promise, resolve };
};
const renderer = createRenderer({
    createElement: () => ({}),
    createText: () => ({}),
    createComment: () => ({}),
    insert() {},
    remove() {},
    setText() {},
    setElementText() {},
    parentNode: () => null,
    nextSibling: () => null,
    patchProp() {},
});
async function mount(name, args) {
    const hooks = await server.ssrLoadModule(
        `/resources/js/composables/${name}.ts`,
    );
    let state;
    const app = renderer.createApp({
        setup() {
            state = hooks[name](...args);
            return () => h('div');
        },
    });
    app.mount({});
    await flush();
    return { state, app };
}

const playerConfig = {
    sport: 'nba',
    sportLabel: 'NBA',
    predictionsHref: '/nba/predictions',
    leaderboardQuery: null,
};

test('missing stored player quotes remain unavailable rather than zero-priced upcoming bets', async () => {
    const { normalizePlayerProp } = await server.ssrLoadModule(
        '/resources/js/lib/playerPagePresentation.ts',
    );
    const { default: Card } = await server.ssrLoadModule(
        '/resources/js/components/player-page/PlayerPropsCard.vue',
    );
    const quote = normalizePlayerProp({
        id: 1,
        market: 'points',
        line: null,
        over_price: null,
        under_price: null,
        game: { id: 1 },
    });
    assert.equal(quote.line, null);
    assert.equal(quote.over_price, null);
    assert.equal(quote.under_price, null);
    const html = await renderToString(
        createSSRApp({ render: () => h(Card, { playerProps: [quote] }) }),
    );
    assert.match(html, /Stored Props/);
    assert.match(html, /Line unavailable/);
    assert.match(html, /Price unavailable/);
});

test('non-push grades without hit direction stay pending in actual generic prop cards', async () => {
    const { usePlayerPropPresentation } = await server.ssrLoadModule(
        '/resources/js/composables/usePlayerPropPresentation.ts',
    );
    const { default: Card } = await server.ssrLoadModule(
        '/resources/js/components/player-props/PlayerPropCard.vue',
    );
    const presentation = usePlayerPropPresentation(
        { sport: 'NBA' },
        ref(Date.now()),
    );
    const rec = {
        id: 1,
        player: { name: 'Fixture', url: null },
        game: { home_team: 'H', away_team: 'A' },
        line: 10,
        actual_value: 12,
        hit_over: null,
        graded_at: '2026-11-26T00:00:00Z',
        recommendation: 'Under',
        market: 'points',
        odds: -110,
        confidence: 60,
        stats: {},
        bookmaker: 'fixture',
    };
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(Card, {
                    rec,
                    sport: 'NBA',
                    isAdmin: false,
                    presentation,
                    expanded: false,
                }),
        }),
    );
    assert.match(html, /Pending/);
    assert.doesNotMatch(html, />Won</);
    assert.equal(
        presentation.getResultStatus({ ...rec, actual_value: 10 }).label,
        'Push',
    );
    assert.equal(
        presentation.getResultStatus({
            ...rec,
            actual_value: 0,
            hit_over: false,
        }).label,
        'Won',
    );
});

test('player history uses the recorded game team rather than a traded players current team', async () => {
    const { getPlayerOpponent } = await server.ssrLoadModule(
        '/resources/js/lib/playerPagePresentation.ts',
    );
    const game = {
        home_team_id: 10,
        away_team_id: 20,
        home_team: { abbreviation: 'OLD' },
        away_team: { abbreviation: 'OPP' },
    };
    assert.deepEqual(
        getPlayerOpponent({ team_id: 10, game }, { team_id: 30 }),
        { label: 'vs', name: 'OPP' },
    );
    assert.equal(
        getPlayerOpponent({ team_id: 40, game }, { team_id: 30 }),
        null,
    );
});

test('partial loaded player samples do not claim complete season averages', async () => {
    const { default: Summary } = await server.ssrLoadModule(
        '/resources/js/components/player-page/PlayerSeasonSummary.vue',
    );
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(Summary, {
                    seasonSummaryValues: [
                        { label: 'PPG', value: '12.0', rank: null },
                    ],
                    gamesCount: 100,
                }),
        }),
    );
    assert.doesNotMatch(html, /Season Averages/);
    assert.match(html, /Loaded Game Averages/);
    assert.match(html, /not a complete season/);
});

test('player game log preserves observed zeros and missing leaderboard values stay unavailable', async () => {
    const { defaultGameLogColumns } = await server.ssrLoadModule(
        '/resources/js/lib/playerPagePresentation.ts',
    );
    const points = defaultGameLogColumns.find((x) => x.label === 'PTS');
    assert.equal(points.value({ points: 0 }), 0);
    assert.equal(points.value({ points: null }), '—');
    const { formatColumnValue } = await server.ssrLoadModule(
        '/resources/js/lib/playerStatsPresentation.ts',
    );
    assert.equal(
        formatColumnValue(
            { points_per_game: null },
            {
                key: 'points_per_game',
                format: (v) => Number(v ?? 0).toFixed(1),
            },
        ),
        '—',
    );
    assert.equal(
        formatColumnValue({ points_per_game: 0 }, { key: 'points_per_game' }),
        '0.0',
    );
});

test('reused player page props cancel old-account-independent player requests and cannot retain wrong identity', async () => {
    const calls = [];
    globalThis.window = {
        requestIdleCallback: () => 1,
        cancelIdleCallback() {},
    };
    globalThis.__gameReviewApi = {
        players: {
            show: (_s, id) => {
                const p = pending();
                calls.push({ id, ...p });
                return p.promise;
            },
        },
        stats: { players: async () => ({ data: [] }) },
    };
    const props = reactive({ config: playerConfig, playerId: 1 });
    const { state, app } = await mount('usePlayerPageData', [props]);
    try {
        props.playerId = 2;
        await flush();
        assert.deepEqual(
            calls.map((x) => x.id),
            [1, 2],
        );
        calls[1].resolve({ data: { id: 2, full_name: 'Second' } });
        await flush();
        calls[0].resolve({ data: { id: 1, full_name: 'First' } });
        await flush();
        assert.equal(state.playerData.value.id, 2);
    } finally {
        app.unmount();
        delete globalThis.window;
    }
});

test('loaded averages exclude unavailable observations instead of inventing scoreless appearances', async () => {
    const { defaultSummaryCards } = await server.ssrLoadModule(
        '/resources/js/lib/playerPagePresentation.ts',
    );
    const points = defaultSummaryCards.find((x) => x.label === 'PPG');
    assert.equal(points.value([{ points: null }]), '—');
    assert.equal(
        points.value([{ points: null }, { points: 10 }, { points: 0 }]),
        '5.0',
    );
    const { nflPlayerPageConfig, mlbPlayerPageConfig } =
        await server.ssrLoadModule(
            '/resources/js/config/player-page-configs.ts',
        );
    for (const config of [nflPlayerPageConfig, mlbPlayerPageConfig])
        for (const card of config.summaryCards)
            assert.equal(card.value([{}]), '—', card.label);
});

test('a partial cross-season player sample does not inherit an unrelated leaderboard rank', async () => {
    globalThis.window = {
        requestIdleCallback: () => 1,
        cancelIdleCallback() {},
    };
    globalThis.__gameReviewApi = {
        players: {
            show: async () => ({ data: { id: 1, full_name: 'Fixture' } }),
        },
        stats: {
            players: async () => ({
                data: [{ id: 1, stats: { points: 12 }, season: 2025 }],
                meta: { pagination: { total: 250 } },
            }),
        },
        leaderboards: {
            players: async () => ({
                data: [{ player_id: 1, points_per_game: 25 }],
                meta: {
                    focus_ranks: { points_per_game: { rank: 1, total: 100 } },
                },
            }),
        },
    };
    const { state, app } = await mount('usePlayerPage', [
        { config: { ...playerConfig, leaderboardQuery: {} }, playerId: 1 },
    ]);
    try {
        await state.loadSupplementalData();
        assert.equal(
            state.seasonSummaryValues.value.find((x) => x.label === 'PPG').rank,
            null,
        );
    } finally {
        app.unmount();
        delete globalThis.window;
    }
});

test('ordinary viewers see stale stored quote warnings without admin access', async () => {
    const { usePlayerPropPresentation } = await server.ssrLoadModule(
        '/resources/js/composables/usePlayerPropPresentation.ts',
    );
    const { default: Card } = await server.ssrLoadModule(
        '/resources/js/components/player-props/PlayerPropCard.vue',
    );
    const presentation = usePlayerPropPresentation(
        { sport: 'NBA' },
        ref(Date.parse('2026-11-26T12:00:00Z')),
    );
    const rec = {
        id: 1,
        player: { name: 'Fixture', url: null },
        game: { home_team: 'H', away_team: 'A' },
        line: 10,
        actual_value: null,
        graded_at: null,
        recommendation: 'Under',
        market: 'points',
        odds: -110,
        confidence: 60,
        stats: {},
        bookmaker: 'fixture',
        fetched_at: '2026-11-20T00:00:00Z',
        freshness_hours: 36,
    };
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(Card, {
                    rec,
                    sport: 'NBA',
                    isAdmin: false,
                    presentation,
                    expanded: false,
                }),
        }),
    );
    assert.match(html, /Quote expired/);
});

test('a season lookup failure never silently fetches an unscoped all-history leaderboard', async () => {
    let calls = 0;
    globalThis.__gameReviewApi = {
        leaderboards: {
            playerAvailableSeasons: async () => {
                throw new Error('Synthetic unavailable');
            },
            players: async () => {
                calls++;
                return { data: [] };
            },
        },
    };
    const { state, app } = await mount('usePlayerStats', [
        { config: { sport: 'nba' } },
    ]);
    try {
        await flush();
        assert.equal(calls, 0);
        assert.match(state.error.value, /season/i);
        assert.equal(state.loading.value, false);
    } finally {
        app.unmount();
    }
});

test('a failed new-season request cannot relabel previous season rows as the selected season', async () => {
    globalThis.__gameReviewApi = {
        leaderboards: {
            playerAvailableSeasons: async () => ({ data: [2026, 2025] }),
            players: async (_s, { query }) => {
                if (String(query.season) === '2025')
                    throw new Error('Synthetic unavailable');
                return {
                    data: [
                        {
                            player_id: 1,
                            games_played: 20,
                            points_per_game: 12,
                            player: { full_name: 'Old rows' },
                        },
                    ],
                };
            },
        },
    };
    const { state, app } = await mount('usePlayerStats', [
        { config: { sport: 'nba' } },
    ]);
    try {
        await flush();
        assert.equal(state.players.value.length, 1);
        state.selectedSeason.value = '2025';
        await flush();
        assert.equal(state.players.value.length, 0);
        assert.match(state.error.value, /Synthetic unavailable/);
    } finally {
        app.unmount();
    }
});

test('stored game calendar dates do not shift a day in a west-of-UTC viewer timezone', async () => {
    const { formatDate } = await server.ssrLoadModule(
        '/resources/js/lib/playerPagePresentation.ts',
    );
    const prior = process.env.TZ;
    process.env.TZ = 'America/Los_Angeles';
    try {
        assert.equal(formatDate('2026-10-10'), 'Oct 10');
        assert.equal(formatDate('2026-02-31'), '—');
    } finally {
        if (prior === undefined) delete process.env.TZ;
        else process.env.TZ = prior;
    }
});
