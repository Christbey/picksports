import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
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
        plugins: [vue()],
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

const forecastHtml = async (prediction) => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NFLPredictionModelCard.vue',
    );
    return renderToString(
        createSSRApp(component, {
            prediction,
            awayLabel: 'DET',
            homeLabel: 'BUF',
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
    assert.match(html, /BUF favored/);
    assert.match(html, /63\.0%/);
    assert.match(html, /37\.0%/);
    assert.match(html, /not sportsbook lines or an approved bet/);
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
    assert.match(away, /DET favored/);
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
