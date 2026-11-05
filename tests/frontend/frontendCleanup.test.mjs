import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { parse } from '@vue/compiler-dom';
import { createSSRApp, reactive, effectScope } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { cleanupProps } from './fixtures/frontendCleanup.mjs';
const root = fileURLToPath(new URL('../../', import.meta.url));
const paths = {
    health: 'pages/Admin/Healthchecks.vue',
    monitor: 'pages/Admin/MlbModelMonitoring.vue',
    public: 'pages/PublicSport.vue',
    trends: 'components/game-page/TrendsComparisonCard.vue',
    box: 'components/game-page/BasketballBoxScoreCard.vue',
};
const baseline = process.env.FRONTEND_CLEANUP_BASELINE_REF;
let server;
before(async () => {
    server = await createServer({
        root,
        configFile: false,
        plugins: [
            {
                name: 'cleanup-fixtures',
                resolveId(id) {
                    if (id.includes('__CleanupBefore'))
                        return root + 'resources/js/' + id.split('/').at(-1);
                    if (id.endsWith('/routes')) return 'virtual:cleanup-routes';
                },
                load(id) {
                    const kind = id.match(/__CleanupBefore(\w+)\.vue$/)?.[1];
                    if (kind)
                        return execFileSync(
                            'git',
                            ['show', `${baseline}:resources/js/${paths[kind]}`],
                            { cwd: root, encoding: 'utf8' },
                        );
                    if (id === 'virtual:cleanup-routes')
                        return `export const home=()=>({url:'/'});export const dashboard=()=>({url:'/dashboard'});export const login=()=>({url:'/login'});export const register=()=>({url:'/register'});`;
                },
            },
            vue(),
        ],
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        resolve: {
            alias: [
                {
                    find: '@inertiajs/vue3',
                    replacement:
                        root +
                        'tests/frontend/fixtures/frontendCleanupInertia.mjs',
                },
                {
                    find: '@/layouts/AppLayout.vue',
                    replacement:
                        root + 'tests/frontend/fixtures/adminLayout.mjs',
                },
                {
                    find: '@/layouts/settings/Layout.vue',
                    replacement:
                        root + 'tests/frontend/fixtures/adminLayout.mjs',
                },
                { find: '@', replacement: root + 'resources/js' },
            ],
        },
    });
});
after(async () => server?.close());
// Ignore Vue's component/loop anchors and generated accessibility IDs, retaining text, classes, links, and table structure.
const emptyPublicLabel = (scenario) =>
    scenario === 'empty' ? 'NBA' : 'Prop Player';
const normalize = (html) => {
    function clean(node) {
        if (node.type === 3) return null;
        if (node.type === 2)
            return node.content.replace(/\s+/g, ' ').trim() || null;
        if (node.type === 1)
            return [
                node.tag,
                node.props
                    .filter(
                        (p) =>
                            ![
                                'id',
                                'aria-controls',
                                'aria-labelledby',
                                'selected',
                            ].includes(p.name) &&
                            !(p.name === 'class' && !p.value?.content),
                    )
                    .map((p) => [
                        p.name,
                        p.name === 'class'
                            ? (p.value?.content ?? '')
                                  .split(/\s+/)
                                  .sort()
                                  .join(' ')
                            : (p.value?.content ?? ''),
                    ])
                    .sort((a, b) => a[0].localeCompare(b[0])),
                node.children.map(clean).filter((x) => x !== null),
            ];
        return node.children.map(clean).filter((x) => x !== null);
    }
    return JSON.stringify(clean(parse(html.replace(/<!--[\s\S]*?-->/g, ''))));
};
for (const [kind, scenarios] of Object.entries({
    health: ['normal', 'validation', 'empty'],
    monitor: ['normal', 'empty'],
    public: ['normal', 'empty'],
    trends: ['normal', 'empty'],
    box: ['grid', 'table', 'gridzero', 'tablezero'],
})) {
    for (const scenario of scenarios)
        test(`${kind} ${scenario}: released text, links, classes, and structure are preserved`, async () => {
            const props = cleanupProps(kind, scenario);
            let html = [];
            for (const before of baseline ? [true, false] : [false]) {
                const { default: Page } = await server.ssrLoadModule(
                    before
                        ? `/resources/js/__CleanupBefore${kind}.vue`
                        : '/resources/js/' + paths[kind],
                );
                const app = createSSRApp(Page, props);
                app.config.globalProperties.$page = {
                    props: { auth: { user: null } },
                };
                html.push(normalize(await renderToString(app)));
            }
            if (baseline) {
                const a = html[1],
                    b = html[0];
                let i = 0;
                while (i < a.length && a[i] === b[i]) i++;
                assert.ok(
                    a === b,
                    `First rendered difference at ${i}: actual ${a.slice(i - 70, i + 200)}; expected ${b.slice(i - 70, i + 200)}`,
                );
            } else {
                const expected = {
                    health: 'Health Checks',
                    monitor: 'MLB Model Monitor',
                    public: emptyPublicLabel(scenario),
                    trends:
                        scenario === 'empty'
                            ? 'No stored trends'
                            : 'Trends & Matchup History',
                    box: 'Box Score',
                };
                assert.ok(
                    html[0].includes(expected[kind]),
                    `${kind} retained heading or empty state`,
                );
            }
        });
}
test('healthcheck controls preserve filter, selection, run, and sync request semantics', async () => {
    const { useHealthchecks } = await server.ssrLoadModule(
        '/resources/js/composables/useHealthchecks.ts',
    );
    globalThis.__cleanupCalls = [];
    const scope = effectScope();
    const state = scope.run(() =>
        useHealthchecks(reactive(cleanupProps('health'))),
    );
    try {
        state.selectedSport.value = 'nfl';
        state.selectedView.value = 'validation';
        state.filterBySport();
        assert.deepEqual(__cleanupCalls.at(-1).args, [
            '/admin/healthchecks',
            { sport: 'nfl', view: 'validation', validation_run: null },
            { preserveState: true, replace: true },
        ]);
        state.selectValidationRun(7);
        assert.deepEqual(__cleanupCalls.at(-1).args[1], {
            sport: 'nfl',
            view: 'validation',
            validation_run: 7,
        });
        assert.equal(__cleanupCalls.at(-1).args[2].preserveScroll, true);
        state.runHealthChecks();
        state.runHealthChecks();
        assert.equal(
            __cleanupCalls.filter((x) => x.url === '/admin/healthchecks/run')
                .length,
            1,
        );
        assert.deepEqual(__cleanupCalls.at(-1).data, {
            sport: 'nfl',
            mode: 'validation',
        });
        __cleanupFinish();
        assert.equal(state.isRunning.value, false);
        state.syncData('nfl', 'heartbeat_sync');
        state.syncData('nfl', 'heartbeat_sync');
        assert.equal(
            __cleanupCalls.filter((x) => x.url === '/admin/healthchecks/sync')
                .length,
            1,
        );
        __cleanupFinish();
        assert.equal(state.syncingCheck.value, null);
    } finally {
        scope.stop();
    }
});
test('MLB artifact selection retains empty/default query and navigation options', async () => {
    const { useMlbMonitoring } = await server.ssrLoadModule(
        '/resources/js/composables/useMlbMonitoring.ts',
    );
    globalThis.__cleanupCalls = [];
    const scope = effectScope();
    const state = scope.run(() =>
        useMlbMonitoring(reactive(cleanupProps('monitor'))),
    );
    try {
        state.selectArtifact({ target: { value: 'fixture' } });
        state.selectArtifact({ target: { value: '' } });
        assert.deepEqual(
            __cleanupCalls.map((x) => x.args),
            [
                [
                    '/admin/mlb-model-monitoring',
                    { artifact: 'fixture' },
                    { preserveScroll: true, preserveState: true },
                ],
                [
                    '/admin/mlb-model-monitoring',
                    {},
                    { preserveScroll: true, preserveState: true },
                ],
            ],
        );
    } finally {
        scope.stop();
    }
});

test('box-score rows preserve zero, missing, shooting, and lower-is-better rules', async () => {
    const { basketballStatRows, basketballStatValue, shootingPercentage } =
        await server.ssrLoadModule('/resources/js/lib/basketballBoxScore.ts');
    assert.equal(shootingPercentage(0, 0), '0.0');
    assert.equal(shootingPercentage(1, 3), '33.3');
    const points = basketballStatRows.find((r) => r.key === 'points_in_paint');
    assert.equal(
        basketballStatValue({ points_in_paint: 0 }, points, true),
        '-',
    );
    assert.equal(basketballStatValue({ points_in_paint: 0 }, points), 0);
    assert.equal(
        basketballStatRows
            .filter((r) => r.lowerIsBetter)
            .map((r) => r.key)
            .join(','),
        'turnovers',
    );
});
test('healthcheck metadata retains zero/false facts, safe empty values, headline trimming, and outlier groups', async () => {
    const p = await server.ssrLoadModule(
        '/resources/js/lib/healthcheckPresentation.ts',
    );
    const entries = p.metadataEntries({
        metadata: {
            age_minutes: 0,
            in_season: false,
            command_patterns: ['nfl:sync%%'],
            last_failure_error: null,
        },
    });
    assert.equal(entries.find((r) => r.key === 'age_minutes').value, '0 min');
    assert.equal(entries.find((r) => r.key === 'in_season').value, 'No');
    assert.equal(
        entries.find((r) => r.key === 'command_patterns').value,
        'nfl:sync',
    );
    assert.equal(p.displayMessage('Headline\nprivate diagnostic'), 'Headline');
    assert.deepEqual(p.getTeamScheduleOutliers(null), []);
    assert.equal(
        p.getNoGameOutliers({
            outliers: [{ type: 'no_games' }, { type: 'high' }],
        }).length,
        1,
    );
    assert.equal(
        p.getScheduleOutliers({
            outliers: [{ type: 'no_games' }, { type: 'high' }],
        }).length,
        1,
    );
    assert.equal(p.canSync('nfl', 'heartbeat_sync'), true);
    assert.equal(p.canSync('nfl', 'validation_game_coverage'), false);
});
test('public display retains spread sign, pick-em, zero metrics, and season coverage precedence', async () => {
    const p = await server.ssrLoadModule(
        '/resources/js/lib/publicSportPresentation.ts',
    );
    assert.equal(p.formatMetric(0), '0.00');
    assert.equal(p.formatMetric(null), 'N/A');
    assert.equal(p.formatHomeSpreadLine(0), 'PK');
    assert.equal(p.formatHomeSpreadLine(3), '-3.0');
    assert.equal(p.formatHomeSpreadLine(-3), '+3.0');
    const prop = {
        stats: {
            times_covered_season: { hits: 8, games: 10 },
            times_covered_last5: { hits: 1, games: 5 },
        },
    };
    assert.equal(p.coverageBreakdown(prop).label, 'Season cover rate');
    assert.equal(p.coverageBreakdown(prop).hitPct, 80);
    prop.stats.times_covered_season.games = 0;
    assert.equal(p.coverageBreakdown(prop).label, 'Last 5 cover rate');
});
test('bracket layout retains round tracks, safe fallback rows, participant identity, and play-in labeling', async () => {
    const p = await server.ssrLoadModule(
        '/resources/js/lib/bracketPresentation.ts',
    );
    assert.deepEqual(p.roundTrackStyle('round_of_64', 0), {
        gridRow: '1 / span 6',
    });
    assert.deepEqual(p.roundTrackStyle('round_of_32', 1), {
        gridRow: '21 / span 6',
    });
    assert.deepEqual(p.roundTrackStyle('unknown', 0), {
        gridRow: '1 / span 3',
    });
    assert.equal(p.roundColumnMinHeightClass('unknown'), 'min-h-[22rem]');
    assert.equal(
        p.teamToParticipant({ id: null, name: 'TBD', abbreviation: 'TBD' }).id,
        'name:TBD',
    );
    assert.equal(
        p.isRoundOf64PlayInCard({
            roundKey: 'round_of_64',
            participants: [{ sourceMatchupId: 'first-four' }],
        }),
        true,
    );
});
