import assert from 'node:assert/strict';
import { after, afterEach, before, beforeEach, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { createRenderer, nextTick } from 'vue';

let server;
let useNflGamePage;
const original = Object.fromEntries(
    [
        'document',
        'setTimeout',
        'clearTimeout',
        'setInterval',
        'clearInterval',
    ].map((key) => [key, globalThis[key]]),
);
let timers;
let intervals;
let listeners;
let calls;
let snapshotResult;
let app;
let state;
let nextId;

const game = {
    id: 1722,
    season: 2026,
    season_type: '2',
    week: 2,
    status: 'STATUS_IN_PROGRESS',
    game_date: '2026-09-17',
    game_time: '20:15:00',
    starts_at: '2026-09-18T00:15:00Z',
    home_score: 14,
    away_score: 7,
    home_team_id: 2,
    away_team_id: 1,
    home_team: { id: 2, abbreviation: 'BUF' },
    away_team: { id: 1, abbreviation: 'DET' },
};

const snapshot = (overrides = {}) => ({
    game: {
        id: 1722,
        status: 'STATUS_IN_PROGRESS',
        home_score: 21,
        away_score: 10,
        period: 3,
        game_clock: '12:00',
        ...overrides,
    },
    projection: {
        live_win_probability: 81,
        live_predicted_spread: 12.5,
        live_predicted_total: 48,
        live_seconds_remaining: 1620,
    },
    source_updated_at: new Date().toISOString(),
    generated_at: new Date().toISOString(),
    warning: 'Experimental score-and-clock estimate.',
});
const flush = async () => {
    for (let i = 0; i < 25; i++) await Promise.resolve();
    await nextTick();
};
const deferred = () => {
    let resolve;
    const promise = new Promise((done) => {
        resolve = done;
    });
    return { promise, resolve };
};

before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'mock-nfl-live-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts')) {
                        return 'export const useApiV2Client = () => globalThis.__nflLivePageApi;';
                    }
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
    ({ useNflGamePage } = await server.ssrLoadModule(
        '/resources/js/composables/useNflGamePage.ts',
    ));
});
after(async () => {
    await server?.close();
});

beforeEach(() => {
    timers = new Map();
    intervals = new Map();
    listeners = new Map();
    calls = [];
    nextId = 1;
    snapshotResult = () => Promise.resolve({ data: snapshot() });
    globalThis.document = {
        hidden: false,
        addEventListener: (name, callback) => listeners.set(name, callback),
        removeEventListener: (name) => listeners.delete(name),
    };
    globalThis.setTimeout = (callback, delay) => {
        const id = nextId++;
        timers.set(id, { callback, delay });
        return id;
    };
    globalThis.clearTimeout = (id) => timers.delete(id);
    globalThis.setInterval = (callback, delay) => {
        const id = nextId++;
        intervals.set(id, { callback, delay });
        return id;
    };
    globalThis.clearInterval = (id) => intervals.delete(id);
    const track =
        (name, value) =>
        (...args) => {
            calls.push({ name, args });
            return typeof value === 'function'
                ? value(...args)
                : Promise.resolve(value);
        };
    globalThis.__nflLivePageApi = {
        games: {
            show: track('show', { data: game }),
            liveSnapshot: track('liveSnapshot', () => snapshotResult()),
        },
        predictions: {
            forGame: track('prediction', {
                data: {
                    game_id: 1722,
                    win_probability: 70,
                    predicted_spread: 7,
                    predicted_total: 45,
                },
            }),
        },
        stats: { teams: track('stats', { data: [] }) },
        teams: {
            games: track('recent', { data: [] }),
            trends: track('trends', { data: { sample_size: 1, trends: {} } }),
        },
    };
});
afterEach(() => {
    app?.unmount();
    app = undefined;
    for (const [key, value] of Object.entries(original)) {
        if (value === undefined) delete globalThis[key];
        else globalThis[key] = value;
    }
    delete globalThis.__nflLivePageApi;
});

const mount = async () => {
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
    app = renderer.createApp({
        setup() {
            state = useNflGamePage(1722);
            return () => null;
        },
    });
    app.mount({});
    await flush();
};
const tickRefresh = async () => {
    const entry = [...timers][0];
    assert.ok(entry, 'Expected a scheduled refresh');
    timers.delete(entry[0]);
    await entry[1].callback();
    await flush();
};
const count = (name) => calls.filter((call) => call.name === name).length;

test('initial requests use explicit regular-season phase and UTC kickoff, not the display date', async () => {
    await mount();
    assert.equal(count('trends'), 2);
    for (const { args } of calls.filter((call) => call.name === 'trends')) {
        assert.deepEqual(args[2].query, {
            games: 'season',
            season: 2026,
            season_type: '2',
            before_date: '2026-09-18T00:15:00Z',
        });
    }
    assert.equal(state.loading.value, false);
    assert.equal([...timers.values()][0].delay, 15000);
});

test('polling assigns a coherent score/projection snapshot without reloading expensive context', async () => {
    await mount();
    const before = {
        trends: count('trends'),
        recent: count('recent'),
        stats: count('stats'),
    };
    snapshotResult = async () => ({
        data: {
            ...snapshot({ home_score: 28, away_score: 13, period: 4 }),
            projection: { ...snapshot().projection, live_win_probability: 92 },
        },
    });
    await tickRefresh();
    assert.equal(state.game.value.home_score, 28);
    assert.equal(state.livePredictionData.value.homeScore, 28);
    assert.equal(state.livePredictionData.value.awayScore, 13);
    assert.equal(state.livePredictionData.value.period, 4);
    assert.equal(state.livePredictionData.value.liveWinProbability, 92);
    assert.equal(count('show'), 1);
    assert.equal(count('prediction'), 1);
    assert.deepEqual(
        {
            trends: count('trends'),
            recent: count('recent'),
            stats: count('stats'),
        },
        before,
    );
});

test('a failed refresh preserves the last snapshot and exposes a stale-data warning, then recovers', async () => {
    await mount();
    snapshotResult = async () => {
        throw new Error('Network unavailable');
    };
    await tickRefresh();
    assert.equal(state.game.value.home_score, 21);
    assert.match(
        state.livePredictionData.value.freshnessWarning,
        /Refresh failed/,
    );
    snapshotResult = async () => ({ data: snapshot({ home_score: 24 }) });
    await tickRefresh();
    assert.equal(state.game.value.home_score, 24);
    assert.equal(state.livePredictionData.value.freshnessWarning, null);
});

test('final snapshots stop polling, hide the live estimate and fetch final statistics', async () => {
    await mount();
    const statsBefore = count('stats');
    snapshotResult = async () => ({
        data: snapshot({ status: 'STATUS_FINAL' }),
    });
    await tickRefresh();
    assert.equal(state.game.value.status, 'STATUS_FINAL');
    assert.equal(state.hasLivePrediction.value, false);
    assert.equal(state.livePredictionData.value, undefined);
    assert.equal(timers.size, 0);
    assert.equal(count('stats'), statsBefore + 1);
});

test('hidden pages skip polling and visibility resume refreshes without overlapping requests', async () => {
    await mount();
    document.hidden = true;
    const before = count('liveSnapshot');
    await tickRefresh();
    assert.equal(count('liveSnapshot'), before);
    const pending = deferred();
    snapshotResult = () => pending.promise;
    document.hidden = false;
    listeners.get('visibilitychange')();
    listeners.get('visibilitychange')();
    await flush();
    assert.equal(count('liveSnapshot'), before + 1);
    pending.resolve({ data: snapshot() });
    await flush();
});

test('unmount aborts all requests and removes refresh timers and visibility listener', async () => {
    await mount();
    const signals = calls
        .map(({ args }) => args.at(-1)?.init?.signal)
        .filter(Boolean);
    assert.ok(signals.length >= 7);
    assert.ok(signals.every((signal) => !signal.aborted));
    app.unmount();
    app = undefined;
    assert.ok(signals.every((signal) => signal.aborted));
    assert.equal(timers.size, 0);
    assert.equal(intervals.size, 0);
    assert.equal(listeners.size, 0);
});

test('a pending snapshot resolving after unmount cannot mutate the page or create more requests', async () => {
    await mount();
    const pending = deferred();
    snapshotResult = () => pending.promise;
    const entry = [...timers][0];
    timers.delete(entry[0]);
    const refreshing = entry[1].callback();
    await flush();
    app.unmount();
    app = undefined;
    const requestsBefore = calls.length;
    pending.resolve({
        data: snapshot({ home_score: 99, status: 'STATUS_FINAL' }),
    });
    await refreshing;
    await flush();
    assert.equal(state.game.value.home_score, 21);
    assert.equal(calls.length, requestsBefore);
    assert.equal(timers.size, 0);
    assert.equal(intervals.size, 0);
    assert.equal(listeners.size, 0);
});

test('unmount during the first live refresh cannot install a late timer or visibility listener', async () => {
    const pending = deferred();
    snapshotResult = () => pending.promise;
    await mount();
    assert.equal(count('liveSnapshot'), 1);
    assert.equal(timers.size, 0);
    app.unmount();
    app = undefined;
    const requestsBefore = calls.length;
    pending.resolve({ data: snapshot({ home_score: 99 }) });
    await flush();
    assert.equal(state.game.value.home_score, 14);
    assert.equal(calls.length, requestsBefore);
    assert.equal(timers.size, 0);
    assert.equal(intervals.size, 0);
    assert.equal(listeners.size, 0);
});
