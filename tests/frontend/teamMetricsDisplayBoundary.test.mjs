import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server, configs, component;
before(async () => {
    const root = fileURLToPath(new URL('../../', import.meta.url));
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'team-metrics-read-only-boundaries',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client=()=>({metrics:{teams(){throw Error("Unexpected SSR API call")},teamAvailableSeasons(){throw Error("Unexpected SSR API call")}}});';
                    if (
                        id.endsWith('/layouts/AppLayout.vue') ||
                        id.endsWith('/components/SubscriptionBanner.vue')
                    )
                        return '<template><div><slot/></div></template>';
                },
            },
            vue(),
        ],
        resolve: {
            alias: [
                {
                    find: /^@\/actions\/App\/Http\/Controllers\/(?:CBB|MLB|NBA|NFL|WCBB|WNBA)\/TeamController$/,
                    replacement:
                        root + 'tests/frontend/fixtures/teamController.mjs',
                },
                {
                    find: '@inertiajs/vue3',
                    replacement:
                        root +
                        'tests/frontend/fixtures/frontendCleanupInertia.mjs',
                },
                { find: '@', replacement: root + 'resources/js' },
            ],
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    configs = await server.ssrLoadModule(
        '/resources/js/config/sport-team-metrics-configs.ts',
    );
    component = (
        await server.ssrLoadModule(
            '/resources/js/components/SportTeamMetrics.vue',
        )
    ).default;
});
after(async () => server?.close());
const record = {
    record_label: null,
    record: { wins: null, losses: null, games_played: 0, label: null },
    wins: null,
    losses: null,
    games_played: 0,
};
const column = (sport, label) =>
    configs[`${sport}TeamMetricsConfig`].columns.find(
        (item) => item.label === label,
    );
for (const sport of ['nba', 'wnba', 'mlb']) {
    test(`${sport}: nullable API record is unavailable rather than null-null`, () => {
        assert.equal(column(sport, 'Record').value(record), '-');
    });
}
for (const sport of ['nba', 'wnba', 'mlb', 'cbb', 'wcbb']) {
    test(`${sport}: genuine zero wins retain their known loss count`, () => {
        assert.equal(
            column(sport, 'Record').value({
                ...record,
                record: { wins: 0, losses: 2 },
                wins: 0,
                losses: 2,
            }),
            '0-2',
        );
    });
}
for (const sport of ['cbb', 'wcbb']) {
    for (const side of ['Home', 'Away']) {
        test(`${sport} ${side}: zero efficiency remains observed while missing efficiency stays unavailable`, () => {
            const prefix = side.toLowerCase();
            const metric = {
                [`${prefix}_offensive_efficiency`]: 0,
                [`${prefix}_defensive_efficiency`]: 100,
                [`${prefix}_games`]: 1,
            };
            assert.equal(column(sport, side).value(metric), '-100.0 (1)');
            assert.equal(
                column(sport, side).value({
                    ...metric,
                    [`${prefix}_offensive_efficiency`]: null,
                }),
                '- (1)',
            );
            assert.equal(
                column(sport, side).value({
                    ...metric,
                    [`${prefix}_offensive_efficiency`]: 100,
                    [`${prefix}_defensive_efficiency`]: 0,
                }),
                '100.0 (1)',
            );
            assert.equal(
                column(sport, side).value({
                    ...metric,
                    [`${prefix}_offensive_efficiency`]: '0.00',
                    [`${prefix}_defensive_efficiency`]: '100.00',
                }),
                '-100.0 (1)',
            );
            assert.equal(
                column(sport, side).value({
                    ...metric,
                    [`${prefix}_offensive_efficiency`]: '',
                    [`${prefix}_defensive_efficiency`]: 100,
                }),
                '- (1)',
            );
        });
    }
}
async function render(sport, rows) {
    const config = configs[`${sport}TeamMetricsConfig`];
    return renderToString(
        createSSRApp(
            {
                ...component,
                setup(props, context) {
                    const state = component.setup(props, context);
                    state.metrics.value = rows;
                    state.loading.value = false;
                    return state;
                },
            },
            { config },
        ),
    );
}
test('actual compiled NBA metrics table does not expose a null-null record', async () => {
    const html = await render('nba', [
        { ...record, id: 1, team: { id: 2, display_name: 'Synthetic team' } },
    ]);
    assert.match(html, /Synthetic team/);
    assert.doesNotMatch(html, /null-null/);
});
for (const sport of ['cbb', 'wcbb']) {
    test(`actual compiled ${sport} metrics table displays known zero-based split net`, async () => {
        const html = await render(sport, [
            {
                id: 1,
                team: { id: 2, display_name: 'Synthetic team' },
                record_label: '0-2',
                home_offensive_efficiency: 0,
                home_defensive_efficiency: 100,
                home_games: 1,
            },
        ]);
        assert.match(html, /Synthetic team/);
        assert.match(html, /-100\.0 \(1\)/);
        assert.match(html, /0-2/);
    });
}
