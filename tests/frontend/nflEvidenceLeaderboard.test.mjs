import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { fileURLToPath } from 'node:url';

let server, component, evidenceRows;
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
            '/resources/js/components/nfl/NflEvidenceLeaderboard.vue',
        )
    ).default;
    ({ evidenceRows } = await server.ssrLoadModule(
        '/resources/js/lib/nflEvidenceLeaderboard.ts',
    ));
});
after(async () => {
    await server?.close();
});
const metrics = (extra = {}) => ({
    games: 1,
    sample: 1,
    wins: 1,
    losses: 0,
    pushes: 0,
    ungraded: 0,
    hit_rate: 1,
    wilson_lower: 0.2,
    wilson_upper: 1,
    baseline_lift: null,
    paired_baseline_sample: 0,
    mean_absolute_error: null,
    brier: null,
    ...extra,
});
const row = (extra = {}) => ({
    ...metrics(),
    signal_type: 'reason_code',
    signal_key: 'qb_edge',
    label: 'QB edge',
    market: 'spread',
    model_version: 'test',
    feature_version: 'test',
    blend_version: 'test',
    definition_hash: 'abc',
    direction: null,
    direction_basis: ['model_default'],
    discovery: metrics(),
    later: metrics({ sample: 0 }),
    weeks: [],
    cohorts: { divisional: metrics() },
    identical_sample_signals: ['pressure_edge'],
    ...extra,
});
const report = (rows = []) => ({
    scope: {
        from_season: 2026,
        to_season: 2026,
        model_version: 'test',
        validation_from_week: 5,
    },
    minimum_games: 30,
    rows,
    inventory: [
        {
            evidence: 'EPA catalog',
            collection: 'Game page',
            grading: 'Not connected',
            gap: 'Freeze before kickoff',
        },
    ],
    limitations: [],
});
test('shows gaps and empty state without claiming evidence is validated', async () => {
    const html = await renderToString(
        createSSRApp(component, { report: report() }),
    );
    assert.match(html, /No eligible evidence/);
    assert.match(html, /Freeze before kickoff/);
    assert.match(html, /not approved picks/);
});
test('renders records, uncertainty and overlap warnings while keeping missing comparisons unavailable', async () => {
    const html = await renderToString(
        createSSRApp(component, { report: report([row()]) }),
    );
    assert.match(html, /QB edge/);
    assert.match(html, /Insufficient evidence/);
    assert.match(html, /Unavailable/);
    assert.match(html, /Shared evidence/);
    assert.match(html, /not held-out validation/);
});
test('filters by market, exclusive matchup cohort and evidence text', () => {
    const data = report([
        row(),
        row({ signal_key: 'other', market: 'winner' }),
        row({ signal_key: 'no_cohort', cohorts: {} }),
    ]);
    assert.equal(evidenceRows(data, 'spread', 'divisional', 'QB').length, 1);
    assert.equal(evidenceRows(data, 'spread', 'interconference', '').length, 0);
    assert.equal(evidenceRows(data, 'winner', 'all', '').length, 1);
});
test('ranks meaningful samples ahead of a perfect one-game record', () => {
    const data = report([
        row({ signal_key: 'tiny', wilson_lower: 0.9 }),
        row({ signal_key: 'larger', sample: 40, wilson_lower: 0.5 }),
    ]);
    assert.equal(
        evidenceRows(data, 'spread', 'all', '')[0].signal_key,
        'larger',
    );
});

test('keeps unmatched and unavailable states out of the default positive-evidence ranking', () => {
    const data = report([
        row({ condition_state: 'matched' }),
        row({ signal_key: 'control', condition_state: 'not_matched' }),
        row({ signal_key: 'missing', condition_state: 'insufficient_data' }),
    ]);
    assert.equal(evidenceRows(data, 'spread', 'all', '').length, 1);
    assert.equal(
        evidenceRows(data, 'spread', 'all', '', 'not_matched')[0].signal_key,
        'control',
    );
    assert.equal(
        evidenceRows(data, 'spread', 'all', '', 'unavailable')[0].signal_key,
        'missing',
    );
    assert.equal(evidenceRows(data, 'spread', 'all', '', 'all').length, 3);
});

test('renders ledger freshness, pending reasons and trial validation gates', async () => {
    const data = report([]);
    data.operations = {
        audit: {
            freshness: { last_capture: null },
            reasons: { spread: { grading_backlog: 2 } },
            games: [
                {
                    game_id: 1,
                    matchup: 'DAL at PHI',
                    market: 'spread',
                    reason: 'missing_market_line',
                    quote_status: 'missing_observation_time',
                },
            ],
            scheduler_heartbeats: [],
        },
        trials: [
            {
                artifact_id: 'trial-1',
                status: 'awaiting_confirmation',
                promoted: false,
                periods: {
                    validation: {
                        games: 0,
                        weeks: 0,
                        mean_improvement: null,
                        simultaneous_lower_bound: null,
                    },
                },
                checks: { validation_sample: false },
            },
        ],
        phase_status: {},
    };
    const html = await renderToString(
        createSSRApp(component, { report: data }),
    );
    assert.match(html, /grading backlog/);
    assert.match(html, /DAL at PHI/);
    assert.match(html, /Not yet observed/);
    assert.match(html, /awaiting confirmation/);
    assert.match(html, /No automatic promotion/);
});
