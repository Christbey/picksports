import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { createRenderer, h, nextTick } from 'vue';
let server, usePlayerPageData;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'player-page-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__playerPageApi;';
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
    ({ usePlayerPageData } = await server.ssrLoadModule(
        '/resources/js/composables/usePlayerPageData.ts',
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
    let resolve, reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
};
const flush = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
const props = {
    playerId: 7,
    config: {
        sport: 'nfl',
        sportLabel: 'NFL',
        predictionsHref: '/nfl/predictions',
        showPlayerProps: true,
    },
};
const mount = () => {
    let state;
    const app = renderer.createApp({
        setup() {
            state = usePlayerPageData(props);
            return () => h('div');
        },
    });
    app.mount({});
    return { app, state };
};
const api = (
    show,
    stats,
    playerProps = async () => ({ data: [] }),
    leaders = async () => ({ data: [] }),
) => ({
    players: { show, playerProps },
    stats: { players: stats },
    leaderboards: { players: leaders },
});
test('closing during primary requests aborts both and prevents late state writes and idle scheduling', async () => {
    const oldWindow = globalThis.window;
    const primary = deferred(),
        stats = deferred(),
        signals = [];
    let scheduled = 0;
    globalThis.window = {
        requestIdleCallback() {
            scheduled++;
            return 1;
        },
        cancelIdleCallback() {},
    };
    globalThis.__playerPageApi = api(
        (sport, id, options) => {
            signals.push(options.init.signal);
            return primary.promise;
        },
        (sport, options) => {
            signals.push(options.init.signal);
            return stats.promise;
        },
    );
    const { app, state } = mount();
    try {
        app.unmount();
        assert.equal(signals.length, 2);
        assert.ok(signals.every((signal) => signal.aborted));
        primary.resolve({ data: { name: 'Late player' } });
        stats.resolve({ data: [{ id: 1 }] });
        await flush();
        assert.equal(state.playerData.value, null);
        assert.deepEqual(state.gameLogs.value, []);
        assert.equal(scheduled, 0);
        assert.equal(state.error.value, null);
    } finally {
        globalThis.window = oldWindow;
        delete globalThis.__playerPageApi;
    }
});
test('closing with idle work queued cancels it and ignored cancellation still cannot start requests', async () => {
    const oldWindow = globalThis.window;
    let idle,
        cancelled,
        supplemental = 0;
    globalThis.window = {
        requestIdleCallback(callback) {
            idle = callback;
            return 9;
        },
        cancelIdleCallback(id) {
            cancelled = id;
        },
    };
    globalThis.__playerPageApi = api(
        async () => ({ data: { name: 'Player' } }),
        async () => ({ data: [] }),
        async () => {
            supplemental++;
            return { data: [] };
        },
        async () => {
            supplemental++;
            return { data: [] };
        },
    );
    const { app, state } = mount();
    try {
        await flush();
        assert.equal(state.loading.value, false);
        assert.equal(typeof idle, 'function');
        app.unmount();
        assert.equal(cancelled, 9);
        idle();
        await flush();
        assert.equal(supplemental, 0);
    } finally {
        globalThis.window = oldWindow;
        delete globalThis.__playerPageApi;
    }
});
test('closing with supplemental work in flight aborts it and discards late props and ranks', async () => {
    const oldWindow = globalThis.window;
    let idle;
    const quotes = deferred(),
        ranks = deferred(),
        signals = [];
    globalThis.window = {
        requestIdleCallback(callback) {
            idle = callback;
            return 9;
        },
        cancelIdleCallback() {},
    };
    globalThis.__playerPageApi = api(
        async () => ({ data: { name: 'Player' } }),
        async () => ({ data: [] }),
        (sport, id, options) => {
            signals.push(options.init.signal);
            return quotes.promise;
        },
        (sport, options) => {
            signals.push(options.init.signal);
            return ranks.promise;
        },
    );
    const { app, state } = mount();
    try {
        await flush();
        idle();
        assert.equal(signals.length, 2);
        app.unmount();
        assert.ok(signals.every((signal) => signal.aborted));
        quotes.resolve({ data: [{ id: 1 }] });
        ranks.resolve({
            data: [{ player_id: 7 }],
            meta: { focus_ranks: { yards: { rank: 1, total: 32 } } },
        });
        await flush();
        assert.deepEqual(state.playerProps.value, []);
        assert.deepEqual(state.leaderboardRows.value, []);
        assert.deepEqual(state.leaderboardRanks.value, {});
    } finally {
        globalThis.window = oldWindow;
        delete globalThis.__playerPageApi;
    }
});
test('active pages retain primary normalization, deferred props and focus-rank metadata', async () => {
    const oldWindow = globalThis.window;
    let idle;
    globalThis.window = {
        requestIdleCallback(callback) {
            idle = callback;
            return 9;
        },
        cancelIdleCallback() {},
    };
    globalThis.__playerPageApi = api(
        async () => ({ data: { id: 7, full_name: 'Test Player' } }),
        async () => ({ data: [{ id: 1, stats: { points: 0 }, game: null }] }),
        async () => ({
            data: [
                {
                    id: 1,
                    line: '0',
                    over_price: '110',
                    under_price: -120,
                    game: {
                        id: 4,
                        home_team: { abbreviation: 'HOM' },
                        away_team: 'AWY',
                    },
                },
            ],
        }),
        async () => ({
            data: [{ player_id: 7 }],
            meta: { focus_ranks: { points_per_game: { rank: 1, total: 10 } } },
        }),
    );
    const { app, state } = mount();
    try {
        await flush();
        assert.equal(state.playerData.value.name, 'Test Player');
        assert.equal(state.gameLogs.value[0].points, 0);
        assert.deepEqual(state.playerProps.value, []);
        idle();
        await flush();
        assert.equal(state.playerProps.value[0].line, 0);
        assert.equal(state.playerProps.value[0].game.home_team, 'HOM');
        assert.deepEqual(state.leaderboardRanks.value, {
            points_per_game: { rank: 1, total: 10 },
        });
    } finally {
        app.unmount();
        globalThis.window = oldWindow;
        delete globalThis.__playerPageApi;
    }
});
test('the timeout fallback is cancelled on close and cannot start late supplemental work', async () => {
    const original = {
        window: globalThis.window,
        setTimeout: globalThis.setTimeout,
        clearTimeout: globalThis.clearTimeout,
    };
    let queued, cancelled;
    globalThis.window = {};
    globalThis.setTimeout = (callback) => {
        queued = callback;
        return 11;
    };
    globalThis.clearTimeout = (id) => {
        cancelled = id;
    };
    let supplemental = 0;
    globalThis.__playerPageApi = api(
        async () => ({ data: null }),
        async () => ({ data: [] }),
        async () => {
            supplemental++;
            return { data: [] };
        },
        async () => {
            supplemental++;
            return { data: [] };
        },
    );
    const { app } = mount();
    try {
        await flush();
        app.unmount();
        assert.equal(cancelled, 11);
        queued();
        await flush();
        assert.equal(supplemental, 0);
    } finally {
        Object.assign(globalThis, original);
        delete globalThis.__playerPageApi;
    }
});

test('null primary responses are recoverable failures; duplicate retries wait for both reads', async () => {
    const oldWindow = globalThis.window;
    let idleCount = 0,
        fail = true,
        shows = 0;
    globalThis.window = {
        requestIdleCallback() {
            idleCount++;
            return 1;
        },
        cancelIdleCallback() {},
    };
    const pending = deferred();
    globalThis.__playerPageApi = api(
        async () => {
            shows++;
            return fail ? null : { data: { id: 7, full_name: 'Recovered' } };
        },
        async () => (fail ? null : pending.promise),
    );
    const { app, state } = mount();
    try {
        await flush();
        assert.match(state.error.value, /could not be loaded/);
        assert.deepEqual(state.gameLogs.value, []);
        fail = false;
        const retry = state.loadPlayer();
        await state.loadPlayer();
        await flush();
        assert.equal(state.loading.value, true);
        assert.equal(shows, 2);
        pending.resolve({ data: [] });
        await retry;
        assert.equal(state.error.value, null);
        assert.equal(state.playerData.value.name, 'Recovered');
        assert.equal(state.loading.value, false);
        assert.equal(idleCount, 1);
    } finally {
        app.unmount();
        globalThis.window = oldWindow;
        delete globalThis.__playerPageApi;
    }
});
test('supplemental failures have independent recovery without reloading player or game log', async () => {
    const oldWindow = globalThis.window;
    let idle,
        fail = true,
        primary = 0;
    globalThis.window = {
        requestIdleCallback(fn) {
            idle = fn;
            return 1;
        },
        cancelIdleCallback() {},
    };
    globalThis.__playerPageApi = api(
        async () => {
            primary++;
            return { data: { id: 7, full_name: 'Player' } };
        },
        async () => ({ data: [] }),
        async () => (fail ? null : { data: [] }),
        async () => ({
            data: [],
            meta: { focus_ranks: { yards: { rank: 1, total: 32 } } },
        }),
    );
    const { app, state } = mount();
    try {
        await flush();
        idle();
        await flush();
        assert.match(state.supplementalError.value, /could not be loaded/);
        assert.equal(state.error.value, null);
        assert.equal(state.leaderboardRanks.value.yards.rank, 1);
        fail = false;
        await state.loadSupplementalData();
        assert.equal(state.supplementalError.value, null);
        assert.equal(primary, 1);
        assert.equal(state.supplementalLoading.value, false);
    } finally {
        app.unmount();
        globalThis.window = oldWindow;
        delete globalThis.__playerPageApi;
    }
});
test('a rejected primary read waits for its sibling and retry does not accept late writes after close', async () => {
    const oldWindow = globalThis.window;
    globalThis.window = {
        requestIdleCallback() {
            return 1;
        },
        cancelIdleCallback() {},
    };
    const stats = deferred();
    let retry = false;
    const late = deferred();
    let signal;
    globalThis.__playerPageApi = api(
        async (s, id, o) => {
            signal = o.init.signal;
            if (retry) return late.promise;
            throw new Error('Offline');
        },
        async () => (retry ? { data: [] } : stats.promise),
    );
    const { app, state } = mount();
    try {
        await flush();
        assert.equal(state.loading.value, true);
        stats.resolve({ data: [] });
        await flush();
        assert.equal(state.error.value, 'Offline');
        retry = true;
        const reading = state.loadPlayer();
        app.unmount();
        assert.equal(signal.aborted, true);
        late.resolve({ data: { name: 'Late' } });
        await reading;
        assert.equal(state.playerData.value, null);
    } finally {
        app.unmount();
        globalThis.window = oldWindow;
        delete globalThis.__playerPageApi;
    }
});
