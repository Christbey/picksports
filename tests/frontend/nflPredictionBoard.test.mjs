import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import {
    kickoffLabel,
    nflBoardPresentation,
} from '../../resources/js/lib/nflBoardPresentation.ts';
import { researchReason } from '../../resources/js/lib/researchDecision.ts';

test('research labels separate expiry changes and blocked refresh without hiding simultaneous reasons', () => {
    assert.match(
        researchReason('research_incomplete_or_stale'),
        /incomplete or no longer matches/,
    );
    assert.doesNotMatch(
        researchReason('research_incomplete_or_stale'),
        /out of date/,
    );
    for (const [status, label] of Object.entries({
        evidence_expired: 'Evidence expired',
        prediction_changed: 'Prediction changed',
        refresh_blocked: 'Refresh blocked',
        reassessment_due: 'Reassessment needed',
    })) {
        const v = nflBoardPresentation({
            nfl_board: {
                research: {
                    status,
                    reasons: [
                        'research_evidence_expired',
                        'research_game_attempt_limit_reached',
                    ],
                },
            },
        });
        assert.equal(v.researchLabel, label);
        assert.equal(v.researchReasons.length, 2);
    }
    assert.match(
        researchReason('research_game_attempt_limit_reached'),
        /attempt limit/,
    );
    assert.match(
        researchReason('research_game_daily_budget_reached'),
        /per-game spending/,
    );
    assert.match(
        researchReason('research_prediction_comparison_missing'),
        /material change is not confirmed/,
    );
});

const prediction = (p, margin, homeLine, extra = {}) => ({
    id: 1,
    sport: 'nfl',
    game_id: 1,
    win_probability: p,
    predicted_spread: margin,
    game: {
        home_team: { abbreviation: 'HOME' },
        away_team: { abbreviation: 'AWAY' },
        kickoff_at: '2026-09-20T17:00:00Z',
    },
    pro_signal_layer: { market_context: { pick_side: 'away' } },
    nfl_board: {
        market: { home_spread: homeLine },
        research: { status: 'reviewed', decision: 'pass' },
    },
    ...extra,
});

test('winner uses home probability, never the value pick side', () => {
    const v = nflBoardPresentation(prediction(0.72, 8.8, -13.5));
    assert.equal(v.winner, 'HOME');
    assert.equal(v.winnerProbability, 0.72);
    assert.equal(v.spreadLean, 'AWAY +13.5');
    assert.equal(v.researchLabel, 'Research checked');
});
test('away favorites display their own complementary win probability', () => {
    const v = nflBoardPresentation(prediction(0.27, -9.9, 7));
    assert.equal(v.winner, 'AWAY');
    assert.equal(v.winnerProbability, 0.73);
    assert.equal(v.spreadLean, 'AWAY -7');
});
test('home underdog, home favorite, push, missing line and missing probabilities', () => {
    assert.equal(
        nflBoardPresentation(prediction(0.505, 0.3, 2.5)).spreadLean,
        'HOME +2.5',
    );
    assert.equal(
        nflBoardPresentation(prediction(0.74, 10.8, -8.5)).spreadLean,
        'HOME -8.5',
    );
    assert.equal(
        nflBoardPresentation(prediction(0.58, 3, -3)).spreadLean,
        'No edge',
    );
    assert.equal(
        nflBoardPresentation(prediction(0.58, 3, null)).spreadLean,
        'Line unavailable',
    );
    for (const p of [null, '', -1, 1.1, 'bad'])
        assert.equal(
            nflBoardPresentation(prediction(p, 0, null)).winner,
            'Unavailable',
        );
    assert.equal(
        nflBoardPresentation(prediction(0.5, 0, null)).winner,
        'Even matchup',
    );
});
test('explicit research forecast wins over older stored projection without clearing holds', () => {
    const v = nflBoardPresentation(
        prediction(0.72, 8.8, -13.5, {
            nfl_board: {
                forecast: { win_probability: 0.4, predicted_spread: -2.2 },
                research: { status: 'hold' },
                market: { home_spread: 3.5 },
            },
        }),
    );
    assert.equal(v.winner, 'AWAY');
    assert.equal(v.winnerProbability, 0.6);
    assert.equal(v.researchLabel, 'Research hold');
    assert.equal(v.spreadLean, 'Pass — small edge');
});
test('tiny edges pass on either side without hiding the winner or projection', () => {
    for (const margin of [4.7, 4.3]) {
        const v = nflBoardPresentation(prediction(0.65, margin, -4.5));
        assert.equal(v.spreadLean, 'Pass — small edge');
        assert.equal(v.winner, 'HOME');
        assert.equal(v.margin, margin);
    }
    assert.equal(
        nflBoardPresentation(prediction(0.65, 6.5, -4.5)).spreadLean,
        'HOME -4.5',
    );
    assert.equal(
        nflBoardPresentation(
            prediction(0.65, 6.5, -4.5, {
                spread_assessment: { minimum_edge_points: 3 },
            }),
        ).spreadLean,
        'Pass — small edge',
    );
});
test('kickoff uses an absolute instant with explicit timezone, missing time is not invented', () => {
    assert.match(kickoffLabel(prediction(0.5, 0, 0)), /(?:AM|PM).*\S+/);
    assert.equal(kickoffLabel({ game: {} }), 'Time pending');
    assert.equal(
        kickoffLabel({ game: { kickoff_at: 'invalid' } }),
        'Time pending',
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

test('game page preserves and renders the ATS assessment independently of win probability', async () => {
    const { normalizePrediction } = await server.ssrLoadModule(
        '/resources/js/composables/useNflGamePage.ts',
    );
    const assessment = {
        recommendation: 'pass_small_edge',
        edge_points: 0.2,
        minimum_edge_points: 2,
    };
    const normalized = normalizePrediction({
        predicted_spread: 4.7,
        spread_assessment: assessment,
    });
    assert.deepEqual(normalized.spread_assessment, assessment);
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NFLPredictionModelCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, {
            prediction: normalized,
            homeLabel: 'GB',
            awayLabel: 'ATL',
            formatNumber: (n, decimals = 1) => Number(n).toFixed(decimals),
            formatSpread: String,
        }),
    );
    assert.match(html, /Spread: pass/);
    assert.match(html, /0\.2/);
    assert.match(html, /Win probability unavailable/);
});

test('compact card renders winner and spread as separate concepts without diagnostic clutter', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/nfl/NflMatchupCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, { prediction: prediction(0.72, 8.8, -13.5) }),
    );
    assert.match(html, /Model winner/);
    assert.match(html, /HOME.*72\.0%/s);
    assert.match(html, /AWAY \+13\.5/);
    assert.match(html, /Research checked/);
    assert.doesNotMatch(html, /Trust|Moneyline:|Week 2|Spread Pass|Total Pass/);
});
test('final cards preserve grading and never label predictions as live', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/nfl/NflMatchupCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, {
            prediction: prediction(0.72, 8.8, -13.5, {
                status: 'STATUS_FINAL',
                winner_correct: false,
            }),
        }),
    );
    assert.match(html, /Projection lost/);
    assert.match(html, /Pregame winner/);
});

test('board leads with games, collapses season insights, and labels filter controls', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/nfl/NflMatchupBoard.vue',
    );
    const wrapper = {
        ...component,
        setup(props, context) {
            const bindings = component.setup(props, context);
            bindings.loading.value = false;
            bindings.predictions.value = [prediction(0.72, 8.8, -13.5)];
            bindings.selectedDate.value = '2026-09-20';
            return bindings;
        },
    };
    const html = await renderToString(createSSRApp(wrapper));
    assert.match(html, /NFL predictions/);
    assert.match(html, /aria-label="Game date"/);
    assert.match(html, /aria-label="Season"/);
    assert.match(html, /aria-expanded="false"/);
    assert.match(html, /More insights/);
    assert.doesNotMatch(html, /Super Bowl|One card per game|Visible 1/);
    assert.ok(html.indexOf('Model winner') < html.indexOf('More insights'));
});

test('stored stale lines remain visible with freshness labels despite blocked research', async () => {
    const p = prediction(0.73, 10, -7);
    p.predicted_total = 42.4;
    p.nfl_board.market = {
        home_spread: -7,
        total: 50.5,
        spread_stale: true,
        total_stale: true,
        bookmaker: 'draftkings',
        observed_at: '2026-09-26T16:10:06Z',
    };
    p.nfl_board.research = { status: 'refresh_blocked', decision: 'hold' };
    const v = nflBoardPresentation(p);
    assert.equal(v.spreadLean, 'HOME -7 (stale line)');
    assert.equal(v.marketSpread, 'HOME -7 (stale)');
    assert.equal(v.totalLean, 'Under 50.5 (stale line)');
    assert.equal(v.marketAt, '2026-09-26T16:10:06Z');
    assert.equal(v.researchLabel, 'Refresh blocked');
    assert.equal(v.researchDecision, 'hold');
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/nfl/NflMatchupCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, { prediction: p }),
    );
    assert.match(html, /HOME -7 \(stale line\)/);
    assert.match(html, /Refresh blocked/);
    assert.doesNotMatch(html, /Line unavailable/);
});

test('admin drawer retries research and refreshes the board after completion', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/nfl/NflMatchupDetailDrawer.vue',
    );
    const p = prediction(0.73, 10, -7);
    p.nfl_board.can_retry_research = true;
    let bindings;
    let updates = 0;
    const wrapper = {
        ...component,
        setup(props, context) {
            bindings = component.setup(props, context);
            return () => null;
        },
    };
    await renderToString(
        createSSRApp(wrapper, {
            prediction: p,
            open: false,
            onResearchUpdated: () => updates++,
        }),
    );
    const oldFetch = globalThis.fetch;
    const calls = [];
    globalThis.fetch = async (url, init = {}) => {
        calls.push([String(url), init.method ?? 'GET']);
        return new Response(
            JSON.stringify({
                data: {
                    run_id: 'test-run',
                    status: init.method === 'POST' ? 'queued' : 'completed',
                    message: 'Research rerun completed.',
                },
            }),
            {
                status: init.method === 'POST' ? 202 : 200,
                headers: { 'Content-Type': 'application/json' },
            },
        );
    };
    try {
        assert.equal(bindings.canRetry.value, true);
        await bindings.retryResearch();
        assert.equal(bindings.retryStatus.value, 'completed');
        assert.equal(bindings.retryBusy.value, false);
        assert.equal(updates, 1);
        assert.ok(
            calls.some(
                ([url, method]) =>
                    url === '/api/v2/admin/nfl/games/1/research-retry' &&
                    method === 'POST',
            ),
        );
        p.nfl_board.can_retry_research = false;
    } finally {
        bindings.stopRetryPolling();
        globalThis.fetch = oldFetch;
    }
});
