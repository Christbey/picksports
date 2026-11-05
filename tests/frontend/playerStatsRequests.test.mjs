import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { createRenderer, h, nextTick } from 'vue';
let server, usePlayerStats;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'player-stats-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__playerStatsApi;';
                },
            },
        ],
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
    ({ usePlayerStats } = await server.ssrLoadModule(
        '/resources/js/composables/usePlayerStats.ts',
    ));
});
after(async () => {
    await server?.close();
});
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
const deferred = () => {
    let resolve;
    const promise = new Promise((yes) => (resolve = yes));
    return { promise, resolve };
};
const flush = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
const config = {
    sport: 'nfl',
    pageTitle: 'Stats',
    heading: 'Player stats',
    description: 'Test',
    breadcrumb: { title: 'Stats', href: '/stats' },
    bannerStorageKey: 'test',
};
const mount = (extra = {}) => {
    let state;
    const app = renderer.createApp({
        setup() {
            state = usePlayerStats({ config: { ...config, ...extra } });
            return () => h('div');
        },
    });
    app.mount({});
    return { app, state };
};
test('closing while seasons load prevents leaderboard requests and late season mutations', async () => {
    const seasons = deferred();
    let signal,
        calls = 0;
    globalThis.__playerStatsApi = {
        leaderboards: {
            playerAvailableSeasons(sport, options) {
                signal = options.init.signal;
                return seasons.promise;
            },
            players: async () => {
                calls++;
                return { data: [] };
            },
        },
    };
    const { app, state } = mount();
    try {
        app.unmount();
        assert.equal(signal.aborted, true);
        seasons.resolve({ data: [2025, 2024] });
        await flush();
        assert.equal(calls, 0);
        assert.deepEqual(state.availableSeasons.value, []);
        assert.equal(state.error.value, null);
    } finally {
        delete globalThis.__playerStatsApi;
    }
});
test('latest season request owns rows and loading even if an aborted request resolves later', async () => {
    const requests = [];
    globalThis.__playerStatsApi = {
        leaderboards: {
            playerAvailableSeasons: async () => ({ data: [2025, 2024] }),
            players(sport, options) {
                const pending = deferred();
                requests.push({ ...pending, options });
                return pending.promise;
            },
        },
    };
    const { app, state } = mount();
    try {
        await flush();
        assert.equal(requests.length, 1);
        assert.equal(requests[0].options.query.season, '2025');
        state.selectedSeason.value = '2024';
        await nextTick();
        assert.equal(requests.length, 2);
        assert.equal(requests[0].options.init.signal.aborted, true);
        requests[1].resolve({
            data: [{ player_id: 2, games_played: 3, points_per_game: 0 }],
        });
        await flush();
        requests[0].resolve({
            data: [{ player_id: 1, games_played: 3, points_per_game: 99 }],
        });
        await flush();
        assert.equal(state.players.value[0].player_id, 2);
        assert.equal(state.loading.value, false);
    } finally {
        app.unmount();
        delete globalThis.__playerStatsApi;
    }
});
test('category filtering keeps its minimum-games query, zero-value sorting, and local-only changes', async () => {
    const requests = [];
    const data = [
        {
            player_id: 1,
            player: { full_name: 'Zero', position: 'QB' },
            games_played: 3,
            passing_yards_per_game: 0,
        },
        {
            player_id: 2,
            player: { full_name: 'Ten', position: 'QB' },
            games_played: 3,
            passing_yards_per_game: 10,
        },
        {
            player_id: 3,
            player: { full_name: 'Too few', position: 'QB' },
            games_played: 1,
            passing_yards_per_game: 99,
        },
    ];
    globalThis.__playerStatsApi = {
        leaderboards: {
            playerAvailableSeasons: async () => ({ data: [2025] }),
            players: async (sport, options) => {
                requests.push(options.query);
                return { data };
            },
        },
    };
    const category = (key, position, minGames) => ({
        key,
        label: key,
        minGames,
        match: (row) => row.player?.position === position,
        defaultSortBy: 'passing_yards_per_game',
        sortOptions: [{ key: 'passing_yards_per_game', label: 'Yards' }],
    });
    const { app, state } = mount({
        minGames: 1,
        statCategoryOptions: [
            category('passing', 'QB', 3),
            category('rushing', 'RB', 1),
        ],
    });
    try {
        await flush();
        assert.equal(requests[0].min_games, 1);
        assert.deepEqual(
            state.sortedPlayers.value.map((x) => x.player_id),
            [2, 1],
        );
        state.toggleSort('passing_yards_per_game');
        assert.deepEqual(
            state.sortedPlayers.value.map((x) => x.player_id),
            [1, 2],
        );
        state.selectedCategory.value = 'rushing';
        await nextTick();
        assert.deepEqual(state.sortedPlayers.value, []);
        assert.equal(requests.length, 1);
    } finally {
        app.unmount();
        delete globalThis.__playerStatsApi;
    }
});
test('search and sorting reset show-more limits while retaining the complete loaded dataset', async () => {
    const data = Array.from({ length: 105 }, (_, i) => ({
        player_id: i,
        player: { full_name: `Player ${i}` },
        games_played: 3,
        points_per_game: i,
    }));
    globalThis.__playerStatsApi = {
        leaderboards: {
            playerAvailableSeasons: async () => ({ data: [2025] }),
            players: async () => ({ data }),
        },
    };
    const { app, state } = mount();
    try {
        await flush();
        assert.equal(state.visiblePlayers.value.length, 100);
        state.visibleLimit.value += 100;
        assert.equal(state.visiblePlayers.value.length, 105);
        state.searchQuery.value = 'Player 104';
        await nextTick();
        assert.equal(state.visibleLimit.value, 100);
        assert.equal(state.visiblePlayers.value[0].player_id, 104);
        assert.equal(state.players.value.length, 105);
    } finally {
        app.unmount();
        delete globalThis.__playerStatsApi;
    }
});
