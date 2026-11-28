import assert from 'node:assert/strict';
import { after, afterEach, before, beforeEach, test } from 'node:test';
import { mkdtemp, appendFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createRenderer, createSSRApp, nextTick } from 'vue';
import { renderToString } from '@vue/server-renderer';

let server, cacheDir, useNflGamePage, BettingAnalysisCard, app, state;
const original = Object.fromEntries(
    [
        'document',
        'fetch',
        'setTimeout',
        'clearTimeout',
        'setInterval',
        'clearInterval',
    ].map((key) => [key, globalThis[key]]),
);
let timers, intervals, calls;
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
    period: 2,
    game_clock: '5:00',
    home_team_id: 2,
    away_team_id: 1,
    home_team: { id: 2, abbreviation: 'HOME' },
    away_team: { id: 1, abbreviation: 'AWAY' },
};
const live = {
    game: {
        id: 1722,
        status: 'STATUS_IN_PROGRESS',
        home_score: 21,
        away_score: 10,
        period: 3,
        game_clock: '12:00',
    },
    projection: {
        live_win_probability: 0.81,
        live_predicted_spread: 12.5,
        live_predicted_total: 48,
        live_seconds_remaining: 1620,
    },
    source_updated_at: null,
    record_updated_at: null,
    record_updated_at_raw: '2026-11-28 08:00:00',
    record_clock_status: 'unverified_storage_clock',
    source_clock_status: 'observation_unavailable',
    generated_at: '2026-11-28T08:00:00Z',
    warning:
        'Experimental score-and-clock estimate; not a live sportsbook edge.',
};

before(async () => {
    cacheDir = await mkdtemp(join(tmpdir(), 'ps-nfl-pregame-unavailable-'));
    server = await createServer({
        configFile: false,
        cacheDir,
        plugins: [
            {
                name: 'owned-saved-api-boundary-only',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__nflPregameUnavailableApi;';
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
    ({ useNflGamePage } = await server.ssrLoadModule(
        '/resources/js/composables/useNflGamePage.ts',
    ));
    ({ default: BettingAnalysisCard } = await server.ssrLoadModule(
        '/resources/js/components/BettingAnalysisCard.vue',
    ));
});
after(async () => {
    await server?.close();
    if (cacheDir) await rm(cacheDir, { recursive: true, force: true });
});
beforeEach(() => {
    timers = new Map();
    intervals = new Map();
    calls = [];
    let id = 0;
    globalThis.document = {
        hidden: false,
        addEventListener() {},
        removeEventListener() {},
    };
    globalThis.fetch = async () => {
        throw new Error(
            'Unexpected external fetch in owned API-boundary fixture',
        );
    };
    globalThis.setTimeout = (fn, delay) => {
        timers.set(++id, { fn, delay });
        return id;
    };
    globalThis.clearTimeout = (key) => timers.delete(key);
    globalThis.setInterval = (fn, delay) => {
        intervals.set(++id, { fn, delay });
        return id;
    };
    globalThis.clearInterval = (key) => intervals.delete(key);
});
afterEach(() => {
    app?.unmount();
    app = undefined;
    for (const [key, value] of Object.entries(original)) {
        if (value === undefined) delete globalThis[key];
        else globalThis[key] = value;
    }
    delete globalThis.__nflPregameUnavailableApi;
});
async function mount(prediction) {
    const savedPage = { data: { game, prediction, team_stats: [] } };
    const track =
        (name, value) =>
        async (...args) => {
            calls.push({ name, args });
            return value;
        };
    globalThis.__nflPregameUnavailableApi = {
        games: {
            page: track('page', savedPage),
            liveSnapshot: track('liveSnapshot', { data: live }),
        },
        stats: { teams: track('stats', { data: [] }) },
        teams: {
            games: track('recent', { data: [] }),
            trends: track('trends', {
                data: { sample_size: 0, trends: {}, locked_trends: {} },
            }),
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
    for (let i = 0; i < 40; i++) await Promise.resolve();
    await nextTick();
    return savedPage;
}
const cases = [
    [
        'absent published pregame baseline remains unavailable in the real NFL live consumer',
        null,
        null,
    ],
    [
        'normalized empty pregame values remain unavailable in the real NFL live consumer',
        {
            game_id: 1722,
            win_probability: null,
            predicted_spread: null,
            predicted_total: null,
        },
        null,
    ],
    [
        'complete observed pregame baseline remains displayed in the real NFL live consumer',
        {
            game_id: 1722,
            win_probability: 0.6,
            predicted_spread: 4,
            predicted_total: 45,
        },
        [0.6, 4, 45],
    ],
    [
        'genuine observed zero pregame baseline remains zero in the real NFL live consumer',
        {
            game_id: 1722,
            win_probability: 0,
            predicted_spread: 0,
            predicted_total: 0,
        },
        [0, 0, 0],
    ],
];
for (const [title, prediction, expected] of cases) {
    test(title, async () => {
        const savedPage = await mount(prediction);
        const publicLive = state.livePredictionData.value;
        const html = await renderToString(
            createSSRApp(BettingAnalysisCard, {
                livePrediction: publicLive,
                bettingValue: [],
            }),
        );
        const text = html
            .replace(/<[^>]*>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        if (process.env.REVIEW_NFL_PREGAME_TRACE)
            await appendFile(
                process.env.REVIEW_NFL_PREGAME_TRACE,
                JSON.stringify({
                    title,
                    savedPage,
                    liveResponse: live,
                    normalizedPrediction: state.prediction.value,
                    publicLive,
                    html,
                    text,
                    calls,
                }) + '\n',
                { mode: 0o600 },
            );
        // Raw trace precedes desired assertions so every genuine red retains its actual rendered values.
        assert.equal(calls.filter((x) => x.name === 'page').length, 1);
        assert.equal(calls.filter((x) => x.name === 'liveSnapshot').length, 1);
        assert.equal(publicLive.liveWinProbability, 0.81);
        assert.equal(publicLive.livePredictedSpread, 12.5);
        assert.equal(publicLive.livePredictedTotal, 48);
        assert.match(text, /81\.0%/);
        if (expected === null) {
            assert.equal(
                (text.match(/pregame unavailable/g) ?? []).length,
                3,
                text,
            );
            assert.deepEqual(
                [
                    publicLive.preGameWinProbability,
                    publicLive.preGamePredictedSpread,
                    publicLive.preGamePredictedTotal,
                ],
                [null, null, null],
            );
            assert.doesNotMatch(text, /was 0\.0/);
        } else {
            assert.deepEqual(
                [
                    publicLive.preGameWinProbability,
                    publicLive.preGamePredictedSpread,
                    publicLive.preGamePredictedTotal,
                ],
                expected,
            );
            assert.doesNotMatch(text, /pregame unavailable/);
            assert.match(text, expected[0] === 0 ? /was 0\.0%/ : /was 60\.0%/);
            assert.match(text, expected[1] === 0 ? /was 0\.0/ : /was -4\.0/);
            assert.match(text, expected[2] === 0 ? /was 0\.0/ : /was 45\.0/);
        }
    });
}
