import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { compareMetricValues } from '../../resources/js/components/sport-team-metrics-helpers.ts';

let server, component, config;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [vue()],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: [
                {
                    find: /^@\/actions\/App\/Http\/Controllers\/(?:CBB|MLB|NBA|NFL|WCBB|WNBA)\/TeamController$/,
                    replacement: fileURLToPath(
                        new URL(
                            './fixtures/teamController.mjs',
                            import.meta.url,
                        ),
                    ),
                },
                {
                    find: '@',
                    replacement: fileURLToPath(
                        new URL('../../resources/js', import.meta.url),
                    ),
                },
            ],
        },
    });
    component = (
        await server.ssrLoadModule(
            '/resources/js/components/NflTeamMetricsBoard.vue',
        )
    ).default;
    config = (
        await server.ssrLoadModule(
            '/resources/js/config/sport-team-metrics-configs.ts',
        )
    ).nflTeamMetricsConfig;
});
after(async () => {
    await server?.close();
});
const fixture = {
    id: 1,
    display_rank: 8,
    team: { id: 2, display_name: 'Test Team' },
    record_label: '1-0-1',
    games_played: 2,
    points_per_game: 24,
    points_allowed_per_game: 20,
    net_true_epa_per_play: 0.125,
    sample_sizes: { games: 2, home: 2, away: 0, yards_per_game: 1 },
    updated_at: '2026-09-20T12:00:00Z',
};
const render = (rows, expanded = []) =>
    renderToString(
        createSSRApp(
            {
                ...component,
                setup(props, context) {
                    const bindings = component.setup(props, context);
                    bindings.expandedTeams.value = expanded;
                    return bindings;
                },
            },
            {
                metrics: rows,
                columns: config.columns,
                teamLink: (id) => `/nfl/teams/${id}`,
                sortLabel: 'Net EPA/Play',
            },
        ),
    );

test('defensive metrics start best-first and nulls sort last in both directions', () => {
    assert.deepEqual(
        [0.3, null, -0.2, 0.1].sort((a, b) => compareMetricValues(a, b, true)),
        [-0.2, 0.1, 0.3, null],
    );
    assert.deepEqual(
        [0.3, null, -0.2, 0.1].sort((a, b) =>
            compareMetricValues(a, b, true, false),
        ),
        [0.3, 0.1, -0.2, null],
    );
    assert.equal(compareMetricValues(null, undefined), 0);
    assert.equal(compareMetricValues('bad', 0), 1);
});
test('NFL starts with regular season and preserves fractional turnover rates', () => {
    assert.equal(config.defaultSeasonType, '2');
    assert.equal(
        config.columns
            .find((c) => c.label === 'TO+/-')
            .value({ turnover_differential: 0.5 }),
        '0.5',
    );
});
test('core stats remain visible while advanced metrics are collapsed with samples', async () => {
    const html = await render([fixture]);
    const primary = html;
    assert.match(primary, /1-0-1/);
    assert.match(primary, /#8/);
    assert.match(primary, /0\.125/);
    assert.match(primary, /Small sample: 2/);
    assert.match(primary, /Calculated/);
    assert.doesNotMatch(html, /<details[^>]*\bopen\b/);
    assert.match(html, /aria-expanded="false"/);
    assert.match(html, /<section[^>]*aria-label="NFL team rankings"/);
    assert.doesNotMatch(html, /<form/);
    assert.doesNotMatch(html, /Heuristic rating|Offense &amp; defense/);
    const expanded = await render([fixture], [fixture.id]);
    assert.match(expanded, /aria-expanded="true"/);
    assert.match(expanded, /id="[^"]*-nfl-team-metrics-1"/);
    assert.match(expanded, /1 game/);
    assert.match(expanded, /Heuristic rating/);
});
test('legacy samples remain unknown and empty results are explained', async () => {
    const html = await render([
        {
            ...fixture,
            record_label: null,
            sample_sizes: null,
            games_played: null,
            display_rank: null,
        },
    ]);
    assert.match(html, /Recalculation required/);
    assert.doesNotMatch(html, /0\.125/);
    assert.match(await render([]), /No teams match/);
});

test('each disclosure has a team-specific name and a stable labelled panel even while collapsed', async () => {
    const rows = [
        fixture,
        { ...fixture, id: 2, team: { id: 3, display_name: 'Other Team' } },
    ];
    const html = await render(rows);
    assert.match(html, /for Test Team/);
    assert.match(html, /for Other Team/);
    const controls = [...html.matchAll(/<button\b[^>]*>/g)].map(([tag]) => ({
        id: tag.match(/\bid="([^"]+)"/)?.[1],
        panel: tag.match(/aria-controls="([^"]+)"/)?.[1],
        type: tag.match(/type="([^"]+)"/)?.[1],
    }));
    assert.equal(controls.length, 2);
    for (const control of controls) {
        assert.equal(control.type, 'button');
        assert.ok(control.id);
        assert.ok(control.panel);
        assert.match(
            html,
            new RegExp(
                `id="${control.panel}"[^>]*hidden[^>]*role="region"[^>]*aria-labelledby="${control.id}"`,
            ),
        );
    }
    assert.equal(new Set(controls.map((control) => control.panel)).size, 2);
    assert.doesNotMatch(html, /Heuristic rating|Offense &amp; defense/);
    const opened = await render(rows, [fixture.id]);
    assert.equal((opened.match(/aria-expanded="true"/g) ?? []).length, 1);
    assert.equal((opened.match(/aria-expanded="false"/g) ?? []).length, 1);
});

test('two board instances never reuse disclosure or panel identifiers', async () => {
    const props = {
        metrics: [fixture],
        columns: config.columns,
        teamLink: (id) => `/nfl/teams/${id}`,
        sortLabel: 'Net EPA/Play',
    };
    const html = await renderToString(
        createSSRApp({
            render: () => h('main', [h(component, props), h(component, props)]),
        }),
    );
    const ids = [...html.matchAll(/\bid="([^"]*nfl-team-metrics[^\"]*)"/g)].map(
        ([, id]) => id,
    );
    assert.equal(ids.length, 4);
    assert.equal(new Set(ids).size, 4);
});
