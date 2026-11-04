import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let component;
let setViewerAdmin;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [vue()],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: [
                {
                    find: '@/composables/useViewerAccess',
                    replacement: fileURLToPath(
                        new URL('./fixtures/viewerAccess.mjs', import.meta.url),
                    ),
                },
                {
                    find: '@',
                    replacement: fileURLToPath(
                        new URL('../../resources/js', import.meta.url),
                    ),
                },
            ],
        },
    });
    ({ setViewerAdmin } = await server.ssrLoadModule(
        '/tests/frontend/fixtures/viewerAccess.mjs',
    ));
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
            last_5: record(3, 5),
            last_10: record(7, 10),
            last_season: record(11, 15),
            all_time: record(44, 75),
            vs_opponent: record(4, 8),
            vs_conference: record(33, 53),
        },
    },
    ...extra,
});
const render = async (
    rec = fixture(),
    nowMs = Date.parse('2026-09-20T16:00:00Z'),
    admin = false,
) => {
    setViewerAdmin(admin);
    try {
        return await renderToString(createSSRApp(component, { rec, nowMs }));
    } finally {
        setViewerAdmin(false);
    }
};
const primary = (html) => html.split('<details')[0];
const details = (html) => html.slice(html.indexOf('<details'));
const text = (html) =>
    html
        .replace(/<!--.*?-->/gs, '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
const hitRates = (html) =>
    [
        ...primary(html).matchAll(
            /<dt\b[^>]*>(.*?)<\/dt>\s*<dd\b[^>]*>(.*?)<\/dd>/gs,
        ),
    ].map(([, label, value]) => [text(label), text(value)]);
const historyRows = (html) =>
    [...details(html).matchAll(/<tr\b[^>]*>(.*?)<\/tr>/gs)]
        .map(([, row]) => row)
        .filter((row) => row.includes('scope="row"'))
        .map((row) =>
            [...row.matchAll(/<t[hd]\b[^>]*>(.*?)<\/t[hd]>/gs)].map(
                ([, cell]) => text(cell),
            ),
        );

test('pick, price and four hit-rate summaries are visible while research stays collapsed', async () => {
    const html = await render();
    const front = primary(html);
    for (const value of [
        'Test Player',
        'Under 13.5',
        'Rushing Attempts',
        '-110',
        'Test book',
        '12:00 PM CDT',
        'Estimated hit chance',
        '70.0%',
        'Season average',
        'Model estimate · not calibrated',
        'Hits at this line · historical results, not win probabilities',
    ])
        assert.ok(front.includes(value), value);
    assert.deepEqual(hitRates(html), [
        ['L5', '3/5'],
        ['L10', '7/10'],
        ['Season', '1/1'],
        ['H2H', '4/8'],
    ]);
    assert.doesNotMatch(
        front,
        /scope="row"|All available|Last season|Signal score|Probability edge/,
    );
    assert.match(html, /<details class=/);
    assert.doesNotMatch(html, /<details[^>]*\bopen\b/);
    assert.match(details(html), /Research &amp; history/);
    assert.deepEqual(historyRows(html), [
        ['This season', '1–0–0', '100.0%'],
        ['Last season', '11–4–0', '73.3%'],
        ['All available', '44–31–0', '58.7%'],
        ['vs ATL', '4–4–0', '50.0%'],
        ['vs NFC', '33–20–0', '62.3%'],
        ['Last 10', '7–3–0', '70.0%'],
        ['Last 5', '3–2–0', '60.0%'],
    ]);
});

test('missing history shows four empty summaries and five unavailable research rows', async () => {
    const rec = fixture();
    rec.stats.cover_record = null;
    rec.stats.season_summary = null;
    const html = await render(rec);
    assert.deepEqual(hitRates(html), [
        ['L5', '—'],
        ['L10', '—'],
        ['Season', '—'],
        ['H2H', '—'],
    ]);
    assert.deepEqual(historyRows(html), [
        ['This season', 'N/A', 'N/A'],
        ['Last season', 'N/A', 'N/A'],
        ['All available', 'N/A', 'N/A'],
        ['vs opponent', 'N/A', 'N/A'],
        ['vs conference', 'N/A', 'N/A'],
    ]);
    assert.doesNotMatch(html, /0\/0|\(2025\)|\(2026\)/);
});

test('public cards disclose uncalibrated estimates while model diagnostics remain admin-only', async () => {
    const rec = fixture({
        confidence_decomposition: {
            probability_method: 'discretized_normal_uncalibrated',
            history: { model_season: 2026, current_season_games: 1 },
        },
    });
    const publicHtml = await render(rec);
    assert.match(primary(publicHtml), /Model estimate · not calibrated/);
    assert.doesNotMatch(
        publicHtml,
        /Model details|Signal score|Fair market probability|Probability edge|Probabilities exclude pushes|current-team stats/,
    );

    const adminHtml = await render(rec, undefined, true);
    const researchText = text(details(adminHtml));
    assert.match(researchText, /Model estimate, not calibrated/);
    assert.match(researchText, /Probabilities exclude pushes/);
    assert.match(
        researchText,
        /Uses 2026 current-team stats; 1 current-season games/,
    );
    assert.match(researchText, /Signal score \(not win %\) 80\/100/);
    assert.match(researchText, /Under probability 70\.0%/);
    assert.match(researchText, /Fair market probability 52\.0%/);
    assert.match(researchText, /Probability edge \+18\.0 pp/);
    assert.doesNotMatch(
        primary(adminHtml),
        /Model details|Signal score|Probability edge|current-team stats/,
    );
    assert.doesNotMatch(await render(rec), /Model details/);
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

test('quote freshness respects its recorded window and missing timestamps stay explicit', async () => {
    const fetched = Date.parse('2026-09-20T15:10:00Z');
    const fresh = primary(await render(fixture(), fetched + 86400000));
    assert.match(fresh, /Line updated:/);
    assert.doesNotMatch(fresh, /Quote expired|Quote age unavailable/);

    const stale = primary(await render(fixture(), fetched + 86400001));
    assert.match(stale, /Line updated:/);
    assert.match(
        stale,
        /role="status"[^>]*>Quote expired — check the current line and price/,
    );

    const missing = primary(await render(fixture({ fetched_at: null })));
    assert.match(
        missing,
        /role="status"[^>]*>Quote age unavailable — verify the line and price/,
    );
    assert.doesNotMatch(missing, /Line updated:|Quote expired/);
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
