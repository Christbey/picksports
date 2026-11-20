import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import {
    finiteValue,
    gameOutcome,
    recentRecord,
} from '../../resources/js/lib/gameOutcome.ts';
import {
    researchReason,
    decisionLabel,
} from '../../resources/js/lib/researchDecision.ts';
import { availabilityFreshness } from '../../resources/js/lib/dataFreshness.ts';
import { isConditionalPattern } from '../../resources/js/lib/trendContext.ts';
import { flattenApiV2Stat } from '../../resources/js/composables/useApiV2StatsAdapter.ts';
let server;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'viewer',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useViewerAccess.ts'))
                        return 'import { computed } from "vue"; export const useViewerAccess = () => ({ isAdmin: computed(() => globalThis.__reviewAdmin === true) });';
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
});
after(async () => {
    await server?.close();
});

test('archived research shows its save time without a pick verdict or model diagnostics', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflResearchBrief.vue',
    );
    const wrapper = {
        ...component,
        setup(props, context) {
            const bindings = component.setup(props, context);
            bindings.loading.value = false;
            bindings.revisions.value = [
                {
                    id: 1,
                    created_at: '2026-09-17T23:00:00Z',
                    baseline: {},
                    revised: {},
                    brief: {
                        eligibility: {
                            status: 'pass',
                            reasons: [
                                'specialist_tier_does_not_approve',
                                'specialist_market_scores_spread_tier_does_not_approve',
                            ],
                        },
                        supporting: [],
                        opposing: [],
                        unresolved: [],
                        prop_angles: [],
                    },
                },
            ];
            return bindings;
        },
    };
    const html = await renderToString(
        createSSRApp(wrapper, {
            gameId: 1722,
            gameStatus: 'STATUS_FINAL',
        }),
    );
    assert.match(html, /Archived pregame research/);
    assert.match(html, /<time datetime="2026-09-17T23:00:00Z">/);
    assert.doesNotMatch(
        html,
        /No approved spread pick|Forecast history|technical reason codes|specialist_.*does_not_approve/,
    );
});

test('record outcomes preserve ties, shutouts and unavailable scores', () => {
    const game = {
        id: 1,
        home_team_id: 2,
        away_team_id: 1,
        status: 'STATUS_FINAL',
        home_score: 0,
        away_score: 0,
    };
    assert.equal(gameOutcome(game, 1), 'T');
    assert.equal(gameOutcome({ ...game, home_score: 7 }, 2), 'W');
    assert.equal(gameOutcome({ ...game, home_score: null }, 1), null);
    assert.equal(gameOutcome(game, 3), null);
    assert.equal(recentRecord([game], 1), '0-0-1 W–L–T');
    for (const invalid of [null, undefined, '', ' ', Infinity, NaN, true])
        assert.equal(finiteValue(invalid), null);
});
test('decision labels deduplicate equivalent technical reasons and retain safe unknown explanations', () => {
    assert.equal(decisionLabel('pass'), 'No approved spread bet');
    assert.match(decisionLabel('candidate'), /Spread candidate/);
    assert.equal(
        researchReason('specialist_tier_does_not_approve'),
        researchReason('specialist_market_scores_spread_tier_does_not_approve'),
    );
    assert.match(researchReason('unknown_reason'), /Review required/);
    assert.match(researchReason('research_daily_budget_reached'), /budget/);
});
test('availability timestamps and stat timestamps are preserved without certifying freshness', () => {
    assert.match(availabilityFreshness([]), /refresh time unavailable/);
    assert.match(
        availabilityFreshness([
            { source_updated_at: '2026-09-18T00:00:00Z' },
            { source_updated_at: null },
        ]),
        /1\/2/,
    );
    assert.equal(
        flattenApiV2Stat({
            id: 1,
            stats: {},
            updated_at: '2026-09-18T00:00:00Z',
        }).updated_at,
        '2026-09-18T00:00:00Z',
    );
});
test('conditional observations cannot be treated as standalone pregame evidence', () => {
    assert.equal(
        isConditionalPattern('first_score', 'Scored first and won'),
        true,
    );
    assert.equal(
        isConditionalPattern('quarters', 'When DET lead after Q1 they win'),
        true,
    );
    assert.equal(isConditionalPattern('scoring', 'Scored 21+ in 4/5'), false);
});
test('final summary compares home-minus-away margin and total without grading mutable odds', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflFinalResultCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, {
            game: { status: 'STATUS_FINAL', home_score: 41, away_score: 31 },
            homeLabel: 'BUF',
            prediction: {
                predicted_spread: 7.3,
                predicted_total: 45,
                winner_correct: true,
            },
        }),
    );
    for (const value of [
        '10.0',
        '72.0',
        '2.7',
        '27.0',
        'Recorded winner grade:',
        '>W<',
        'not a verified pregame snapshot',
        'preserved pregame market line',
    ])
        assert.ok(html.includes(value), value);
    assert.doesNotMatch(html, /ATS win|Over win/);
    const missing = await renderToString(
        createSSRApp(component, {
            game: { status: 'STATUS_FINAL', home_score: null, away_score: 31 },
            prediction: {},
        }),
    );
    assert.match(missing, /Awaiting final scores/);
    assert.doesNotMatch(missing, /NaN|>0.0</);
    const invalidTotal = await renderToString(
        createSSRApp(component, {
            game: { status: 'STATUS_FINAL', home_score: 0, away_score: 0 },
            prediction: { predicted_total: -1 },
        }),
    );
    assert.doesNotMatch(invalidTotal, />-1.0</);
    assert.match(invalidTotal, /Unavailable/);
});

test('research shows the write-up and citations without internal review flags', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflResearchBrief.vue',
    );
    const renderResearch = async () => {
        const wrapper = {
            ...component,
            setup(props, context) {
                const bindings = component.setup(props, context);
                bindings.loading.value = false;
                bindings.revisions.value = [
                    {
                        id: 1,
                        created_at: '2026-10-11T17:00:00Z',
                        baseline: {
                            predicted_spread: 2.5,
                            predicted_total: 43,
                        },
                        revised: { predicted_spread: 3, predicted_total: 44 },
                        brief: {
                            eligibility: {
                                status: 'hold',
                                reasons: ['research_candidate_changed'],
                            },
                            supporting: [
                                {
                                    claim: 'Detailed evidence marker',
                                    interpretation:
                                        'Detailed interpretation marker',
                                    source_url: 'https://example.com/evidence',
                                },
                            ],
                            opposing: [
                                {
                                    claim: 'Counterargument marker',
                                    interpretation:
                                        'Context from the same source',
                                    source_url: 'https://example.com/evidence',
                                },
                                {
                                    claim: 'Open risk',
                                    source_url: 'javascript:alert(1)',
                                },
                            ],
                            prop_angles: [],
                            season_evidence: {
                                teams: {
                                    home: {
                                        team: 'DET',
                                        last_five: {
                                            games: 5,
                                            wins: 3,
                                            losses: 2,
                                            ties: 0,
                                            points_per_game: 24,
                                            points_allowed_per_game: 20,
                                        },
                                    },
                                },
                            },
                            unresolved: [
                                {
                                    claim: 'Routine QB announcement marker',
                                    reason_code: 'source_gap',
                                    concern_type: 'routine_confirmation',
                                    scope: 'game',
                                    blocking: true,
                                },
                                {
                                    claim: 'Prop-only workload marker',
                                    reason_code: 'material_availability',
                                    concern_type: 'injury',
                                    scope: 'props',
                                    blocking: true,
                                    evidence_kind: 'document',
                                    evidence_status: 'reference_verified',
                                    source_url: 'https://example.com/evidence',
                                },
                                {
                                    claim: 'Fresh market quote marker',
                                    reason_code: 'market_data_gap',
                                    scope: 'spread',
                                    blocking: false,
                                },
                                {
                                    claim: 'Unverified injury marker',
                                    reason_code: 'material_availability',
                                    concern_type: 'injury',
                                    scope: 'spread',
                                    blocking: true,
                                    evidence_kind: 'document',
                                    evidence_status: 'unverified_question',
                                    source_url: 'https://example.com/evidence',
                                },
                                {
                                    claim: 'Starting quarterback injury marker',
                                    reason_code: 'material_availability',
                                    concern_type: 'injury',
                                    scope: 'spread',
                                    blocking: true,
                                    evidence_kind: 'document',
                                    evidence_status: 'reference_verified',
                                    source_url: 'https://example.com/evidence',
                                },
                            ],
                        },
                    },
                ];
                return bindings;
            },
        };
        return renderToString(createSSRApp(wrapper, { gameId: 1781 }));
    };
    const closed = await renderResearch();
    assert.doesNotMatch(closed, /No approved spread pick/);
    assert.match(closed, /Detailed evidence marker/);
    assert.match(closed, /Detailed interpretation marker/);
    assert.match(closed, /Counterargument marker/);
    assert.match(closed, /Starting quarterback injury marker/);
    assert.match(closed, /Lineup risks/);
    assert.doesNotMatch(
        closed,
        /Routine QB announcement marker|Prop-only workload marker|Fresh market quote marker|Unverified injury marker/,
    );
    assert.match(closed, /\[1\] example.com/);
    assert.equal(
        closed.match(/href="https:\/\/example.com\/evidence"/g)?.length,
        4,
    );
    assert.match(closed, /Recent form/);
    assert.match(closed, /3–2 in 5 games/);
    assert.match(closed, /24 scored and 20 allowed per game/);
    assert.match(closed, /References/);
    assert.doesNotMatch(closed, /javascript:|Player prop context/);
    assert.doesNotMatch(
        closed,
        /forecast changed|research_candidate_changed|Assessment details|Forecast history/,
    );
    if (process.env.RESEARCH_PREVIEW_PATH) {
        const { writeFile } = await import('node:fs/promises');
        await writeFile(
            process.env.RESEARCH_PREVIEW_PATH,
            `<!doctype html><html><head><meta charset="utf-8"><title>Game research preview</title><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><nav>Overview · Research · Trends · Roster</nav><main>${closed}</main></body></html>`,
        );
    }
});

test('NFL page normalizes canonical home handicaps without changing historical margins', async () => {
    const { normalizePrediction } = await server.ssrLoadModule(
        '/resources/js/composables/useNflGamePage.ts',
    );
    assert.equal(
        normalizePrediction({
            id: 'native',
            phase: 'pregame',
            revision: 1,
            predicted_spread: -7,
        }).predicted_spread,
        7,
    );
    assert.equal(
        normalizePrediction({ id: 1, predicted_spread: 7 }).predicted_spread,
        7,
    );
    assert.equal(
        normalizePrediction({
            id: 'native',
            phase: 'pregame',
            revision: 1,
            predicted_spread: null,
        }).predicted_spread,
        '',
    );
});

test('final result displays archived pregame projections separately without native or betting claims', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflFinalResultCard.vue',
    );
    const archive = {
        source: 'legacy_archive',
        prediction_id: 10,
        snapshot_id: 20,
        generated_at: '2026-09-01T12:00:00Z',
        known_before_kickoff: true,
        predicted_spread: 3.5,
        predicted_total: 43,
        win_probability: 0.6,
    };
    const html = await renderToString(
        createSSRApp(component, {
            game: { status: 'STATUS_FINAL', home_score: 24, away_score: 17 },
            prediction: null,
            historicalPrediction: archive,
        }),
    );
    assert.match(html, /Archived pregame forecast/);
    assert.match(html, /observed before kickoff/);
    assert.match(html, /no betting approval/);
    assert.ok(html.includes('3.5'));
    assert.ok(html.includes('43.0'));
    assert.doesNotMatch(html, /not a verified pregame snapshot/);
    const native = await renderToString(
        createSSRApp(component, {
            game: { status: 'STATUS_FINAL', home_score: 24, away_score: 17 },
            prediction: { predicted_spread: 5, predicted_total: 44 },
            historicalPrediction: archive,
        }),
    );
    assert.doesNotMatch(native, /Archived pregame forecast/);
    assert.ok(native.includes('44.0'));
});

test('NFL forecast source time is retained from stored timestamps without inventing query time', async () => {
    const { normalizePrediction } = await server.ssrLoadModule(
        '/resources/js/composables/useNflGamePage.ts',
    );
    assert.equal(
        normalizePrediction({ updated_at: '2026-07-20T12:00:00Z' })
            .source_updated_at,
        '2026-07-20T12:00:00Z',
    );
    assert.equal(
        normalizePrediction({
            published_at: '2026-07-19T12:00:00Z',
            updated_at: '2026-07-20T12:00:00Z',
        }).source_updated_at,
        '2026-07-19T12:00:00Z',
    );
    assert.equal(
        normalizePrediction({ updated_at: 'invalid' }).source_updated_at,
        null,
    );
});
