import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
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
