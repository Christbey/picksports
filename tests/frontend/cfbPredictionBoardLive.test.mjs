import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    predictionBoardLiveFields,
    liveForecastMessage,
} from '../../resources/js/lib/predictionBoardLive.ts';
import { usePredictionList } from '../../resources/js/composables/usePredictionList.ts';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createRenderer, nextTick, ssrContextKey } from 'vue';
import { fileURLToPath } from 'node:url';

test('board mapping preserves live values including zero without substituting pregame', () => {
    const live = predictionBoardLiveFields({
        live_predicted_spread: 0,
        live_predicted_total: 52,
        live_win_probability: 0.81,
        live_seconds_remaining: 0,
        live_status: 'live',
        live_updated_at: '2026-09-26T17:29:00Z',
        predicted_total: 48.5,
    });
    assert.equal(live.live_predicted_spread, 0);
    assert.equal(live.live_predicted_total, 52);
    assert.equal(live.live_win_probability, 0.81);
    assert.equal(live.live_seconds_remaining, 0);
    assert.equal(liveForecastMessage(live), null);
    assert.equal(
        predictionBoardLiveFields({ predicted_total: 48.5 })
            .live_predicted_total,
        null,
    );
});

test('missing baseline stale and waiting states have distinct customer-facing labels', () => {
    assert.match(
        liveForecastMessage({
            live_status: 'unavailable',
            live_unavailable_reason: 'missing_baseline',
        }),
        /no valid pregame baseline/,
    );
    assert.equal(
        liveForecastMessage({ live_status: 'stale' }),
        'Live update delayed',
    );
    assert.match(
        liveForecastMessage({ live_status: 'updating' }),
        /first live update/,
    );
    assert.equal(
        liveForecastMessage({
            live_status: 'unavailable',
            live_unavailable_reason: 'private_provider_error',
        }),
        'Live forecast unavailable',
    );
});

test('background refresh preserves cards and current page, avoids overlapping requests and handles failure', async () => {
    let resolve;
    let reject;
    const pages = [];
    const list = usePredictionList((page) => {
        pages.push(page);
        return new Promise((a, b) => {
            resolve = a;
            reject = b;
        });
    });
    const first = list.fetchPage(2);
    resolve({ data: [{ total: 50 }], meta: { current_page: 2 } });
    await first;
    const refresh = list.fetchPage(2, true);
    assert.equal(list.loading.value, false);
    assert.equal(list.items.value[0].total, 50);
    await list.fetchPage(2, true);
    assert.deepEqual(pages, [2, 2]);
    reject(new Error('internal details must not reach customers'));
    await refresh;
    assert.equal(list.items.value[0].total, 50);
    assert.equal(list.error.value, null);
    assert.match(list.refreshError.value, /Live refresh delayed/);
    const retry = list.fetchPage(2, true);
    resolve({ data: [{ total: 54 }], meta: { current_page: 2 } });
    await retry;
    assert.equal(list.items.value[0].total, 54);
    assert.equal(list.refreshError.value, null);
});

test('a late background response cannot overwrite a newly selected filter page', async () => {
    const pending = [];
    const list = usePredictionList(
        () => new Promise((resolve) => pending.push(resolve)),
    );
    const first = list.fetchPage(1);
    pending[0]({ data: ['old'], meta: null });
    await first;
    const background = list.fetchPage(1, true);
    const filtered = list.fetchPage(2);
    pending[2]({ data: ['new filter'], meta: null });
    await filtered;
    pending[1]({ data: ['stale'], meta: null });
    await background;
    assert.deepEqual(list.items.value, ['new filter']);
});

test('mounted CFB board polls the current page, resumes on visibility and cleans up on unmount', async () => {
    const server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'mock-board-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__cfbBoardApi;';
                },
            },
            vue(),
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
    const original = {
        document: globalThis.document,
        setInterval: globalThis.setInterval,
        clearInterval: globalThis.clearInterval,
    };
    let app;
    try {
        const { default: Board } = await server.ssrLoadModule(
            '/resources/js/components/SportPredictions.vue',
        );
        Board.render = () => null;
        const intervals = new Map();
        const listeners = new Map();
        const calls = [];
        let status = 'STATUS_SCHEDULED';
        globalThis.document = {
            hidden: false,
            addEventListener: (key, callback) => listeners.set(key, callback),
            removeEventListener: (key) => listeners.delete(key),
        };
        globalThis.setInterval = (callback, delay) => {
            intervals.set(1, { callback, delay });
            return 1;
        };
        globalThis.clearInterval = (id) => intervals.delete(id);
        globalThis.__cfbBoardApi = {
            predictions: {
                availableSeasons: async () => ({ data: [2026] }),
                availableDates: async () => ({ data: ['2026-09-26'] }),
                index: async (sport, options) => {
                    calls.push({ sport, options });
                    return {
                        data: [
                            {
                                id: 'canonical-public-id',
                                game_id: 1,
                                status,
                                projection: {},
                                game: {
                                    id: 1,
                                    game_date: '2026-09-26',
                                    status,
                                },
                            },
                        ],
                        meta: { pagination: { current_page: 2 } },
                    };
                },
            },
        };
        const renderer = createRenderer({
            createElement: () => ({}),
            createText: () => ({}),
            createComment: () => ({}),
            insert() {},
            remove() {},
            setText() {},
            setElementText() {},
            patchProp() {},
            parentNode: () => null,
            nextSibling: () => null,
        });
        const flush = async () => {
            for (let i = 0; i < 30; i++) await Promise.resolve();
            await nextTick();
        };
        app = renderer.createApp(Board, {
            config: { sport: 'cfb', filterMode: 'date' },
        });
        app.provide(ssrContextKey, { modules: new Set() });
        app.mount({});
        await flush();
        assert.equal(calls.length, 1);
        assert.equal(intervals.get(1).delay, 30000);
        intervals.get(1).callback();
        await flush();
        assert.equal(calls.length, 2);
        assert.equal(calls[1].options.query.page, 2);
        assert.equal(calls[1].options.init.cache, 'no-store');
        globalThis.document.hidden = true;
        intervals.get(1).callback();
        await flush();
        assert.equal(calls.length, 2);
        globalThis.document.hidden = false;
        status = 'STATUS_FINAL';
        listeners.get('visibilitychange')();
        await flush();
        assert.equal(calls.length, 3);
        intervals.get(1).callback();
        await flush();
        assert.equal(calls.length, 3, 'Final-only pages stop polling');
        app.unmount();
        app = null;
        assert.equal(intervals.size, 0);
        assert.equal(listeners.size, 0);
    } finally {
        app?.unmount();
        for (const [key, value] of Object.entries(original)) {
            if (value === undefined) delete globalThis[key];
            else globalThis[key] = value;
        }
        delete globalThis.__cfbBoardApi;
        await server.close();
    }
});
