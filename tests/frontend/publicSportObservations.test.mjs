import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h, provide, reactive } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server, presentation;
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
    presentation = await server.ssrLoadModule(
        '/resources/js/lib/publicSportPresentation.ts',
    );
});
after(async () => {
    await server?.close();
});
test('public probability bars preserve observed zero and expose unknown', () => {
    assert.equal(
        presentation.predictionBarWidth({ home_win_probability: 0 }),
        '0%',
    );
    assert.equal(
        presentation.predictionBarWidth({ home_win_probability: null }),
        null,
    );
    assert.equal(
        presentation.predictionBarWidth({ home_win_probability: 67 }),
        '67%',
    );
});
test('public calendar-only and malformed dates never fabricate kickoff clocks or crash', () => {
    assert.match(presentation.formatGameDate('2026-11-26'), /Time unavailable/);
    assert.equal(
        presentation.formatGameDate('not-a-clock'),
        'Time unavailable',
    );
    assert.doesNotMatch(
        presentation.formatGameDate('2026-11-26T19:45:00Z'),
        /Time unavailable/,
    );
});
test('public numeric displays reject nonfinite state while preserving real zero', () => {
    assert.equal(presentation.formatMetric(Infinity), 'N/A');
    assert.equal(presentation.formatHomeSpreadLine('1e999'), 'N/A');
    assert.equal(presentation.formatProbability(NaN), 'N/A');
    assert.equal(presentation.formatMetric(0), '0.00');
    assert.equal(presentation.formatHomeSpreadLine(6.5), '-6.5');
    assert.equal(presentation.formatProbability(0), '0.0%');
});

test('actual public prediction component renders zero without a fabricated minimum bar', async () => {
    const { default: Component } = await server.ssrLoadModule(
        '/resources/js/components/PublicSportPredictions.vue',
    );
    const { usePublicSport, publicSportKey } = await server.ssrLoadModule(
        '/resources/js/composables/usePublicSport.ts',
    );
    const props = reactive({
        links: {},
        hasPlayerProps: false,
        featuredPredictions: [
            {
                id: 1,
                matchup: 'Synthetic Away at Home',
                game_date: '2026-11-26',
                home_win_probability: 0,
                confidence_score: null,
                predicted_spread: 0,
                pick: 'Away',
            },
        ],
    });
    const app = createSSRApp({
        setup() {
            provide(publicSportKey, usePublicSport(props));
            return () => h(Component);
        },
    });
    const html = await renderToString(app);
    assert.match(html, /width:0%/);
    assert.doesNotMatch(html, /Model edge/);
    assert.match(html, /Time unavailable/);
});

test('public coverage uses the selected side and preserves pushes as a third outcome', () => {
    const prop = {
        recommendation: 'Under',
        stats: {
            times_covered_season: { hits: 3, games: 5 },
            cover_record: {
                season: { over: 3, under: 1, pushes: 1, games: 5 },
            },
        },
    };
    const result = presentation.coverageBreakdown(prop);
    assert.equal(result.hits, 1);
    assert.equal(result.misses, 3);
    assert.equal(result.pushes, 1);
    assert.equal(result.hitPct, 20);
    assert.equal(
        presentation.coverageBreakdown({
            recommendation: 'Under',
            stats: { times_covered_season: { hits: 3, games: 5 } },
        }),
        null,
    );
});

test('actual public prop card separates selected-side wins losses and pushes', async () => {
    const { default: Component } = await server.ssrLoadModule(
        '/resources/js/components/PublicSportPropPicks.vue',
    );
    const { usePublicSport, publicSportKey } = await server.ssrLoadModule(
        '/resources/js/composables/usePublicSport.ts',
    );
    const props = reactive({
        links: {},
        hasPlayerProps: true,
        featuredProps: [
            {
                id: 1,
                recommendation: 'Under',
                player: { name: 'Synthetic Player' },
                stats: {
                    cover_record: {
                        season: { games: 5, under: 1, over: 3, pushes: 1 },
                    },
                },
            },
        ],
    });
    const app = createSSRApp({
        setup() {
            provide(publicSportKey, usePublicSport(props));
            return () => h(Component);
        },
    });
    const html = await renderToString(app);
    assert.match(html, /Covered 1/);
    assert.match(html, /Missed 3/);
    assert.match(html, /Pushes 1/);
    assert.match(html, /bg-amber-300[^>]+width:20%/);
});

test('actual public injury card labels local storage and unverified source text without claiming freshness', async () => {
    const { default: Component } = await server.ssrLoadModule(
        '/resources/js/components/PublicSportRecentInjuries.vue',
    );
    const { usePublicSport, publicSportKey } = await server.ssrLoadModule(
        '/resources/js/composables/usePublicSport.ts',
    );
    const props = reactive({
        links: {},
        hasPlayerProps: false,
        injuries: {
            recent: [
                {
                    id: 1,
                    player_name: 'Synthetic Player',
                    source_updated_at_raw: '2026-11-26 10:00:00',
                    last_stored_at: '2026-11-26 11:00:00',
                },
            ],
        },
    });
    const app = createSSRApp({
        setup() {
            provide(publicSportKey, usePublicSport(props));
            return () => h(Component);
        },
    });
    const html = await renderToString(app);
    assert.match(html, /Last stored: 2026-11-26 11:00:00/);
    assert.match(html, /Source observation: Unverified/);
    assert.match(html, /Stored source text: 2026-11-26 10:00:00/);
    assert.doesNotMatch(html, /Fresh Feed/);
});
