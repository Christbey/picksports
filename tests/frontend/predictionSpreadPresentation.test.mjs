import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { predictionHomeMargin } from '../../resources/js/lib/predictionSpread.ts';

let server, Row, mapPrediction;
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
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    Row = (
        await server.ssrLoadModule(
            '/resources/js/components/predictions/PredictionTableRow.vue',
        )
    ).default;
    ({ mapV2Prediction: mapPrediction } = await server.ssrLoadModule(
        '/resources/js/lib/predictionBoardPresentation.ts',
    ));
});
after(async () => server?.close());

const cases = [
    ['UNM', 'WYO', 11.85, 10.5, 'UNM', 'UNM -10.5 to cover'],
    ['ORE', 'MSU', 25.25, 18.5, 'ORE', 'ORE -18.5 to cover'],
    ['BGSU', 'TOL', -17, -17, 'TOL', 'Model projects a push'],
    ['CLEM', 'DUKE', -8.31, -11.5, 'DUKE', 'CLEM +11.5 to cover'],
    ['ISU', 'UCF', -1, 1.5, 'UCF', 'UCF +1.5 to cover'],
    ['UTEP', 'AFA', -26.7, -22, 'AFA', 'AFA -22 to cover'],
];
const render = (prediction, dashboard = false) =>
    renderToString(
        createSSRApp({
            render: () =>
                h(Row, {
                    prediction,
                    dashboard,
                    sport: 'cfb',
                    href: '/cfb/games/820',
                }),
        }),
    );

test('board and dashboard render the six screenshot matchups using the same home-handicap convention', async () => {
    for (const [away, home, modelLine, marketLine, winner, cover] of cases) {
        const context = {
            model_home_spread: modelLine,
            home_spread: marketLine,
        };
        const probability = modelLine > 0 ? 0.2 : 0.8;
        // The actual compact API shape has no phase or revision. Its adapter
        // also drops those fields from full canonical responses.
        const api = {
            id: null,
            sport: 'cfb',
            game_id: 820,
            projection: {
                predicted_spread: modelLine,
                predicted_total: 46.05,
                home_win_probability: probability,
            },
            model_bet_context: context,
            game: {
                id: 820,
                status: 'STATUS_IN_PROGRESS',
                home_score: 0,
                away_score: 0,
                home_team: { abbreviation: home },
                away_team: { abbreviation: away },
            },
        };
        const mapped = mapPrediction(api);
        assert.equal(predictionHomeMargin(mapped), -modelLine);
        const board = await render(mapped);
        assert.match(board, new RegExp(`${winner} −${Math.abs(modelLine)}`));
        assert.match(board, new RegExp(`Bet ${winner} to win`));
        const dashboard = await render(
            {
                id: null,
                game_id: 820,
                game: `${away} @ ${home}`,
                home_team: home,
                away_team: away,
                predicted_spread: -modelLine,
                predicted_total: 46.05,
                win_probability: probability,
                model_bet_context: context,
                is_live: true,
                home_score: 0,
                away_score: 0,
            },
            true,
        );
        assert.ok(dashboard.includes(cover), `${away} at ${home}: ${cover}`);
    }
});

test('explicit home handicap survives missing metadata and does not turn null or blank values into zero', () => {
    for (const line of [11.85, '11.85', -17, '-17', 0, '0']) {
        assert.equal(
            predictionHomeMargin({
                model_bet_context: { model_home_spread: line },
            }),
            Number(line) === 0 ? 0 : -Number(line),
        );
    }
    for (const line of [null, undefined, '', ' ', 'invalid']) {
        assert.equal(
            predictionHomeMargin({
                predicted_spread: 3,
                model_bet_context: { model_home_spread: line },
            }),
            3,
        );
    }
});
