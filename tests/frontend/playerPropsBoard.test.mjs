import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { createRenderer, h, nextTick, reactive } from 'vue';

let server, usePlayerPropsBoard;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'board-api-fixture',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__propsBoardApi;';
                },
            },
        ],
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
    ({ usePlayerPropsBoard } = await server.ssrLoadModule(
        '/resources/js/composables/usePlayerPropsBoard.ts',
    ));
});
after(async () => {
    await server?.close();
    delete globalThis.__propsBoardApi;
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
const settle = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
const pending = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
};
const payload = (query = {}, data = []) => ({
    data,
    dates: [{ value: query.date || '2026-11-08', label: 'Nov 8' }],
    games: [{ id: 10, label: 'NYJ @ BUF', date: query.date || '2026-11-08' }],
    markets: [{ value: 'player_pass_yds', label: 'Passing yards' }],
    filters: {
        date: query.date || '2026-11-08',
        game: query.game || null,
        market: query.market || null,
    },
    meta: {},
});
function fixture({ view = 'recommendations', sport = 'NFL', fetcher } = {}) {
    const original = globalThis.window;
    let url = new URL(
        `https://picksports.test/${sport.toLowerCase()}/player-props?tracking=kept#results`,
    );
    const listeners = new Map();
    const history = {
        state: { inertia: 'preserved' },
        replaceState(state, _, value) {
            this.state = state;
            url = new URL(value, url);
        },
    };
    globalThis.window = {
        get location() {
            return { href: url.href, pathname: url.pathname };
        },
        history,
        addEventListener: (name, fn) => listeners.set(name, fn),
        removeEventListener: (name) => listeners.delete(name),
    };
    const requests = [];
    globalThis.__propsBoardApi = {
        playerProps: {
            board: (slug, options) => {
                requests.push({ slug, ...options });
                return fetcher
                    ? fetcher(slug, options, requests.length)
                    : Promise.resolve(payload(options.query));
            },
        },
    };
    const props = reactive({
        sport,
        sportSlug: sport.toLowerCase(),
        filters: { view, date: null, game: null, market: null },
        sportLabel: sport,
        description: 'Props',
    });
    let state;
    const app = renderer.createApp({
        setup() {
            state = usePlayerPropsBoard(props);
            return () => h('div');
        },
    });
    app.mount({});
    return {
        state,
        requests,
        props,
        listeners,
        history,
        get url() {
            return url;
        },
        navigate(value) {
            url = new URL(value, url);
            listeners.get('popstate')?.();
        },
        close() {
            app.unmount();
            if (original === undefined) delete globalThis.window;
            else globalThis.window = original;
        },
    };
}

test('direct All props loads filter metadata without recommendation rows and resolves defaults once', async () => {
    const f = fixture({ view: 'all' });
    try {
        assert.equal(f.state.filtersReady.value, false);
        await settle();
        assert.equal(f.requests.length, 1);
        assert.equal(f.requests[0].query.filters_only, true);
        assert.equal(f.state.filtersReady.value, true);
        assert.equal(f.state.selectedDate.value, '2026-11-08');
        assert.deepEqual(f.state.recommendations.value, []);
        assert.equal(f.state.dates.value.length, 1);
        assert.equal(f.url.searchParams.get('view'), 'all');
        assert.equal(f.url.searchParams.get('tracking'), 'kept');
        assert.equal(f.url.hash, '#results');
        assert.deepEqual(f.history.state, { inertia: 'preserved' });
    } finally {
        f.close();
    }
});

test('switching views cancels hidden requests and cached filter choices avoid duplicate board reads', async () => {
    const first = pending();
    const f = fixture({
        fetcher: (_, options, count) =>
            count === 1
                ? first.promise
                : Promise.resolve(payload(options.query)),
    });
    try {
        assert.equal(f.requests[0].query.filters_only, undefined);
        f.state.switchView('all');
        await settle();
        assert.equal(f.requests[0].init.signal.aborted, true);
        assert.equal(f.requests[1].query.filters_only, true);
        first.resolve(payload({ date: '2020-01-01' }, [{ id: 'obsolete' }]));
        await settle();
        assert.equal(f.state.selectedDate.value, '2026-11-08');
        assert.deepEqual(f.state.recommendations.value, []);
        f.state.switchView('recommendations');
        await settle();
        assert.equal(f.requests[2].query.filters_only, undefined);
        f.state.switchView('all');
        await settle();
        assert.equal(f.requests.length, 3);
        f.state.switchView('all');
        await settle();
        assert.equal(f.requests.length, 3);
    } finally {
        f.close();
    }
});

test('All props filter edits request only metadata, clear games when the date changes, and keep URL in sync', async () => {
    const f = fixture({ view: 'all' });
    try {
        await settle();
        f.state.selectedGame.value = '10';
        f.state.onGameChange();
        await settle();
        f.state.selectedMarket.value = 'player_pass_yds';
        f.state.onMarketChange();
        await settle();
        f.state.selectedDate.value = '2026-11-15';
        f.state.onDateChange();
        await settle();
        assert.ok(f.requests.every((request) => request.query.filters_only));
        assert.equal(f.state.selectedGame.value, '');
        assert.equal(f.url.searchParams.get('date'), '2026-11-15');
        assert.equal(f.url.searchParams.get('game'), null);
        assert.equal(f.url.searchParams.get('market'), 'player_pass_yds');
        f.state.clearFilters();
        await settle();
        assert.equal(f.requests.at(-1).query.date, undefined);
        assert.equal(f.requests.at(-1).query.game, undefined);
        assert.equal(f.requests.at(-1).query.market, undefined);
    } finally {
        f.close();
    }
});

test('browser restoration and incoming page props select the correct view and cancel on unmount', async () => {
    const f = fixture();
    try {
        await settle();
        f.navigate('?view=all&date=2026-11-15&game=10&market=player_pass_yds');
        await settle();
        assert.equal(f.state.selectedView.value, 'all');
        assert.equal(f.requests.at(-1).query.filters_only, true);
        assert.equal(f.requests.at(-1).query.game, '10');
        f.props.filters = {
            view: 'recommendations',
            date: '2026-11-22',
            game: null,
            market: null,
        };
        await settle();
        assert.equal(f.state.selectedView.value, 'recommendations');
        assert.equal(f.requests.at(-1).query.filters_only, undefined);
        const count = f.requests.length;
        f.navigate('/nfl/teams/10');
        await settle();
        assert.equal(f.requests.length, count);
    } finally {
        f.close();
    }
    assert.equal(f.listeners.has('popstate'), false);
    assert.equal(f.requests.at(-1).init.signal.aborted, true);
});

test('failed recommendation refresh retains previous data and ignores superseded errors', async () => {
    const error = pending();
    const f = fixture({
        fetcher: (_, options, count) =>
            count === 1
                ? Promise.resolve(payload(options.query, [{ id: 1 }]))
                : count === 2
                  ? error.promise
                  : Promise.resolve(payload(options.query, [{ id: 3 }])),
    });
    try {
        await settle();
        f.state.selectedMarket.value = 'first';
        f.state.onMarketChange();
        f.state.selectedMarket.value = 'second';
        f.state.onMarketChange();
        await settle();
        error.reject(new Error('obsolete failure'));
        await settle();
        assert.deepEqual(f.state.recommendations.value, [{ id: 3 }]);
        assert.equal(f.state.error.value, null);
        globalThis.__propsBoardApi.playerProps.board = async () => {
            throw new Error('Offline');
        };
        await f.state.loadBoard();
        assert.equal(f.state.error.value, 'Offline');
        assert.deepEqual(f.state.recommendations.value, [{ id: 3 }]);
    } finally {
        f.close();
    }
});

test('other sports keep recommendation requests and cannot switch into the NFL-only view', async () => {
    const f = fixture({ sport: 'NBA', view: 'all' });
    try {
        await settle();
        f.state.switchView('all');
        await settle();
        assert.equal(f.state.selectedView.value, 'recommendations');
        assert.equal(f.requests.length, 1);
        assert.equal(f.requests[0].slug, 'nba');
        assert.equal(f.requests[0].query.filters_only, undefined);
    } finally {
        f.close();
    }
});

test('cached filter choices do not retain a failed recommendation refresh warning', async () => {
    const f = fixture();
    try {
        await settle();
        globalThis.__propsBoardApi.playerProps.board = async () => {
            throw new Error('Recommendation unavailable');
        };
        await f.state.loadBoard();
        assert.equal(f.state.error.value, 'Recommendation unavailable');
        f.state.switchView('all');
        await settle();
        assert.equal(f.state.error.value, null);
        assert.equal(f.state.filtersReady.value, true);
    } finally {
        f.close();
    }
});
