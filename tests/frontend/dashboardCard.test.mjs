import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let Card;
let Strip;
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
        server: { middlewareMode: true, hmr: false },
        appType: 'custom',
    });
    Card = (
        await server.ssrLoadModule(
            '/resources/js/components/predictions/UnifiedPredictionCard.vue',
        )
    ).default;
    Strip = (
        await server.ssrLoadModule(
            '/resources/js/components/dashboard/DashboardLiveStrip.vue',
        )
    ).default;
});
after(async () => {
    await server?.close();
});

const prediction = {
    id: null,
    sport: 'nfl',
    game_id: 1,
    game: 'NYG @ WSH',
    game_time: '2026-10-11T17:00:00Z',
    home_team: 'WSH',
    away_team: 'NYG',
    home_logo: '',
    away_logo: '',
    win_probability: 0.61,
    predicted_spread: 2.5,
    predicted_total: 43,
    home_score: 9,
    away_score: 3,
    period: 2,
    game_clock: '8:57',
    live_win_probability: 0.713,
    live_predicted_total: 37.2,
};
const render = (component, props) =>
    renderToString(createSSRApp({ render: () => h(component, props) }));

test('live cards show scores and outlook without kickoff or duplicate pregame content', async () => {
    const html = await render(Card, {
        prediction: { ...prediction, is_live: true },
        href: '/nfl/games/1',
    });
    assert.match(html, /Live game insights/);
    assert.match(html, /8:57/);
    assert.match(html, /71.3%/);
    assert.match(html, /37.2/);
    assert.match(html, /bg-sky-500 motion-safe:animate-pulse/);
    assert.doesNotMatch(html, /Oct 11|Pregame:|Live read|Home spread|red-/);
});

test('pregame cards retain kickoff and selection information', async () => {
    const html = await render(Card, { prediction, href: '/nfl/games/1' });
    assert.match(html, /Oct 11/);
    assert.match(html, /Moneyline pick: WSH/);
    assert.match(html, /View game/);
    assert.doesNotMatch(html, /Home spread|raw edge|61.0%/);
    assert.doesNotMatch(html, /Live game insights/);
});

test('game center uses blue for live status', async () => {
    const html = await render(Strip, {
        sports: [
            { name: 'NFL', predictions: [{ ...prediction, is_live: true }] },
        ],
    });
    assert.match(html, /text-sky-/);
    assert.match(html, /P2 8:57/);
    assert.doesNotMatch(html, /red-/);
});

test('dashboard hides internal holds, trust scores, and diagnostic footer content', async () => {
    const html = await render(Card, {
        prediction: {
            ...prediction,
            home_team: 'NYJ',
            win_probability: 0.53,
            betting_value_debug: 'Research hold',
            prediction_analysis: {
                trust_score: 81,
                bet_classification: 'research_hold',
            },
        },
        href: '/nfl/games/1',
    });
    assert.match(html, /Moneyline pick: NYJ/);
    assert.doesNotMatch(html, /No pick|No Bet|No Play|No signal/);
    assert.match(html, /View game/);
    assert.doesNotMatch(
        html,
        /Research hold|research hold|81 Trust|53.0%|Home spread|Below signal threshold/,
    );
});

test('moneyline selection remains visible when stored recommendation is no play', async () => {
    for (const is_live of [false, true]) {
        const html = await render(Card, {
            prediction: {
                ...prediction,
                is_live,
                recommendation: { recommendation_type: 'no_play' },
            },
            href: '/nfl/games/1',
        });
        assert.match(html, /Moneyline pick: WSH/);
        assert.doesNotMatch(html, /No pick|No Play|Projected winner/);
    }
});
