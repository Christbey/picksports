import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import {
    createRenderer,
    createSSRApp,
    effectScope,
    h,
    nextTick,
    ref,
} from 'vue';
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
const format = (value, decimals = 1) =>
    value === null || value === undefined
        ? 'Unavailable'
        : Number(value).toFixed(decimals);
const target = {
    id: 1,
    status: 'STATUS_FINAL',
    season: 2026,
    season_type: '2',
    game_date: '2026-10-10',
    game_time: '18:00:00',
    starts_at: '2026-10-10T23:00:00Z',
    home_team_id: 10,
    away_team_id: 20,
    home_team: { id: 10, abbreviation: 'HOME' },
    away_team: { id: 20, abbreviation: 'AWAY' },
};
const canonical = {
    phase: 'pregame',
    revision: 1,
    sport: 'cfb',
    predicted_spread: -7,
    predicted_total: 48,
    win_probability: 0.7,
    home_win_probability: 0.7,
    away_win_probability: 0.3,
};
function basicApi(game = target, prediction = canonical) {
    return {
        games: { show: async () => ({ data: game }) },
        predictions: { forGame: async () => ({ data: prediction }) },
        stats: {
            teams: async () => ({ data: [] }),
            players: async () => ({ data: [] }),
        },
        teams: {
            metrics: async () => ({ data: null }),
            games: async () => ({ data: [] }),
            trends: async () => ({ data: null }),
        },
    };
}

test('basketball and MLB preserve missing markets without inventing a zero line or zero probability', async () => {
    const { normalizePrediction } = await server.ssrLoadModule(
        '/resources/js/composables/useBasketballGamePage.ts',
    );
    const { normalizeMlbPrediction } = await server.ssrLoadModule(
        '/resources/js/composables/useMlbGamePage.ts',
    );
    for (const normalize of [normalizePrediction, normalizeMlbPrediction]) {
        const missing = normalize({
            ...canonical,
            home_win_probability: null,
            away_win_probability: null,
            predicted_spread: null,
            predicted_total: null,
        });
        assert.equal(missing.predicted_spread, null);
        assert.equal(missing.predicted_total, null);
        assert.equal(missing.home_win_probability, null);
        const available = normalize({
            ...canonical,
            home_win_probability: 0,
            away_win_probability: 1,
            predicted_spread: 0,
            predicted_total: 0,
        });
        assert.equal(available.home_win_probability, 0);
        assert.equal(available.predicted_spread, 0);
        assert.equal(available.predicted_total, 0);
        const Summary = (
            await server.ssrLoadModule(
                '/resources/js/components/game-page/PredictionSummaryCard.vue',
            )
        ).default;
        const html = await renderToString(
            createSSRApp(Summary, {
                prediction: missing,
                formatNumber: format,
                awayBarClass: 'away',
                homeBarClass: 'home',
            }),
        );
        assert.doesNotMatch(html, /Pick&#39;em|PK|0%|NaN/);
        assert.match(html, /unavailable|Unavailable/);
    }
});

test('CFB canonical home handicap is converted exactly once before the actual football forecast card', async () => {
    globalThis.__gameReviewApi = basicApi();
    const { state, app } = await mount('useCfbDetailedGamePage', [1]);
    try {
        await flush();
        const prediction = state.predictionSectionProps.value.prediction;
        assert.equal(prediction.predicted_spread, 7);
        const Card = (
            await server.ssrLoadModule(
                '/resources/js/components/game-page/NFLPredictionModelCard.vue',
            )
        ).default;
        const html = await renderToString(
            createSSRApp(Card, {
                prediction,
                homeLabel: 'HOME',
                awayLabel: 'AWAY',
                formatNumber: format,
                formatSpread: (v) => String(v),
            }),
        );
        assert.match(html, /HOME projected to win by/);
        assert.doesNotMatch(html, /AWAY favored/);
    } finally {
        app.unmount();
    }
});

test('an obsolete detailed-game reload cannot replace the latest game even if transport ignores abort', async () => {
    const reads = [];
    const api = basicApi();
    api.games.show = () => {
        const p = pending();
        reads.push(p);
        return p.promise;
    };
    globalThis.__gameReviewApi = api;
    const { state, app } = await mount('useDetailedGameData', [
        { sport: 'nba', gameId: 1 },
    ]);
    try {
        const latest = state.reload();
        reads[1].resolve({ data: { ...target, name: 'Latest observed game' } });
        await latest;
        reads[0].resolve({ data: { ...target, name: 'Obsolete game' } });
        await flush();
        assert.equal(state.game.value.name, 'Latest observed game');
    } finally {
        app.unmount();
    }
});

test('CFB form uses observed zero scores and rejects post-kickoff games from the displayed pregame record', async () => {
    const api = basicApi();
    const seen = [];
    api.teams.games = async (sport, team, options) => {
        seen.push(options.query);
        return {
            data: [
                {
                    ...target,
                    id: 2,
                    game_date: '2026-10-01',
                    starts_at: '2026-10-01T23:00:00Z',
                    home_score: 24,
                    away_score: 0,
                },
                {
                    ...target,
                    id: 3,
                    game_date: '2026-10-11',
                    starts_at: '2026-10-11T23:00:00Z',
                    home_score: 1,
                    away_score: 9,
                },
            ],
        };
    };
    globalThis.__gameReviewApi = api;
    const { state, app } = await mount('useCfbDetailedGamePage', [1]);
    try {
        await flush();
        assert.equal(
            state.recentSectionProps.value.homeRecord.startsWith('1-0'),
            true,
        );
        assert.equal(seen[0].before_game_at, target.starts_at);
        assert.equal(seen[0].season, 2026);
    } finally {
        app.unmount();
    }
});

test('partial linescores keep each observed period under its own heading rather than shifting values', async () => {
    const Card = (
        await server.ssrLoadModule(
            '/resources/js/components/game-page/LinescoreCard.vue',
        )
    ).default;
    const html = await renderToString(
        createSSRApp(Card, {
            title: 'Quarter scores',
            homeTeam: null,
            awayTeam: null,
            homeLinescores: [
                { period: 1, value: 7 },
                { period: 2, value: 3 },
            ],
            awayLinescores: [{ period: 2, value: 0 }],
            homeScore: 10,
            awayScore: 0,
            usePeriodNumbers: true,
        }),
    );
    const away = html.match(/<tbody>\s*<tr[^>]*>(.*?)<\/tr>/s)[1];
    const cells = [...away.matchAll(/<td[^>]*>(.*?)<\/td>/gs)].map((m) =>
        m[1].replace(/<[^>]+>/g, '').trim(),
    );
    assert.deepEqual(cells, ['—', '0', '0']);
});

test('a final result takes precedence over retained live projections and accepts full-time finals', async () => {
    const { predictionTiming } = await server.ssrLoadModule(
        '/resources/js/lib/predictionTiming.ts',
    );
    for (const status of ['STATUS_FINAL', 'STATUS_FULL_TIME'])
        assert.equal(
            predictionTiming({
                status,
                live_win_probability: 0.8,
                live_updated_at: '2026-10-10T23:00:00Z',
            }).phase,
            'final',
        );
    const { gameOutcome } = await server.ssrLoadModule(
        '/resources/js/lib/gameOutcome.ts',
    );
    assert.equal(
        gameOutcome(
            {
                ...target,
                status: 'STATUS_FULL_TIME',
                home_score: 24,
                away_score: 0,
            },
            10,
        ),
        'W',
    );
    assert.equal(
        gameOutcome(
            { ...target, status: null, home_score: 24, away_score: 0 },
            10,
        ),
        null,
    );
});

test('prop quote percentage-point units are not reinterpreted as fractions and freshness stays visible', async () => {
    const Card = (
        await server.ssrLoadModule(
            '/resources/js/components/game-page/GamePlayerPropsCard.vue',
        )
    ).default;
    globalThis.__gameReviewResource = {
        data: ref([
            {
                id: 1,
                player_name: 'Stored player',
                market: 'Points',
                line: 10,
                over_price: -110,
                under_price: -110,
                fetched_at: '2020-01-01T00:00:00Z',
                recommendation: {
                    side: 'Under',
                    confidence_score: 70,
                    predicted_over_probability: 0.6,
                    market_over_probability: 1,
                    edge_probability: 0.4,
                    data_quality_score: 1,
                },
            },
        ]),
        isLoading: ref(false),
        error: ref(null),
        execute: async () => {},
    };
    const html = await renderToString(
        createSSRApp(Card, { sportSlug: 'nba', gameId: 1 }),
    );
    assert.match(html, /Edge \+0\.4\s*pp/);
    assert.doesNotMatch(html, /100\.0%|60\.0%|Strongest prop/);
    assert.match(html, /stale|Stale/);
});

test('an initial prop fetch error remains visible rather than disappearing as an empty section', async () => {
    const Card = (
        await server.ssrLoadModule(
            '/resources/js/components/game-page/GamePlayerPropsCard.vue',
        )
    ).default;
    globalThis.__gameReviewResource = {
        data: ref([]),
        isLoading: ref(false),
        error: ref('Synthetic unavailable response'),
        execute: async () => {},
    };
    const html = await renderToString(
        createSSRApp(Card, { sportSlug: 'nba', gameId: 1 }),
    );
    assert.match(html, /Player props unavailable/);
    assert.match(html, /Synthetic unavailable/);
});

test('basketball box scores preserve absent observations and a genuine observed zero', async () => {
    const api = basicApi({ ...target, status: 'STATUS_FINAL' }, canonical);
    api.stats.teams = async () => ({
        data: [
            {
                id: 1,
                team_type: 'home',
                stats: {
                    field_goals_made: null,
                    field_goals_attempted: null,
                    rebounds: null,
                    points_in_paint: 0,
                },
            },
            {
                id: 2,
                team_type: 'away',
                stats: {
                    field_goals_made: 0,
                    field_goals_attempted: 0,
                    rebounds: 7,
                    points_in_paint: 0,
                },
            },
        ],
    });
    globalThis.__gameReviewApi = api;
    const { state, app } = await mount('useBasketballGamePage', [
        { sport: 'nba', gameId: 1 },
    ]);
    try {
        await flush();
        assert.equal(state.homeTeamStats.value.field_goals_made, null);
        assert.equal(state.awayTeamStats.value.field_goals_made, 0);
        const Card = (
            await server.ssrLoadModule(
                '/resources/js/components/game-page/BasketballBoxScoreCard.vue',
            )
        ).default;
        const html = await renderToString(
            createSSRApp(Card, {
                homeTeamStats: state.homeTeamStats.value,
                awayTeamStats: state.awayTeamStats.value,
                getBetterValue: () => null,
                layout: 'table',
            }),
        );
        assert.match(html, /Unavailable/);
        assert.match(html, /Points in Paint/);
        assert.doesNotMatch(html, /null|null-null/);
    } finally {
        app.unmount();
    }
});

test('a prediction card cannot invent an away moneyline pick from a missing win estimate', async () => {
    globalThis.__gameReviewApi = {
        userBets: { index: async () => ({ tracking: null }) },
    };
    const Card = (
        await server.ssrLoadModule(
            '/resources/js/components/predictions/UnifiedPredictionCard.vue',
        )
    ).default;
    const prediction = {
        id: 1,
        game_id: 1,
        win_probability: null,
        predicted_spread: null,
        predicted_total: null,
        away_team: 'AWAY',
        home_team: 'HOME',
        sport: 'nfl',
        status: 'STATUS_SCHEDULED',
    };
    const html = await renderToString(
        createSSRApp(Card, {
            prediction,
            sport: 'nfl',
            href: '/nfl/games/1',
            dashboard: true,
        }),
    );
    assert.doesNotMatch(html, /Moneyline pick: AWAY/);
    assert.match(html, /Model winner unavailable/);
    const measured = await renderToString(
        createSSRApp(Card, {
            prediction: { ...prediction, win_probability: 0 },
            sport: 'nfl',
            href: '/nfl/games/1',
            dashboard: true,
        }),
    );
    assert.match(measured, /Moneyline pick: AWAY/);
});

test('stored external context shows its expired evidence timing and does not turn non-HTTPS sources into links', async () => {
    const Card = (
        await server.ssrLoadModule(
            '/resources/js/components/predictions/PredictionExternalContext.vue',
        )
    ).default;
    const stored = {
        summary: 'Stored evidence',
        researched_at: '2020-01-01T00:00:00Z',
        expires_at: '2020-01-02T00:00:00Z',
        sources: [
            {
                url: 'ftp://untrusted.example/source',
                publisher: 'Unsafe scheme',
            },
            {
                url: 'https://user:password@example.test/private',
                publisher: 'Credentials',
            },
            {
                url: 'https://official.example.test/report',
                publisher: 'Valid source',
            },
        ],
    };
    const context = {
        hasExternalGameContext: () => true,
        externalGameContext: () => stored,
        externalContextConfidenceLabel: () => null,
        externalContextAdjustmentLabel: () => null,
    };
    const html = await renderToString(createSSRApp(Card, { context }));
    assert.match(html, /Stored web context/);
    assert.match(html, /Expired|expired/);
    assert.match(html, /https:\/\/official.example.test\/report/);
    assert.doesNotMatch(html, /href="ftp:|user:password|Current web context/);
});

test('late supplemental statistics and unmounted main responses cannot alter a newer detailed page', async () => {
    const teams = [];
    const api = basicApi();
    let name = 'First';
    api.games.show = async () => ({ data: { ...target, name } });
    api.stats.teams = () => {
        const p = pending();
        teams.push(p);
        return p.promise;
    };
    globalThis.__gameReviewApi = api;
    const { state, app } = await mount('useDetailedGameData', [
        { sport: 'nba', gameId: 1 },
    ]);
    try {
        name = 'Newest';
        const latest = state.reload();
        await flush();
        teams[1].resolve({
            data: [{ id: 2, team_type: 'home', stats: { rebounds: 0 } }],
        });
        await latest;
        teams[0].resolve({
            data: [{ id: 1, team_type: 'home', stats: { rebounds: 99 } }],
        });
        await flush();
        assert.equal(state.game.value.name, 'Newest');
        assert.equal(state.homeTeamStats.value.rebounds, 0);
        name = 'Unmounted response';
        const closing = state.reload();
        app.unmount();
        await closing;
        assert.equal(state.game.value.name, 'Newest');
        assert.equal(state.homeTeamStats.value.rebounds, 0);
    } finally {
        app.unmount();
    }
});

test('a partial prediction keeps its available total when win-probability fields are withheld', async () => {
    const basketball = await server.ssrLoadModule(
        '/resources/js/composables/useBasketballGamePage.ts',
    );
    const mlb = await server.ssrLoadModule(
        '/resources/js/composables/useMlbGamePage.ts',
    );
    for (const normalize of [
        basketball.normalizePrediction,
        mlb.normalizeMlbPrediction,
    ]) {
        const result = normalize({ predicted_total: 42.5 });
        assert.notEqual(result, null);
        assert.equal(result.predicted_total, 42.5);
        assert.equal(result.home_win_probability, null);
        assert.equal(result.predicted_spread, null);
    }
});

test('canonical board mapping preserves spread convention through actual tracked-pick home and away defaults', async () => {
    const { mapV2Prediction } = await server.ssrLoadModule(
        '/resources/js/lib/predictionBoardPresentation.ts',
    );
    const { usePredictionCardGame } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionCardGame.ts',
    );
    const { usePredictionTracking } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionTracking.ts',
    );
    const prediction = mapV2Prediction({
        id: 'canonical-fixture',
        phase: 'pregame',
        revision: 2,
        projection: {
            home_win_probability: 0.7,
            away_win_probability: 0.3,
            predicted_spread: -7,
            predicted_total: 48,
        },
        game: {
            id: 1,
            status: 'STATUS_SCHEDULED',
            home_team: { abbreviation: 'HOME' },
            away_team: { abbreviation: 'AWAY' },
        },
    });
    const scope = effectScope();
    const props = {
        prediction,
        sport: 'cfb',
        href: '/cfb/games/1',
        compact: true,
    };
    try {
        const tracking = scope.run(() =>
            usePredictionTracking(
                props,
                usePredictionCardGame(props),
                ref(false),
            ),
        );
        const options = tracking.saveOptions();
        assert.equal(
            options.find(
                (option) =>
                    option.betType === 'spread' &&
                    option.selectionSide === 'home',
            ).defaultLine,
            -7,
        );
        assert.equal(
            options.find(
                (option) =>
                    option.betType === 'spread' &&
                    option.selectionSide === 'away',
            ).defaultLine,
            7,
        );
    } finally {
        scope.stop();
    }
});

test('legacy unverified calendar clocks never become a browser-local kickoff instant', async () => {
    const { usePredictionCardGame } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionCardGame.ts',
    );
    const game = {
        id: 1,
        game_date: '2026-11-01',
        game_time: '01:30:00',
        home_team: {},
        away_team: {},
    };
    const props = {
        prediction: { id: 1, game },
        sport: 'mlb',
        href: '/mlb/games/1',
        compact: true,
    };
    const scope = effectScope();
    try {
        const card = scope.run(() => usePredictionCardGame(props));
        assert.equal(card.gameDateTimeLabel(), 'Game time unavailable');
    } finally {
        scope.stop();
    }
});

test('detailed historical pages request metrics for the selected season and phase', async () => {
    const api = basicApi();
    const requests = [];
    api.teams.metrics = async (...args) => {
        requests.push(args[2]);
        return { data: null };
    };
    globalThis.__gameReviewApi = api;
    const { app } = await mount('useDetailedGameData', [
        { sport: 'nba', gameId: 1 },
    ]);
    try {
        assert.equal(requests.length, 2);
        for (const request of requests)
            assert.deepEqual(request.query, { season: 2026, season_type: '2' });
    } finally {
        app.unmount();
    }
});

test('the compiled summary converts observed home margin to sportsbook handicap and preserves unknowns', async () => {
    const Summary = (
        await server.ssrLoadModule(
            '/resources/js/components/game-page/PredictionSummaryCard.vue',
        )
    ).default;
    for (const [margin, spread, favorite] of [
        [11.7, '-11.7', 'HOME favored'],
        [-3.1, '+3.1', 'AWAY favored'],
        [0, 'PK', "Pick'em"],
        [null, 'Spread unavailable', 'Favorite unavailable'],
    ]) {
        const html = await renderToString(
            createSSRApp(Summary, {
                prediction: {
                    sport: 'cfb',
                    predicted_spread: margin,
                    predicted_total: null,
                    home_win_probability: null,
                    away_win_probability: null,
                },
                homeLabel: 'HOME',
                awayLabel: 'AWAY',
                formatNumber: format,
                awayBarClass: 'away',
                homeBarClass: 'home',
            }),
        );
        assert.ok(html.includes(spread), `${margin}: expected ${spread}`);
        assert.ok(
            html.includes(favorite.replace("'", '&#39;')),
            `${margin}: expected ${favorite}`,
        );
        if (margin === null)
            assert.doesNotMatch(html, /PK|HOME favored|AWAY favored/);
    }
});
