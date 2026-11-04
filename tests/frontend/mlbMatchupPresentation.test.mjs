import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server;
let presentation;
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
    presentation = await server.ssrLoadModule(
        '/resources/js/lib/mlbMatchupPresentation.ts',
    );
});
after(async () => {
    await server?.close();
});
const prediction = (extra = {}) => ({
    game: {
        status: 'STATUS_SCHEDULED',
        home_team: { abbreviation: 'HOM' },
        away_team: { abbreviation: 'AWY' },
    },
    ...extra,
});
const render = async (name, props) => {
    const { default: component } = await server.ssrLoadModule(
        `/resources/js/components/mlb/${name}.vue`,
    );
    const app = createSSRApp(component, props);
    app.config.warnHandler = (message) => {
        throw new Error(message);
    };
    return renderToString(app);
};
test('matchup formatting retains zero values, missing data and final-result distinctions', () => {
    assert.equal(presentation.formatPercent(0), '0.0%');
    assert.equal(presentation.formatPercent(null), '-');
    assert.equal(presentation.formatOdds(110), '+110');
    assert.equal(presentation.formatOdds(-110), '-110');
    assert.equal(presentation.modelResultLabel(prediction()), 'Pending');
    assert.equal(
        presentation.modelResultLabel(prediction({ status: 'STATUS_FINAL' })),
        'Ungraded',
    );
    assert.equal(
        presentation.modelResultLabel(
            prediction({ status: 'STATUS_FINAL', winner_correct: false }),
        ),
        'Projection lost',
    );
    assert.equal(
        presentation.finalScoreLabel(
            prediction({
                status: 'STATUS_FINAL',
                game: {
                    away_score: 0,
                    home_score: 1,
                    away_team: { abbreviation: 'AWY' },
                    home_team: { abbreviation: 'HOM' },
                },
            }),
        ),
        'AWY 0 - HOM 1',
    );
});
test('watchouts stay separate from supporting signals and repeated reasons are deduplicated', () => {
    const candidate = {
        reason_codes: [
            'home_field_support',
            'home_field_support',
            'missing_pitcher',
        ],
        risk_flags: ['missing_pitcher'],
    };
    const drivers = presentation.signalDriverLabels(null, candidate);
    const risks = presentation.watchoutLabels(null, candidate);
    assert.equal(drivers.length, 1);
    assert.match(drivers[0], /Home-field/);
    assert.equal(risks.length, 1);
    assert.match(risks[0], /Missing Pitcher/i);
});
test('model-versus-market section retains candidate precedence and absent-value placeholders', async () => {
    const html = await render('MlbMatchupOverview', {
        prediction: prediction({ projection: { home_win_probability: 0.55 } }),
        candidate: {
            model_probability: 0.6,
            market_probability: 0.5,
            blended_probability: 0.57,
            edge: 0.07,
        },
    });
    assert.match(html, /Model vs Market/);
    assert.match(html, /60.0%/);
    assert.doesNotMatch(html, /55.0%/);
    assert.match(
        await render('MlbMatchupOverview', { prediction: prediction() }),
        />-</,
    );
});
test('period sections safely omit absent evidence and distinguish partial innings from a completed period', async () => {
    for (const name of ['MlbMatchupPeriodModels', 'MlbMatchupPeriodInsights']) {
        const html = await render(name, { prediction: prediction() });
        assert.doesNotMatch(html, /<section/);
    }
    const game = prediction({
        game: {
            away_team: { abbreviation: 'AWY' },
            home_team: { abbreviation: 'HOM' },
            away_linescores: [0, 1],
            home_linescores: [0, 0],
        },
    });
    assert.equal(presentation.periodScore(game, 3), '-');
    game.game.away_linescores.push(0);
    game.game.home_linescores.push(0);
    assert.equal(presentation.periodScore(game, 3), 'AWY 1 - HOM 0');
});

test('final results distinguish wins, losses and ungraded forecasts while retaining a zero score', async () => {
    for (const [winner_correct, label] of [
        [true, 'Projection won'],
        [false, 'Projection lost'],
        [null, 'Ungraded'],
    ]) {
        const html = await render('MlbMatchupResult', {
            prediction: prediction({
                status: 'STATUS_FINAL',
                winner_correct,
                game: {
                    away_team: { abbreviation: 'AWY' },
                    home_team: { abbreviation: 'HOM' },
                    away_score: 0,
                    home_score: 1,
                },
            }),
        });
        assert.match(html, new RegExp(label));
        assert.match(html, /AWY 0 - HOM 1/);
    }
});
