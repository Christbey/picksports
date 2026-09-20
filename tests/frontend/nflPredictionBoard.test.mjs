import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import {
    kickoffLabel,
    nflBoardPresentation,
} from '../../resources/js/lib/nflBoardPresentation.ts';

const prediction = (p, margin, homeLine, extra = {}) => ({
    id: 1,
    sport: 'nfl',
    game_id: 1,
    win_probability: p,
    predicted_spread: margin,
    game: {
        home_team: { abbreviation: 'HOME' },
        away_team: { abbreviation: 'AWAY' },
        kickoff_at: '2026-09-20T17:00:00Z',
    },
    pro_signal_layer: { market_context: { pick_side: 'away' } },
    nfl_board: {
        market: { home_spread: homeLine },
        research: { status: 'reviewed', decision: 'pass' },
    },
    ...extra,
});

test('winner uses home probability, never the value pick side', () => {
    const v = nflBoardPresentation(prediction(0.72, 8.8, -13.5));
    assert.equal(v.winner, 'HOME');
    assert.equal(v.winnerProbability, 0.72);
    assert.equal(v.spreadLean, 'AWAY +13.5');
    assert.equal(v.researchLabel, 'Research checked');
});
test('away favorites display their own complementary win probability', () => {
    const v = nflBoardPresentation(prediction(0.27, -9.9, 7));
    assert.equal(v.winner, 'AWAY');
    assert.equal(v.winnerProbability, 0.73);
    assert.equal(v.spreadLean, 'AWAY -7');
});
test('home underdog, home favorite, push, missing line and missing probabilities', () => {
    assert.equal(
        nflBoardPresentation(prediction(0.505, 0.3, 2.5)).spreadLean,
        'HOME +2.5',
    );
    assert.equal(
        nflBoardPresentation(prediction(0.74, 10.8, -8.5)).spreadLean,
        'HOME -8.5',
    );
    assert.equal(
        nflBoardPresentation(prediction(0.58, 3, -3)).spreadLean,
        'No edge',
    );
    assert.equal(
        nflBoardPresentation(prediction(0.58, 3, null)).spreadLean,
        'Line unavailable',
    );
    for (const p of [null, '', -1, 1.1, 'bad'])
        assert.equal(
            nflBoardPresentation(prediction(p, 0, null)).winner,
            'Unavailable',
        );
    assert.equal(
        nflBoardPresentation(prediction(0.5, 0, null)).winner,
        'Even matchup',
    );
});
test('explicit research forecast wins over older stored projection without clearing holds', () => {
    const v = nflBoardPresentation(
        prediction(0.72, 8.8, -13.5, {
            nfl_board: {
                forecast: { win_probability: 0.4, predicted_spread: -2.2 },
                research: { status: 'hold' },
                market: { home_spread: 3.5 },
            },
        }),
    );
    assert.equal(v.winner, 'AWAY');
    assert.equal(v.winnerProbability, 0.6);
    assert.equal(v.researchLabel, 'Research hold');
    assert.equal(v.spreadLean, 'HOME +3.5');
});
test('kickoff uses an absolute instant with explicit timezone, missing time is not invented', () => {
    assert.match(kickoffLabel(prediction(0.5, 0, 0)), /(?:AM|PM).*\S+/);
    assert.equal(kickoffLabel({ game: {} }), 'Time pending');
    assert.equal(
        kickoffLabel({ game: { kickoff_at: 'invalid' } }),
        'Time pending',
    );
});

let server;
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
});
after(async () => {
    await server?.close();
});

test('compact card renders winner and spread as separate concepts without diagnostic clutter', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/nfl/NflMatchupCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, { prediction: prediction(0.72, 8.8, -13.5) }),
    );
    assert.match(html, /Model winner/);
    assert.match(html, /HOME.*72\.0%/s);
    assert.match(html, /AWAY \+13\.5/);
    assert.match(html, /Research checked/);
    assert.doesNotMatch(html, /Trust|Moneyline:|Week 2|Spread Pass|Total Pass/);
});
test('final cards preserve grading and never label predictions as live', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/nfl/NflMatchupCard.vue',
    );
    const html = await renderToString(
        createSSRApp(component, {
            prediction: prediction(0.72, 8.8, -13.5, {
                status: 'STATUS_FINAL',
                winner_correct: false,
            }),
        }),
    );
    assert.match(html, /Projection lost/);
    assert.match(html, /Pregame winner/);
});

test('board leads with games, collapses season insights, and labels filter controls', async () => {
    const { default: component } = await server.ssrLoadModule(
        '/resources/js/components/nfl/NflMatchupBoard.vue',
    );
    const wrapper = {
        ...component,
        setup(props, context) {
            const bindings = component.setup(props, context);
            bindings.loading.value = false;
            bindings.predictions.value = [prediction(0.72, 8.8, -13.5)];
            bindings.selectedDate.value = '2026-09-20';
            return bindings;
        },
    };
    const html = await renderToString(createSSRApp(wrapper));
    assert.match(html, /NFL predictions/);
    assert.match(html, /aria-label="Game date"/);
    assert.match(html, /aria-label="Season"/);
    assert.match(html, /aria-expanded="false"/);
    assert.match(html, /More insights/);
    assert.doesNotMatch(html, /Super Bowl|One card per game|Visible 1/);
    assert.ok(html.indexOf('Model winner') < html.indexOf('More insights'));
});
