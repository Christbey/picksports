import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, nextTick } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let component;
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
    ({ default: component } = await server.ssrLoadModule(
        '/resources/js/components/game-page/TrendsComparisonCard.vue',
    ));
});
after(async () => {
    await server?.close();
});

const props = {
    title: 'Trends',
    trendsLoading: false,
    allTrendCategories: ['scoring'],
    formatCategoryName: (value) => value,
    isLockedCategory: () => false,
    formatTierName: (value) => value,
    getRequiredTier: () => 'free',
    awayLabel: 'DET',
    homeLabel: 'BUF',
    emptyText: 'No trends',
};
const record = (wins, losses) => ({
    wins,
    losses,
    ties: 0,
    games: wins + losses,
    display: `${wins}-${losses}`,
});

test('renders the actual last meeting date, season and score, without a betting edge claim', async () => {
    const html = await renderToString(
        createSSRApp(component, {
            ...props,
            matchupContext: {
                rows: [
                    {
                        key: 'head_to_head',
                        label: 'Head-to-head',
                        subtitle: 'Available prior seasons',
                        away: record(2, 3),
                        home: record(3, 2),
                        latest_meeting: {
                            game_id: 100,
                            game_date: '2024-12-15',
                            season: 2024,
                            away_team_id: 2,
                            home_team_id: 1,
                            away_abbreviation: 'BUF',
                            home_abbreviation: 'DET',
                            away_score: 48,
                            home_score: 42,
                        },
                    },
                ],
            },
            awayTrends: {
                sample_size: 1,
                trends: { scoring: ['Won the only sampled game.'] },
                scored_signals: [
                    {
                        id: '1',
                        category: 'scoring',
                        message: 'Won the only sampled game.',
                        tone: 'team',
                        direction: 'support',
                        score: 85,
                        sample_size: 1,
                    },
                ],
            },
        }),
    );
    assert.match(html, /December 15, 2024/);
    assert.match(html, /2024 season/);
    assert.match(html, /BUF 48–42 DET/);
    assert.match(html, /Pattern rank 85\/100/);
    assert.match(html, /not win probabilities/);
    assert.match(html, /DET context/);
    assert.doesNotMatch(html, /DET edge|BUF edge|1 games/);
});

test('shows both sample sizes and does not rank a missing history sample against a real one', async () => {
    const html = await renderToString(
        createSSRApp(component, {
            ...props,
            matchupContext: {
                rows: [
                    {
                        key: 'split',
                        label: 'Split',
                        away: record(0, 0),
                        home: record(1, 1),
                    },
                ],
            },
        }),
    );
    assert.match(html, /DET: 0 games · BUF: 2 games/);
    assert.match(html, /Incomplete sample/);
    assert.doesNotMatch(html, /higher historical win rate/);
});

const evidenceWindow = (label, message, sample) => ({
    sample_size: sample,
    trends: { scoring: [message] },
    scored_signals: [],
    locked_trends: {},
    evidence: {
        label,
        sample_size: sample,
        seasons: sample > 1 ? [2025, 2026] : [2026],
        from_date: '2025-12-15',
        through_date: '2026-09-13',
        latest_source_update: null,
        record: { wins: sample, losses: 0, ties: 0 },
        metrics: {
            points_for: { value: 28, sample_size: sample },
            turnovers: { value: null, sample_size: 0 },
        },
    },
});
const teamProfile = () => ({
    ...evidenceWindow('Last 5 regular-season games', 'RECENT_ONLY_PATTERN', 5),
    team_evidence: {
        windows: {
            recent_5: evidenceWindow(
                'Last 5 regular-season games',
                'RECENT_ONLY_PATTERN',
                5,
            ),
            recent_10: evidenceWindow(
                'Last 10 regular-season games',
                'TEN_GAME_PATTERN',
                10,
            ),
            season: evidenceWindow(
                '2026 regular season',
                'SEASON_ONLY_PATTERN',
                1,
            ),
            historical: evidenceWindow(
                'Previous 3 regular seasons',
                'HISTORICAL_ONLY_PATTERN',
                30,
            ),
        },
        changes: [
            {
                metric: 'turnovers',
                recent: { value: null, sample_size: 0 },
                baseline: { value: null, sample_size: 0 },
                delta: null,
            },
        ],
    },
});

test('shows transparent windows and missing metric coverage without invented efficiency', async () => {
    const html = await renderToString(
        createSSRApp(component, { ...props, homeTrends: teamProfile() }),
    );
    assert.match(html, /Previous 3 seasons/);
    assert.match(html, /Cross-season sample/);
    assert.match(html, /Unavailable/);
    assert.match(html, /Insufficient evidence/);
    assert.match(html, /0\/5/);
    assert.match(html, /RECENT_ONLY_PATTERN/);
    assert.doesNotMatch(html, /HISTORICAL_ONLY_PATTERN/);
});

test('does not mix legacy samples into an evidence-window comparison', async () => {
    const html = await renderToString(
        createSSRApp(component, {
            ...props,
            homeTrends: teamProfile(),
            awayTrends: evidenceWindow(
                'Legacy sample',
                'LEGACY_ONLY_PATTERN',
                20,
            ),
            topMatchupEdges: ['STALE_LEGACY_EDGE'],
        }),
    );
    assert.match(html, /RECENT_ONLY_PATTERN/);
    assert.match(html, /Could not load this team&#39;s evidence/);
    assert.match(html, /Comparison unavailable/);
    assert.doesNotMatch(html, /LEGACY_ONLY_PATTERN|STALE_LEGACY_EDGE/);
});

test('empty evidence shows an explicit no-history state rather than a zero record', async () => {
    const profile = teamProfile();
    profile.team_evidence.windows.recent_5 = evidenceWindow(
        'Last 5 regular-season games',
        '',
        0,
    );
    const html = await renderToString(
        createSSRApp(component, { ...props, homeTrends: profile }),
    );
    assert.match(html, /No completed regular-season games in this window/);
    assert.doesNotMatch(html, /0–0–0 W–L–T|0\/0/);
});

test('changing the window updates the reactive patterns while head-to-head stays separate', async () => {
    let bindings;
    const wrapper = {
        ...component,
        setup(props, context) {
            bindings = component.setup(props, context);
            return bindings;
        },
    };
    await renderToString(
        createSSRApp(wrapper, {
            ...props,
            homeTrends: teamProfile(),
            matchupContext: {
                rows: [
                    {
                        key: 'head_to_head',
                        label: 'Head-to-head unchanged',
                        away: record(0, 1),
                        home: record(1, 0),
                    },
                ],
            },
        }),
    );
    assert.match(
        bindings.homeView.value.trends.scoring[0],
        /RECENT_ONLY_PATTERN/,
    );
    const history = bindings.matchupRows.value;
    bindings.selectedWindow.value = 'historical';
    await nextTick();
    assert.match(
        bindings.homeView.value.trends.scoring[0],
        /HISTORICAL_ONLY_PATTERN/,
    );
    assert.equal(bindings.homeView.value.sample_size, 30);
    assert.equal(bindings.matchupRows.value, history);
    bindings.selectedWindow.value = 'season';
    await nextTick();
    assert.match(
        bindings.homeView.value.trends.scoring[0],
        /SEASON_ONLY_PATTERN/,
    );
    assert.equal(bindings.homeView.value.sample_size, 1);
    bindings.selectedWindow.value = 'unavailable_window';
    await nextTick();
    assert.equal(bindings.homeView.value, null);
    assert.deepEqual(bindings.allTrendCategories.value, []);
});
