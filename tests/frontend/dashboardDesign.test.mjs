import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';

let server, Overview, Sections, Empty;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'dashboard-route-fixture',
                enforce: 'pre',
                resolveId(id) {
                    if (id.endsWith('/routes'))
                        return 'virtual:dashboard-routes';
                },
                load(id) {
                    if (id === 'virtual:dashboard-routes')
                        return "export const performance=()=>({url:'/performance',method:'get'});";
                },
            },
            vue(),
        ],
        resolve: {
            alias: {
                '@': fileURLToPath(
                    new URL('../../resources/js', import.meta.url),
                ),
            },
        },
        server: { middlewareMode: true, hmr: false },
        appType: 'custom',
    });
    const load = async (name) =>
        (
            await server.ssrLoadModule(
                `/resources/js/components/dashboard/${name}.vue`,
            )
        ).default;
    Overview = await load('DashboardOverview');
    Sections = await load('DashboardSportSections');
    Empty = await load('DashboardEmptyState');
});
after(async () => server?.close());
const render = (component, props) =>
    renderToString(createSSRApp({ render: () => h(component, props) }));

for (const status of ['passing', 'degraded', 'unknown']) {
    test(`overview retains server counts and ${status} health status`, async () => {
        const html = await render(Overview, {
            stats: {
                total_predictions_today: 0,
                total_games_today: 12,
                healthcheck_status: status,
            },
        });
        assert.match(html, />0<\/p>/);
        assert.match(html, />12<\/p>/);
        assert.match(html, new RegExp(`>${status}<`));
        assert.match(
            html,
            status === 'passing' ? /text-emerald-700/ : /text-red-700/,
        );
        assert.match(html, /href="\/performance"/);
        assert.match(html, /<h1[^>]*id="dashboard-title"/);
    });
}
test('league sections preserve payload order, game counts, and spring-training context', async () => {
    const prediction = {
        id: null,
        sport: 'mlb',
        game_id: 201,
        game: 'AWY @ HOME',
        game_time: '2026-11-07T20:00:00Z',
        home_team: 'HOME',
        away_team: 'AWY',
        win_probability: 0.65,
        predicted_spread: -3.5,
        predicted_total: 45.5,
        season_type: 1,
    };
    const html = await render(Sections, {
        sports: [
            {
                name: 'MLB',
                fullName: 'Major League Baseball',
                color: 'green',
                predictions: [prediction],
            },
            {
                name: 'NFL',
                fullName: 'National Football League',
                color: 'blue',
                predictions: [
                    {
                        ...prediction,
                        sport: 'nfl',
                        game_id: 101,
                        season_type: 2,
                    },
                    {
                        ...prediction,
                        sport: 'nfl',
                        game_id: 102,
                        season_type: 2,
                    },
                ],
            },
        ],
    });
    assert.ok(html.indexOf('/mlb/games/201') < html.indexOf('/nfl/games/101'));
    assert.ok(html.indexOf('/nfl/games/101') < html.indexOf('/nfl/games/102'));
    assert.match(html, /1 game/);
    assert.match(html, /2 games/);
    assert.equal((html.match(/Spring Training/g) ?? []).length, 1);
    assert.equal((html.match(/Moneyline pick: HOME/g) ?? []).length, 3);
});
test('empty state retains recovery and performance navigation', async () => {
    const html = await render(Empty);
    assert.match(html, /No predictions are available yet/);
    assert.match(html, /href="\/performance"/);
    assert.match(html, />Refresh(?:<!--.*?-->)*<\/button>/);
});
