import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            ...(process.env.REVIEW_ORIGINAL === '1'
                ? [
                      {
                          name: 'frozen-before-consumer',
                          enforce: 'pre',
                          load(id) {
                              for (const path of [
                                  'resources/js/lib/nflBoxScore.ts',
                                  'resources/js/components/BettingAnalysisCard.vue',
                              ]) {
                                  if (id.endsWith(`/${path}`))
                                      return readFileSync(
                                          `/private/tmp/picksports-merged-full-5gpj4mlj/${path}`,
                                          'utf8',
                                      );
                              }
                          },
                      },
                  ]
                : []),
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

test('actual box score component preserves invalid source counts without publishing a percentage winner', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NFLBoxScoreCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, {
            awayLabel: 'AWY',
            homeLabel: 'HME',
            awayTeamStats: {
                third_down_conversions: 5,
                third_down_attempts: 1,
            },
            homeTeamStats: {
                third_down_conversions: 1,
                third_down_attempts: 4,
            },
        }),
    );
    assert.match(html, /5-1 \(invalid source counts\)/);
    assert.doesNotMatch(html, /500%/);
    assert.match(html, /AWY: 0\/11 complete stat rows/);
    assert.match(html, /HME: 1\/11 complete stat rows/);
    assert.match(html, /1-4 \(25%\)/);
});
test('actual live analysis component labels the local record clock and unknown provider freshness separately', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/BettingAnalysisCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, {
            livePrediction: {
                isLive: true,
                provisional: true,
                recordUpdatedAt: null,
                recordUpdatedAtRaw: '2026-11-27 06:00:00',
                recordClockStatus: 'unverified_storage_clock',
                sourceUpdatedAt: null,
                freshnessWarning:
                    'Provider observation freshness is unavailable.',
                liveWinProbability: null,
                preGameWinProbability: 0.5,
                preGamePredictedSpread: 0,
                preGamePredictedTotal: 0,
            },
        }),
    );
    assert.match(html, /Stored game record update \(unverified clock\):/);
    assert.match(html, /Provider observation freshness is unavailable/);
    assert.doesNotMatch(html, /Game data updated:/);
});

test('invalid passing counts do not gain a green row highlight from known yardage', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/NFLBoxScoreCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, {
            awayLabel: 'AWY',
            homeLabel: 'HME',
            awayTeamStats: {
                passing_completions: 5,
                passing_attempts: 1,
                passing_yards: 500,
            },
            homeTeamStats: {
                passing_completions: 1,
                passing_attempts: 4,
                passing_yards: 100,
            },
        }),
    );
    assert.match(html, /5-1 \(invalid source counts\), 500/);
    assert.match(html, /1-4, 100/);
    assert.doesNotMatch(html, /text-green/);
    assert.match(html, /AWY: 0\/11 complete stat rows/);
    assert.match(html, /HME: 1\/11 complete stat rows/);
});
