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

test('research retains plain-language explanations and reserves technical codes for admins', async () => {
    globalThis.__reviewAdmin = true;
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
            mobileCompact: true,
        }),
    );
    assert.match(html, /Archived spread assessment/);
    assert.match(html, /No approved spread bet/);
    assert.equal(
        html.split('The spread did not meet the required approval threshold.')
            .length - 1,
        1,
    );
    assert.match(html, /Admin · technical reason codes/);
    assert.match(html, /specialist_market_scores_spread_tier_does_not_approve/);
    globalThis.__reviewAdmin = false;
    const publicHtml = await renderToString(
        createSSRApp(wrapper, { gameId: 1722, gameStatus: 'STATUS_FINAL' }),
    );
    assert.doesNotMatch(
        publicHtml,
        /technical reason codes|specialist_market_scores_spread_tier_does_not_approve/,
    );
    assert.match(publicHtml, /No approved spread bet/);
    delete globalThis.__reviewAdmin;
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

test('research renders evidence and history only after their controls are expanded', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NflResearchBrief.vue',
    );
    const renderResearch = async (expanded) => {
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
                                reasons: ['specialist_tier_does_not_approve'],
                            },
                            supporting: [
                                {
                                    claim: 'Detailed evidence marker',
                                    interpretation:
                                        'Detailed interpretation marker',
                                    source_url: 'https://example.com/evidence',
                                },
                            ],
                            opposing: [],
                            prop_angles: [],
                            unresolved: [],
                        },
                    },
                ];
                if (expanded) {
                    bindings.toggleGroup('supporting');
                    bindings.showHistory.value = true;
                }
                return bindings;
            },
        };
        return renderToString(createSSRApp(wrapper, { gameId: 1781 }));
    };
    const closed = await renderResearch(false);
    assert.match(closed, /<form aria-label="Matchup research controls"/);
    assert.match(closed, /aria-label="Assessment checks"/);
    assert.match(
        closed,
        /The spread did not meet the required approval threshold/,
    );
    assert.match(closed, /aria-expanded="false"/);
    assert.doesNotMatch(
        closed,
        /Detailed evidence marker|Detailed interpretation marker|example.com\/evidence|Original margin \/ total/,
    );
    const opened = await renderResearch(true);
    assert.match(opened, /aria-expanded="true"/);
    assert.match(opened, /Detailed evidence marker/);
    assert.match(opened, /Detailed interpretation marker/);
    assert.match(opened, /https:\/\/example.com\/evidence/);
    assert.match(opened, /Original margin \/ total/);
    if (process.env.RESEARCH_PREVIEW_PATH) {
        const { writeFile } = await import('node:fs/promises');
        await writeFile(
            process.env.RESEARCH_PREVIEW_PATH,
            `<!doctype html><html><head><meta charset="utf-8"><title>Game research preview</title><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><nav>Overview · Research · Trends · Roster</nav><main>${closed}</main></body></html>`,
        );
    }
});
