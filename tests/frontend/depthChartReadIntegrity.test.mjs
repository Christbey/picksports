import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { fileURLToPath } from 'node:url';
let server;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [vue()],
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
});
after(() => server.close());
test('actual depth chart renders a partial unresolved side without discarding the known side', async () => {
    const Component = (
        await server.ssrLoadModule(
            '/resources/js/components/game-page/DepthChartCard.vue',
        )
    ).default;
    const html = await renderToString(
        createSSRApp(Component, {
            awayTeam: {
                team: null,
                season: 2026,
                season_type: 2,
                before_date: null,
                entries: [],
            },
            homeTeam: {
                team: {
                    id: 1,
                    display_name: 'Known synthetic team',
                    logo: null,
                    abbreviation: 'KST',
                },
                season: 2026,
                season_type: 2,
                before_date: null,
                entries: [],
            },
        }),
    );
    assert.match(html, /Known synthetic team/);
    assert.match(html, /Team identity unavailable/);
    assert.doesNotMatch(html, /current season stat context/i);
});

test('actual team trends render withheld categories instead of claiming too few games when every category is restricted', async () => {
    const Component = (
        await server.ssrLoadModule(
            '/resources/js/components/sport-team/TeamTrendsTabbed.vue',
        )
    ).default;
    const html = await renderToString(
        createSSRApp(Component, {
            trendsData: {},
            lockedTrends: { advanced: 'premium' },
            trendLabel: (key) => key,
        }),
    );
    assert.match(html, /More Insights Available/);
    assert.match(html, /advanced/);
    assert.match(html, /premium/);
    assert.doesNotMatch(html, /Not enough games played/);
});
