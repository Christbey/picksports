import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let component;
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
    component = (
        await server.ssrLoadModule(
            '/resources/js/components/player-props/NflPlayerPropCard.vue',
        )
    ).default;
});
after(async () => {
    await server?.close();
});

const record = (wins, games, pushes = 0) => ({
    wins,
    games,
    pushes,
    losses: games - wins - pushes,
    win_rate: games > pushes ? (wins / (games - pushes)) * 100 : null,
});
const fixture = (extra = {}) => ({
    id: 7,
    player: { name: 'Test Player', team: 'CAR', position: 'RB', url: null },
    recommendation: 'Under',
    line: 13.5,
    market: 'Rushing Attempts',
    odds: -110,
    bookmaker: 'Test book',
    confidence: 80,
    model_over_probability: 30,
    market_over_probability: 48,
    edge_probability: 18,
    fetched_at: '2026-09-20T15:10:00Z',
    freshness_hours: 24,
    game: {
        away_team: 'CAR',
        home_team: 'ATL',
        starts_at: '2026-09-20T17:00:00Z',
        kickoff_label: 'Sep 20, 12:00 PM CDT',
    },
    stats: {
        season_avg: 0,
        historical_avg: 10,
        recent_avg: 9,
        last5_avg: 8,
        season_summary: {
            season: 2026,
            games: 1,
            opponent: 'ATL',
            opponent_conference: 'NFC',
        },
        cover_record: {
            season: record(1, 1),
            last_season: record(11, 15),
            all_time: record(44, 75),
            vs_opponent: record(4, 8),
            vs_conference: record(33, 53),
        },
    },
    ...extra,
});
const render = (rec = fixture(), nowMs = Date.parse('2026-09-20T16:00:00Z')) =>
    renderToString(createSSRApp(component, { rec, nowMs }));
const primary = (html) => html.split('<details')[0];

test('pick, price and exactly five history rows are visible without expanding details', async () => {
    const html = await render();
    const front = primary(html);
    for (const value of [
        'Test Player',
        'Under 13.5',
        'Rushing Attempts',
        '-110',
        'Test book',
        'This season',
        '(2026)',
        'Last season',
        '(2025)',
        'All available',
        'vs ATL',
        'vs NFC',
        '1/1',
        '11/15',
        '44/75',
        '4/8',
        '33/53',
        '12:00 PM CDT',
    ])
        assert.ok(front.includes(value), value);
    assert.equal((front.match(/scope="row"/g) ?? []).length, 5);
    assert.doesNotMatch(
        front,
        /Pending|Signal score|Probability edge|Quote fetched|Last 17/,
    );
    assert.match(html, /<details class=/);
    assert.doesNotMatch(html, /<details[^>]*\bopen\b/);
    assert.match(html, /More details/);
    assert.match(html, /70\.0%/);
    assert.match(html, /52\.0%/);
    assert.match(html, /\+18\.0 pp/);
});

test('missing history remains five N/A rows, never zero covers', async () => {
    const rec = fixture();
    rec.stats.cover_record = null;
    rec.stats.season_summary = null;
    const html = primary(await render(rec));
    assert.equal((html.match(/scope="row"/g) ?? []).length, 5);
    assert.equal((html.match(/<td[^>]*>\s*N\/A/g) ?? []).length, 5);
    assert.doesNotMatch(html, /0\/0|\(2025\)|\(2026\)/);
});

test('zero wins and pushes are explicit; win rate excludes pushes', async () => {
    const rec = fixture();
    rec.stats.cover_record.season = record(0, 2, 1);
    const html = await render(rec);
    assert.match(primary(html), /0\/2/);
    assert.match(primary(html), /1 push/);
    assert.match(html, /0–1–1/);
    assert.match(html, /Games include pushes; win % excludes them/);
});

test('expired and unknown quote warnings stay visible at the daily boundary', async () => {
    const fetched = Date.parse('2026-09-20T15:10:00Z');
    assert.doesNotMatch(
        primary(await render(fixture(), fetched + 86400000)),
        /Quote expired/,
    );
    assert.match(
        primary(await render(fixture(), fetched + 86400001)),
        /Quote expired/,
    );
    assert.match(
        primary(await render(fixture({ fetched_at: null }))),
        /Quote age unavailable/,
    );
});

test('settled results remain visible and side aware, with zero actual values retained', async () => {
    for (const [side, actual, line, expected] of [
        ['Under', 0, 13.5, 'Won'],
        ['Under', 14, 13.5, 'Lost'],
        ['Over', 14, 13.5, 'Won'],
        ['Over', 0, 13.5, 'Lost'],
        ['Under', 13, 13, 'Push'],
    ]) {
        const html = primary(
            await render(
                fixture({
                    recommendation: side,
                    actual_value: actual,
                    line,
                    graded_at: '2026-09-20T20:00:00Z',
                }),
            ),
        );
        assert.match(html, new RegExp(`<strong>${expected}</strong>`));
        assert.match(html, new RegExp(`Actual: ${actual}`));
    }
    assert.doesNotMatch(
        primary(await render(fixture({ actual_value: 0 }))),
        /Actual:/,
    );
});
