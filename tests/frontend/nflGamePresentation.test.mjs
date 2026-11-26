import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, createRenderer, nextTick } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { formatDateLong } from '../../resources/js/composables/useFormatters.ts';
import { nflBoxScoreRows } from '../../resources/js/lib/nflBoxScore.ts';

test('calendar dates do not move backward in US timezones', () => {
    const original = process.env.TZ;
    try {
        for (const zone of [
            'America/Chicago',
            'America/Los_Angeles',
            'UTC',
            'Asia/Tokyo',
        ]) {
            process.env.TZ = zone;
            assert.equal(formatDateLong('2026-09-17'), 'September 17, 2026');
        }
        process.env.TZ = 'America/Chicago';
        assert.equal(
            formatDateLong('2026-09-18T00:15:00Z'),
            'September 17, 2026',
        );
        assert.equal(formatDateLong(null), '-');
        assert.equal(formatDateLong('invalid'), '-');
    } finally {
        if (original === undefined) delete process.env.TZ;
        else process.env.TZ = original;
    }
});

test('missing statistics never become NaN, zero percent, or an advantage', () => {
    const rows = nflBoxScoreRows({}, { interceptions: 0, fumbles_lost: 0 });
    assert.doesNotMatch(JSON.stringify(rows), /NaN|undefined|Infinity|0%/);
    assert.equal(
        rows.find((r) => r.label === 'Turnovers').away,
        '— (— INT, — FUM)',
    );
    assert.equal(
        rows.find((r) => r.label === 'Turnovers').home,
        '0 (0 INT, 0 FUM)',
    );
    assert.ok(rows.every((r) => r.better === null));
    for (const invalid of [null, '', ' ', NaN, Infinity, 'bad']) {
        assert.equal(
            nflBoxScoreRows({ total_yards: invalid }, {})[0].away,
            '—',
        );
    }
});

test('available stats preserve zero and compare numeric values and possession durations correctly', () => {
    const rows = nflBoxScoreRows(
        {
            total_yards: 0,
            interceptions: '1',
            fumbles_lost: '2',
            third_down_conversions: 2,
            third_down_attempts: 4,
            time_of_possession: '9:59',
        },
        {
            total_yards: 10,
            interceptions: 0,
            fumbles_lost: 0,
            third_down_conversions: 0,
            third_down_attempts: 0,
            time_of_possession: '10:00',
        },
    );
    assert.equal(rows[0].away, '0');
    assert.equal(rows[0].better, 'home');
    assert.equal(
        rows.find((r) => r.label === 'Turnovers').away,
        '3 (1 INT, 2 FUM)',
    );
    assert.equal(rows.find((r) => r.label === 'Turnovers').better, 'home');
    assert.equal(rows.find((r) => r.label === '3rd Down').away, '2-4 (50%)');
    assert.equal(rows.find((r) => r.label === '3rd Down').home, '0-0');
    assert.equal(rows.find((r) => r.label === '3rd Down').better, null);
    assert.equal(
        rows.find((r) => r.label === 'Time of Possession').better,
        'home',
    );
});

let server;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            vue(),
            {
                name: 'forecast-page-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => globalThis.__forecastPageApi;';
                    if (id.endsWith('/composables/useGameViewTracking.ts'))
                        return 'export const useGameViewTracking = () => {};';
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
});
after(async () => {
    await server?.close();
});

const forecastHtml = async (prediction, gameStatus, labels = {}) => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NFLPredictionModelCard.vue',
    );
    return renderToString(
        createSSRApp(component, {
            prediction,
            gameStatus,
            awayLabel: 'DET',
            homeLabel: 'BUF',
            ...labels,
            formatNumber: (value, decimals = 1) =>
                value == null ? '—' : Number(value).toFixed(decimals),
            formatSpread: (value) =>
                Number(value) > 0
                    ? `+${Number(value).toFixed(1)}`
                    : Number(value).toFixed(1),
        }),
    );
};

test('forecast leads with model spread and total, preserves sign and marks even matchups', async () => {
    const html = await forecastHtml({
        predicted_spread: 3.5,
        predicted_total: 48.5,
        win_probability: 0.63,
    });
    assert.match(html, /BUF model spread/);
    assert.match(html, /-3\.5/);
    assert.match(html, /48\.5/);
    assert.match(html, /BUF projected to win by 3\.5/);
    assert.match(html, /BUF to win/);
    assert.match(html, /aria-label="Model pick"/);
    assert.match(html, /63\.0%/);
    assert.match(html, /37\.0%/);
    assert.match(html, /Higher ratings indicate stronger teams/);
    assert.doesNotMatch(html, /<details[^>]*\bopen\b/);
    const even = await forecastHtml({
        predicted_spread: 0,
        predicted_total: 0,
        win_probability: 0,
    });
    assert.match(even, /Even matchup/);
    assert.doesNotMatch(even, /favored|Win probability unavailable/);
    const away = await forecastHtml({ predicted_spread: -3.5 });
    assert.match(away, /\+3\.5/);
    assert.match(away, /DET projected to win by 3\.5/);
    assert.match(away, /Model winner unavailable/);
});

test('missing or invalid forecast values never become zero, a favorite or a fake probability', async () => {
    for (const invalid of [null, undefined, '', ' ', 'bad', NaN, Infinity]) {
        const html = await forecastHtml({
            predicted_spread: invalid,
            predicted_total: invalid,
            win_probability: invalid,
        });
        assert.match(html, /Win probability unavailable/);
        assert.doesNotMatch(
            html,
            /NaN|Infinity|favored|Even matchup|width:|0\.0%/,
        );
    }
    for (const invalid of [-0.1, 1.1, 63]) {
        assert.match(
            await forecastHtml({ win_probability: invalid }),
            /Win probability unavailable/,
        );
    }
});

test('injury panel does not show an empty report alongside one to three injuries', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/InjuryReportCard.vue',
    );
    for (const count of [1, 2, 3, 4]) {
        const injuries = Array.from({ length: count }, (_, id) => ({
            id,
            player_id: id,
            player_name: 'Test Player',
            status: 'Out',
        }));
        const html = await renderToString(
            createSSRApp(component, {
                awayInjuries: injuries,
                homeInjuries: injuries,
            }),
        );
        assert.doesNotMatch(html, /No active injuries listed/);
        assert.match(html, /Test Player/);
    }
    const missing = await renderToString(
        createSSRApp(component, {
            awayInjuries: [],
            homeInjuries: [],
            awayInjuriesAvailable: false,
            homeInjuriesAvailable: false,
        }),
    );
    assert.match(missing, /Injury data unavailable/);
    assert.doesNotMatch(missing, /0 active|No active injuries listed/);
    const empty = await renderToString(
        createSSRApp(component, { awayInjuries: [], homeInjuries: [] }),
    );
    assert.match(empty, /No active injuries listed/);
});

test('box score renders missing source values explicitly', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NFLBoxScoreCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, { awayTeamStats: {}, homeTeamStats: {} }),
    );
    assert.match(html, /source has not supplied/);
    assert.doesNotMatch(html, /NaN|undefined|Infinity|0%/);
});

test('pregame forecast stays explicitly labeled during live play', async () => {
    const html = await forecastHtml(
        { win_probability: 0.6, predicted_total: 43.2 },
        'STATUS_IN_PROGRESS',
    );
    assert.match(html, /Pregame forecast/);
    assert.match(html, /do not update during live play/);
    assert.match(html, /Model winner/);
    assert.match(html, /Pregame win outlook/);
    assert.doesNotMatch(html, /60\.0% model win/);
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

test('CFB page converts canonical home lines once and preserves legacy margins and forecast fields', async () => {
    const { useCfbDetailedGamePage } = await server.ssrLoadModule(
        '/resources/js/composables/useCfbDetailedGamePage.ts',
    );
    for (const fixture of [
        {
            source: {
                phase: 'pregame',
                revision: 100,
                predicted_spread: -2.58,
            },
            margin: 2.58,
            team: 'KU',
            line: '-2.6',
        },
        {
            source: {
                phase: 'pregame',
                revision: 1,
                predicted_spread: 2.58,
                win_probability: 0.394126,
            },
            margin: -2.58,
            team: 'UCF',
            line: '+2.6',
        },
        {
            source: { predicted_spread: 2.58 },
            margin: 2.58,
            team: 'KU',
            line: '-2.6',
        },
        {
            source: { phase: 'pregame', revision: 1, predicted_spread: 0 },
            margin: 0,
            team: null,
            line: '0.0',
        },
        {
            source: { phase: 'pregame', revision: 1, predicted_spread: null },
            margin: '',
            team: null,
            line: null,
        },
        ...['', ' ', false, 'bad', Infinity].map((predicted_spread) => ({
            source: { phase: 'pregame', revision: 1, predicted_spread },
            margin: '',
            team: null,
            line: null,
        })),
    ]) {
        const raw = {
            id: '01m6vewnpenrbbr7vgfeaet7mk',
            sport: 'cfb',
            game_id: 644,
            win_probability: 0.605874,
            predicted_total: 53,
            confidence_context: {
                probability_status: 'uncalibrated_model_estimate',
            },
            scoring_model: { state: 'available' },
            comparison_forecasts: { version: 'test' },
            ...fixture.source,
        };
        globalThis.__forecastPageApi = {
            games: {
                show: async () => ({
                    data: {
                        id: 644,
                        status: 'STATUS_FINAL',
                        home_team: { abbreviation: 'KU' },
                        away_team: { abbreviation: 'UCF' },
                    },
                }),
            },
            predictions: { forGame: async () => ({ data: raw }) },
            stats: { teams: async () => ({ data: [] }) },
        };
        let state;
        const app = renderer.createApp({
            setup() {
                state = useCfbDetailedGamePage(644);
                return () => null;
            },
        });
        app.mount({});
        try {
            await new Promise((resolve) => setImmediate(resolve));
            await nextTick();
            const prediction = state.predictionSectionProps.value.prediction;
            assert.deepEqual(prediction, {
                ...raw,
                predicted_spread: fixture.margin,
            });
            assert.equal(raw.predicted_spread, fixture.source.predicted_spread);
            const html = await forecastHtml(prediction, 'STATUS_FINAL', {
                awayLabel: 'UCF',
                homeLabel: 'KU',
            });
            if (fixture.team) {
                assert.ok(html.includes(fixture.line));
                assert.ok(
                    html.includes(`${fixture.team} projected to win by 2.6`),
                );
            } else if (fixture.line) assert.match(html, /Even matchup/);
            else assert.match(html, /Unavailable/);
            if (fixture.team)
                assert.ok(html.includes(`${fixture.team} to win`));
            assert.match(html, /Model winner/);
            assert.match(html, /Uncalibrated model estimate/);
            assert.ok(
                html.indexOf('Uncalibrated model estimate') <
                    html.indexOf('<details'),
            );
            assert.doesNotMatch(html, /Moneyline pick/);
        } finally {
            app.unmount();
            delete globalThis.__forecastPageApi;
        }
    }
});

test('forecast does not label calibrated or unspecified estimates as uncalibrated', async () => {
    for (const confidence_context of [
        undefined,
        { probability_status: 'calibrated' },
    ]) {
        const html = await forecastHtml({
            sport: 'nfl',
            predicted_spread: 3.5,
            win_probability: 0.63,
            confidence_context,
        });
        assert.doesNotMatch(html, /Uncalibrated model estimate/);
        assert.match(html, /Model winner/);
    }
});

test('stored roster codes become plain status names without claiming current availability', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/InjuryReportCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, {
            storedListings: true,
            awayInjuries: [
                {
                    id: 1,
                    player_id: 1,
                    player_name: 'Stored Player',
                    status: 'INJURY_STATUS_OUT',
                    impact_spread: 0.2,
                    impact_total: 0.1,
                    type: 'INJURY_STATUS_OUT',
                    source_updated_at: '2022-10-01T12:00:00Z',
                    injury_date: '2022-10-01',
                },
            ],
            homeInjuries: [],
        }),
    );
    assert.match(html, /Out/);
    assert.match(html, /2022/);
    assert.match(html, /stored listings/);
    assert.match(html, /not verified current availability/);
    assert.match(html, /Model impact details/);
    assert.doesNotMatch(html, /<details[^>]*\bopen\b/);
    assert.doesNotMatch(html, /INJURY_STATUS|1 active/);
});

test('NFL comparison renders both teams, truthful seasons and missing metric samples together', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflTeamEvidencePanel.vue',
    );
    const evidence = {
        label: 'Last 5',
        sample_size: 5,
        record: { wins: 3, losses: 2, ties: 0 },
        seasons: [2025],
        from_date: '2025-12-01',
        through_date: '2025-12-29',
        metrics: {
            points_for: { value: 28, sample_size: 5 },
            turnovers: { value: 0, sample_size: 0 },
        },
    };
    const profile = { windows: { recent_5: { evidence } }, changes: [] };
    const html = await renderToString(
        createSSRApp(component, {
            workspace: true,
            away: profile,
            home: profile,
            awayLabel: 'DET',
            homeLabel: 'BUF',
            window: 'recent_5',
        }),
    );
    assert.match(html, /Team comparison metrics/);
    assert.match(html, /DET/);
    assert.match(html, /BUF/);
    assert.match(html, /Seasons: 2025/);
    assert.match(html, /28\.00/);
    assert.doesNotMatch(
        html,
        /0\.00|Team evidence selection|Evidence views|Metric group/,
    );
    assert.doesNotMatch(html, /<details[^>]*\bopen\b/);
});

test('team ratings explain Elo and compare available ratings without inventing missing edges', async () => {
    for (const [away, home, expected] of [
        [1459, 1515, 'BUF +56 rating edge'],
        [1515, 1459, 'DET +56 rating edge'],
        [1500, 1500, 'Even ratings'],
        [0, 56, 'BUF +56 rating edge'],
    ]) {
        const html = await forecastHtml({
            sport: 'nfl',
            away_elo: away,
            home_elo: home,
        });
        assert.match(html, /Team ratings/);
        assert.match(html, /Pregame Elo rating/);
        assert.ok(html.includes(expected));
        assert.match(html, /These ratings are one input to the forecast/);
        assert.match(html, /winning outright, not covering the spread/);
        assert.doesNotMatch(html, /<details[^>]*\bopen\b/);
    }
    for (const missing of [null, undefined, '', 'bad', Infinity]) {
        const html = await forecastHtml({ away_elo: missing, home_elo: 1500 });
        assert.match(html, /Rating comparison unavailable/);
        assert.doesNotMatch(html, /rating edge|NaN|Infinity/);
    }
});

test('NFL game details render stored kickoff weather and its source timing', async () => {
    const { normalizePrediction } = await server.ssrLoadModule(
        '/resources/js/composables/useNflGamePage.ts',
    );
    const html = await forecastHtml(
        normalizePrediction({
            predicted_spread: 6,
            predicted_total: 42.6,
            win_probability: 0.7,
            weather_forecast: {
                available: true,
                source: 'latest_forecast',
                provider: 'open_meteo',
                forecast_valid_at: '2026-11-27T01:00:00Z',
                received_at: '2026-11-26T20:36:42Z',
                temperature_f: 34.9,
                wind_speed_mph: 20,
                wind_gust_mph: 38.9,
                precipitation_inches: 0.098,
                precipitation_probability: 100,
            },
        }),
    );
    assert.match(html, /Game weather forecast/);
    assert.match(html, /34.9°F/);
    assert.match(html, /Wind 20 mph/);
    assert.match(html, /7:00 PM CST/);
    assert.match(html, /Latest stored forecast/);
});
