import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, nextTick } from 'vue';
import { renderToString } from 'vue/server-renderer';
import {
    distinctMatchupSignals,
    matchupChecklist,
    signalStatus,
} from '../../resources/js/lib/nflMatchupSignals.ts';

const signal = (id, status = 'matched', metric = 'epa', offense = 1) => ({
    id,
    label: `EPA matchup ${id}`,
    category: 'overall',
    status,
    offense_team_id: offense,
    defense_team_id: offense === 1 ? 2 : 1,
    reason: status === 'insufficient_data' ? 'minimum_games_not_met' : null,
    evidence: {
        metric,
        source: 'nflverse_pbp_plays',
        league_teams: 32,
        offense: { value: 0, rank: 4, games: 3, plays: 180 },
        defense: { value: -0.1, rank: 3, games: 3, plays: 180 },
    },
});
const side = (team_id, label) => ({
    team_id,
    label,
    window: 'Current and previous 3 regular seasons',
    limitations: ['Not validated betting edges.'],
    market_records: {
        ats: {
            status: 'unavailable',
            reason: 'No immutable historical quote.',
        },
    },
    records: [
        {
            id: 'home',
            catalog_id: 321,
            label: 'Home record',
            definition: 'Non-neutral home games.',
            minimum_sample: 3,
            sample_size: 2,
            record: { wins: 1, losses: 0, ties: 1 },
            status: 'insufficient_data',
            from_date: '2026-09-01',
            through_date: '2026-09-08',
            game_ids: [1, 2],
        },
    ],
});
const fixture = () => ({
    generated_at: '2026-09-19T22:00:00Z',
    matchup: {
        version: 'v1',
        mode: 'descriptive_only',
        season: 2026,
        cutoff_at: '2026-09-20T17:00:00Z',
        window: 'season_to_date',
        minimum_games: 3,
        minimum_league_teams: 32,
        summary: { supported_rules: 32 },
        signals: [signal(1), signal(5)],
        catalog: [
            {
                id: 1,
                label: 'EPA matchup 1',
                category: 'overall',
                support: 'implemented',
                reason: null,
            },
            {
                id: 201,
                label: 'QB against man coverage',
                category: 'quarterback_scheme',
                support: 'unavailable',
                reason: 'Charting required.',
            },
        ],
        limitations: ['Current stored history is not an as-known backtest.'],
    },
    situational: { home: side(1, 'BUF'), away: side(2, 'DET') },
});

test('correlated top-5/top-10 matches are one highlight per metric and direction', () => {
    const result = distinctMatchupSignals([
        signal(1),
        signal(5),
        signal(51, 'matched', 'pass_epa'),
        signal(2, 'matched', 'epa', 2),
        signal(3, 'not_matched'),
        signal(4, 'insufficient_data'),
    ]);
    assert.deepEqual(
        result.map((s) => s.id),
        [1, 51, 2],
    );
    assert.equal(signalStatus('not_matched'), 'Condition not met');
    assert.equal(signalStatus('insufficient_data'), 'Insufficient history');
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

test('evidence displays zero EPA, ties, sample limits and no approval claim', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflMatchupSignalEvidence.vue',
    );
    const html = await renderToString(
        createSSRApp(component, { data: fixture() }),
    );
    assert.match(html, /Offense 0\.000/);
    assert.match(html, /1–0–1/);
    assert.match(html, /Insufficient history/);
    assert.match(html, /not approved bets/);
    assert.match(html, /No immutable historical quote/);
    assert.match(html, /Current stored history is not an as-known backtest/);
    assert.match(html, /Charting required/);
    assert.doesNotMatch(html, /<details[^>]*\sopen[\s>]/);
});

test('empty or incomplete evidence never becomes a negative matchup', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflMatchupSignalEvidence.vue',
    );
    const data = fixture();
    data.matchup.signals = [signal(1, 'insufficient_data')];
    data.matchup.signals[0].evidence.offense.value = null;
    const html = await renderToString(createSSRApp(component, { data }));
    assert.match(html, /Missing history is not evidence of a disadvantage/);
    assert.match(html, /Offense Unavailable/);
    assert.match(html, /Insufficient history/);
});

test('full checklist retains every item without altering its source or inferring missing results', () => {
    const data = fixture();
    data.matchup.catalog = Array.from({ length: 353 }, (_, i) => ({
        id: i + 1,
        label: `Item ${i + 1}`,
        category: 'overall',
        support: 'unavailable',
        reason: 'Needs data',
    }));
    const before = JSON.stringify(data);
    const checklist = matchupChecklist(data);
    assert.equal(checklist.length, 353);
    assert.equal(checklist[0].status, 'matched');
    assert.equal(checklist[320].status, 'insufficient_data');
    assert.equal(checklist[352].status, 'unavailable');
    assert.equal(JSON.stringify(data), before);
});

test('catalog is searchable and paginated and previous season stays explicitly historical', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflMatchupSignalEvidence.vue',
    );
    const data = fixture();
    data.matchup.window = 'previous_season';
    data.matchup.season = 2025;
    data.matchup.catalog = Array.from({ length: 353 }, (_, i) => ({
        id: i + 1,
        label: `Checklist item ${i + 1}`,
        category: 'overall',
        support: 'unavailable',
        reason: 'Needs data',
    }));
    const html = await renderToString(createSSRApp(component, { data }));
    assert.match(html, /Full checklist · 353 items/);
    assert.match(html, /Search matchup checklist/);
    assert.match(html, /Showing 20 of 353/);
    assert.match(html, /Show 20 more items/);
    assert.doesNotMatch(html, /#21 Checklist item/);
    assert.match(html, /Historical context: 2025 only/);
    assert.match(html, /Prediction effect: none/);
});

test('lazy wrapper fetches once when opened and exposes retry on failure', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflMatchupSignals.vue',
    );
    let bindings;
    const wrapper = {
        ...component,
        setup(props, context) {
            bindings = component.setup(props, context);
            return bindings;
        },
    };
    const originalFetch = globalThis.fetch;
    let calls = 0;
    globalThis.fetch = async () => {
        calls++;
        return { ok: true, json: async () => ({ data: fixture() }) };
    };
    try {
        await renderToString(createSSRApp(wrapper, { gameId: 1722 }));
        assert.equal(calls, 0);
        bindings.toggle({ target: { open: true } });
        await new Promise((resolve) => setImmediate(resolve));
        await nextTick();
        assert.equal(calls, 1);
        assert.equal(bindings.data.value.matchup.mode, 'descriptive_only');
        bindings.toggle({ target: { open: false } });
        bindings.toggle({ target: { open: true } });
        assert.equal(calls, 1);
        bindings.data.value = null;
        globalThis.fetch = async () => {
            calls++;
            return { ok: false };
        };
        await bindings.load();
        assert.match(bindings.error.value, /No result is inferred/);
        assert.equal(bindings.loading.value, false);
    } finally {
        globalThis.fetch = originalFetch;
    }
});
