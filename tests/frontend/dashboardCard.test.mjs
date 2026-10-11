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
    assert.doesNotMatch(
        html,
        /Oct 11|Pregame:|Live read|Home spread|bg-red-500 motion-safe:animate-pulse/,
    );
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
    assert.match(html, /Q2 · 8:57/);
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

test('football cards and game center show quarters and halftime', async () => {
    for (const sport of ['nfl', 'cfb']) {
        for (const game_clock of ['0:00', '00:00', '000', '0.0']) {
            const game = { ...prediction, sport, is_live: true, game_clock };
            const html = await render(Card, {
                prediction: game,
                sport,
                href: '/games/1',
            });
            assert.match(html, /Halftime/);
            assert.doesNotMatch(html, /Period 2|Q2/);
            const strip = await render(Strip, {
                sports: [{ name: sport, predictions: [game] }],
            });
            assert.match(strip, /Halftime/);
        }
    }
    const html = await render(Card, {
        prediction: { ...prediction, is_live: true },
        sport: 'nfl',
        href: '/games/1',
    });
    assert.match(html, /Q2 · 8:57/);
});

test('live indicators show the leader probability and total direction', async () => {
    for (const [total, direction] of [
        [37.2, 'Down'],
        [44.1, 'Up'],
        [43.2, 'Steady'],
    ]) {
        const html = await render(Card, {
            prediction: {
                ...prediction,
                is_live: true,
                live_predicted_total: total,
            },
            href: '/games/1',
        });
        assert.match(html, /aria-valuenow="71.3"/);
        assert.match(html, /width:71.3%/);
        assert.match(html, new RegExp(` ${direction}</span>`));
        if (direction === 'Up') assert.match(html, /text-emerald-700/);
        if (direction === 'Down') assert.match(html, /text-red-700/);
    }
    const html = await render(Card, {
        prediction: {
            ...prediction,
            is_live: true,
            live_win_probability: undefined,
            live_predicted_total: undefined,
        },
        href: '/games/1',
    });
    assert.doesNotMatch(html, /win-outlook-meter|total-movement/);
});

test('period formatting preserves other sports and handles football overtime', async () => {
    for (const [sport, period, status, clock, expected] of [
        ['nfl', 1, null, '12:00', 'Q1 · 12:00'],
        ['cfb', 4, null, '0:00', 'Q4 · 0:00'],
        ['nfl', 5, null, '5:00', 'OT · 5:00'],
        ['cfb', 6, null, null, 'OT2'],
        ['nfl', null, 'STATUS_HALFTIME', null, 'Halftime'],
        ['nhl', 2, null, '0:00', 'Period 2 · 0:00'],
    ]) {
        const html = await render(Card, {
            prediction: {
                ...prediction,
                is_live: true,
                period,
                status,
                game_clock: clock,
            },
            sport,
            href: '/games/1',
        });
        assert.ok(html.includes(expected));
    }
});
