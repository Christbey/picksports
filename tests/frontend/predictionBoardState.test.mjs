import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { createRenderer, h, nextTick, reactive } from 'vue';
let server, usePredictionBoard, presentation;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'prediction-board-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__predictionBoardApi;';
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
    ({ usePredictionBoard } = await server.ssrLoadModule(
        '/resources/js/composables/usePredictionBoard.ts',
    ));
    presentation = await server.ssrLoadModule(
        '/resources/js/lib/predictionBoardPresentation.ts',
    );
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
const flush = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
};
const row = (id = 1, extra = {}) => ({
    id: `forecast-${id}`,
    game_id: id,
    projection: {
        home_win_probability: 0.6,
        away_win_probability: 0.4,
        predicted_spread: 0,
        predicted_total: 40,
    },
    game: {
        id,
        game_date: '2026-09-26',
        season_type: 2,
        week: 0,
        status: 'STATUS_SCHEDULED',
        home_team: {
            abbreviation: 'HOM',
            name: 'Tigers',
            location: 'Home City',
        },
        away_team: { abbreviation: 'AWY', name: 'Bears' },
    },
    ...extra,
});
const response = (data = [row()], page = 1) => ({
    data,
    meta: { pagination: { current_page: page, last_page: 3, total: 3 } },
});
function mount(
    config,
    index = async () => response(),
    seasons = async () => ({ data: [2026, 2025] }),
) {
    const original = {
        document: globalThis.document,
        setInterval: globalThis.setInterval,
        clearInterval: globalThis.clearInterval,
    };
    const calls = [],
        intervals = new Map(),
        listeners = new Map();
    globalThis.document = {
        hidden: false,
        addEventListener: (key, fn) => listeners.set(key, fn),
        removeEventListener: (key) => listeners.delete(key),
    };
    globalThis.setInterval = (fn, delay) => {
        intervals.set(1, { fn, delay });
        return 1;
    };
    globalThis.clearInterval = (id) => intervals.delete(id);
    globalThis.__predictionBoardApi = {
        predictions: {
            availableSeasons: async (sport, options) => {
                calls.push({ kind: 'seasons', sport, options });
                return seasons();
            },
            availableDates: async (sport, options) => {
                calls.push({ kind: 'dates', sport, options });
                return { data: ['2026-09-26'] };
            },
            index: async (sport, options) => {
                calls.push({ kind: 'index', sport, options });
                return index(sport, options);
            },
        },
    };
    let state;
    const props = reactive({ config: { title: 'Predictions', ...config } });
    const app = renderer.createApp({
        setup() {
            state = usePredictionBoard(props);
            return () => h('div');
        },
    });
    app.mount({});
    return {
        state,
        props,
        calls,
        intervals,
        listeners,
        close() {
            app.unmount();
            Object.assign(globalThis, original);
            delete globalThis.__predictionBoardApi;
        },
    };
}
for (const filterMode of ['date', 'none', 'seasonWeek'])
    test(`board ${filterMode} bootstrap retains request order and exact query fields`, async () => {
        const board = mount({
            sport: 'cfb',
            filterMode,
            seasonWeekConfig: { regularSeasonWeeks: 15, postseasonOptions: [] },
        });
        try {
            await flush();
            assert.deepEqual(
                board.calls.map((x) => x.kind),
                filterMode === 'none'
                    ? ['seasons', 'index']
                    : filterMode === 'date'
                      ? ['seasons', 'dates', 'index']
                      : ['seasons', 'dates', 'index', 'index'],
            );
            const call = board.calls.at(-1);
            assert.equal(call.options.init.cache, 'no-store');
            assert.equal(call.options.query.page, 1);
            assert.equal(call.options.query.season, '2026');
            if (filterMode === 'date') {
                assert.equal(call.options.query.from_date, '2026-09-26');
                assert.equal(call.options.query.to_date, '2026-09-26');
            }
            if (filterMode === 'seasonWeek') {
                assert.equal(call.options.query.week, '0');
                assert.equal(call.options.query.season_type, '2');
                assert.equal(board.state.weekOptions.value[0].label, 'Week 0');
            }
            assert.equal(board.state.predictions.value[0].id, 'forecast-1');
            assert.equal(board.intervals.get(1).delay, 30000);
        } finally {
            board.close();
        }
        assert.equal(board.intervals.size, 0);
        assert.equal(board.listeners.size, 0);
    });
test('CFB starts with the resolved week and retries failed metadata without fetching a forecast to identify the week', async () => {
    let metadataRequests = 0;
    const version = 'a'.repeat(64);
    const board = mount(
        { sport: 'cfb', filterMode: 'seasonWeek' },
        async () => ({
            ...response(),
            meta: {
                ...response().meta,
                board: { version, mode: 'replace' },
            },
        }),
        async () => {
            if (++metadataRequests === 1) return null;
            return {
                data: [2026, 2025],
                meta: {
                    board_period: { season: 2026, season_type: '2', week: 0 },
                },
            };
        },
    );

    try {
        await flush();
        assert.match(
            board.state.error.value,
            /Failed to fetch available seasons/,
        );
        assert.deepEqual(
            board.calls.map((call) => call.kind),
            ['seasons'],
        );
        await board.state.retryPredictions();
        assert.deepEqual(
            board.calls.map((call) => call.kind),
            ['seasons', 'seasons', 'index'],
        );
        assert.deepEqual(board.calls[1].options.query, { view: 'board' });
        const query = { page: 1, season: '2026', season_type: '2', week: '0' };
        assert.deepEqual(board.calls[2].options.query, {
            ...query,
            view: 'board',
        });
        assert.equal(board.state.predictions.value[0].id, 'forecast-1');
        await board.state.fetchPredictions(1, true);
        assert.deepEqual(board.calls.at(-1).options.query, {
            ...query,
            view: 'live',
            board_version: version,
        });
    } finally {
        board.close();
    }
});

test('foreground requests supersede old responses while background requests retain cards and cannot overlap', async () => {
    const pending = [];
    const board = mount({ sport: 'cfb', filterMode: 'none' }, (_, options) => {
        const request = deferred();
        pending.push({ ...request, page: options.query.page });
        return request.promise;
    });
    try {
        await flush();
        pending[0].resolve(response());
        await flush();
        const second = board.state.fetchPredictions(2);
        const third = board.state.fetchPredictions(3);
        await board.state.fetchPredictions(3, true);
        assert.equal(pending.length, 3);
        pending[2].resolve(response([row(3)], 3));
        await third;
        pending[1].resolve(response([row(2)], 2));
        await second;
        assert.equal(board.state.predictions.value[0].game.id, 3);
        assert.equal(board.state.loading.value, false);
        const refresh = board.state.fetchPredictions(3, true);
        await board.state.fetchPredictions(3, true);
        assert.equal(pending.length, 4);
        assert.equal(board.state.predictions.value[0].game.id, 3);
        assert.equal(board.state.loading.value, false);
        pending[3].reject(new Error('Internal failure'));
        await refresh;
        assert.equal(board.state.error.value, null);
        assert.match(board.state.refreshError.value, /Live refresh delayed/);
        assert.equal(board.state.meta.value.current_page, 3);
    } finally {
        board.close();
    }
});
test('season-type changes clear the week synchronously and search/recommended views stay local', async () => {
    const board = mount(
        {
            sport: 'cfb',
            filterMode: 'seasonWeek',
            seasonWeekConfig: { regularSeasonWeeks: 15, postseasonOptions: [] },
        },
        async () =>
            response([
                row(1, { value_signal: { has_playable_value: true } }),
                row(2),
            ]),
    );
    try {
        await flush();
        const requests = board.calls.length;
        board.state.week.value = '7';
        board.state.seasonType.value = 'Postseason';
        assert.equal(board.state.week.value, '');
        board.state.searchQuery.value = ' tigers ';
        board.state.betViewMode.value = 'recommended';
        await nextTick();
        assert.equal(board.state.filteredPredictions.value.length, 1);
        assert.equal(board.state.recommendedBetCount.value, 1);
        assert.equal(board.calls.length, requests);
        board.state.applyFilters();
        await flush();
        assert.equal(board.calls.at(-1).options.query.season_type, '3');
        assert.equal('week' in board.calls.at(-1).options.query, false);
    } finally {
        board.close();
    }
});
test('API mapping keeps zero/live/graded values and team fallbacks without substituting pregame fields', () => {
    const mapped = presentation.mapV2Prediction(
        row(1, {
            status: 'STATUS_IN_PROGRESS',
            actual_total: 0,
            winner_correct: false,
            live_win_probability: 0,
            live_predicted_total: 0,
            live_predicted_spread: 0,
            live_seconds_remaining: 0,
        }),
    );
    assert.equal(mapped.id, 'forecast-1');
    assert.equal(mapped.predicted_spread, 0);
    assert.equal(mapped.live_win_probability, 0);
    assert.equal(mapped.live_predicted_total, 0);
    assert.equal(mapped.actual_total, 0);
    assert.equal(mapped.winner_correct, false);
    assert.equal(mapped.game.status, 'STATUS_IN_PROGRESS');
    assert.equal(mapped.game.home_team.school, 'Home City');
    assert.equal(
        presentation.mapV2Prediction(row()).live_predicted_total,
        null,
    );
});
test('recommended filters preserve sport-specific gates and model-context precedence', () => {
    const candidate = { betting_value: [{ edge: 5 }] };
    assert.equal(presentation.isBoardRecommendedBet(candidate, 'nba'), true);
    assert.equal(presentation.isBoardRecommendedBet(candidate, 'nfl'), false);
    assert.equal(
        presentation.isBoardRecommendedBet(
            { value_signal: { has_playable_value: true } },
            'nfl',
        ),
        true,
    );
    assert.equal(
        presentation.isBoardRecommendedBet(
            {
                model_bet_context: {},
                value_signal: { has_playable_value: true },
            },
            'nfl',
        ),
        false,
    );
    assert.equal(
        presentation.isBoardRecommendedBet(
            { prediction_analysis: { bet_classification: ' RECOMMENDED ' } },
            'cfb',
        ),
        true,
    );
});

test('retry repeats a failed page with current filters and ignores duplicate clicks', async () => {
    const pending = deferred();
    let fail = false;
    const board = mount(
        { sport: 'nba', filterMode: 'date' },
        async (_, options) => {
            if (fail) throw new Error('Offline');
            if (options.query.page === 2) return pending.promise;
            return response();
        },
    );
    try {
        await flush();
        fail = true;
        await board.state.fetchPredictions(2);
        assert.equal(board.state.error.value, 'Offline');
        fail = false;
        const retry = board.state.retryPredictions();
        await board.state.retryPredictions();
        assert.equal(board.calls.at(-1).options.query.page, 2);
        assert.equal(board.calls.at(-1).options.query.from_date, '2026-09-26');
        assert.equal(board.calls.filter((c) => c.kind === 'index').length, 3);
        pending.resolve(response([row(2)], 2));
        await retry;
        assert.equal(board.state.error.value, null);
        assert.equal(board.state.meta.value.current_page, 2);
    } finally {
        board.close();
    }
});
test('retry restarts failed metadata bootstrap before requesting predictions', async () => {
    const board = mount({ sport: 'nba', filterMode: 'date' });
    try {
        globalThis.__predictionBoardApi.predictions.availableDates =
            async () => {
                throw new Error('Dates offline');
            };
        await flush();
        assert.equal(board.state.error.value, 'Dates offline');
        globalThis.__predictionBoardApi.predictions.availableDates =
            async () => ({ data: ['2026-09-26'] });
        await board.state.retryPredictions();
        assert.equal(board.state.error.value, null);
        assert.equal(board.state.predictions.value.length, 1);
        assert.equal(board.calls.at(-1).options.query.from_date, '2026-09-26');
    } finally {
        board.close();
    }
});
