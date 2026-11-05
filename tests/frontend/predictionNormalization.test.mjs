import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
let server, helpers, basketball, mlb, nfl;
before(async () => {
    server = await createServer({
        configFile: false,
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
    helpers = await server.ssrLoadModule(
        '/resources/js/lib/predictionNormalization.ts',
    );
    basketball = (
        await server.ssrLoadModule(
            '/resources/js/composables/useBasketballGamePage.ts',
        )
    ).normalizePrediction;
    mlb = (
        await server.ssrLoadModule(
            '/resources/js/composables/useMlbGamePage.ts',
        )
    ).normalizeMlbPrediction;
    nfl = (
        await server.ssrLoadModule(
            '/resources/js/composables/useNflGamePage.ts',
        )
    ).normalizePrediction;
});
after(async () => {
    await server?.close();
});
const fixture = (extra = {}) => ({
    home_win_probability: 0.6,
    away_win_probability: 0.4,
    predicted_spread: 3,
    confidence_score: 75,
    ...extra,
});
test('prediction numeric coercions retain zero and legacy inputs without changing NFL optional values', () => {
    for (const [input, expected] of [
        [0, 0],
        ['0', 0],
        ['2.5', 2.5],
        [true, 1],
        [' ', 0],
        [null, null],
        [undefined, null],
        ['', null],
        ['bad', null],
        [Infinity, null],
    ])
        assert.equal(helpers.optionalPredictionNumber(input), expected);
    assert.equal(helpers.predictionNumber(undefined), 0);
    assert.equal(
        basketball(fixture({ confidence_score: true })).confidence_score,
        1,
    );
    assert.equal(nfl(fixture({ home_elo: true })).home_elo, '');
    assert.equal(nfl(fixture({ home_elo: ' ' })).home_elo, '');
    assert.equal(nfl(fixture({ home_elo: 0 })).home_elo, 0);
});
test('confidence levels retain explicit labels, thresholds, legacy model levels and mixed reason codes', () => {
    for (const [score, level] of [
        [null, 'unavailable'],
        [0, 'low'],
        [59, 'low'],
        [60, 'medium'],
        [74, 'medium'],
        [75, 'high'],
    ]) {
        for (const normalize of [basketball, mlb])
            assert.equal(
                normalize(fixture({ confidence_score: score }))
                    .confidence_level,
                level,
            );
    }
    const result = helpers.normalizePredictionConfidence({
        confidence_level: '',
        confidence_context: {
            model_level: 10,
            raw_level: 'legacy',
            reason_codes: ['', 0, false, null],
            sample_games: '0',
        },
    });
    assert.equal(result.confidenceLevel, '');
    assert.deepEqual(result.confidenceContext, {
        label: null,
        tier: null,
        model_level: 'legacy',
        reason_codes: ['0', 'false', 'null'],
        sample_games: 0,
    });
});
test('modern betting plans keep all evidence lists and missing narrative content stays explicit', () => {
    const plan = {
        bet_pick: 'Home',
        reasoning: 'Stored reason',
        classification: 'lean',
        for_bet: ['', 0, 'Support'],
        against_bet: ['Risk'],
        pass_reasons: null,
        reason_codes: ['code'],
    };
    for (const normalize of [basketball, mlb]) {
        const output = normalize(
            fixture({
                narrative: {
                    summary: 'Summary',
                    key_points: ['', 0, false],
                    betting_plan: plan,
                },
            }),
        );
        assert.deepEqual(output.narrative.key_points, ['0', 'false']);
        assert.deepEqual(output.narrative.betting_plan, {
            bet_pick: 'Home',
            reasoning: 'Stored reason',
            classification: 'lean',
            for_bet: ['0', 'Support'],
            against_bet: ['Risk'],
            pass_reasons: [],
            reason_codes: ['code'],
        });
        assert.equal(normalize(fixture({ narrative: 'bad' })).narrative, null);
    }
});
test('basketball legacy plans and context layers remain distinct from MLB and NFL plans', () => {
    const narrative = {
        betting_plan: {
            spread_lean: 'Home +3',
            moneyline_hedge: 'Legacy reason',
        },
        context_layer: { source: 'stored' },
    };
    assert.deepEqual(
        basketball(fixture({ narrative })).narrative.betting_plan,
        { bet_pick: 'Home +3', reasoning: 'Legacy reason' },
    );
    assert.deepEqual(
        basketball(fixture({ narrative })).narrative.context_layer,
        { source: 'stored' },
    );
    assert.equal(mlb(fixture({ narrative })).narrative.betting_plan, null);
    assert.equal(
        'context_layer' in mlb(fixture({ narrative })).narrative,
        false,
    );
    assert.deepEqual(
        nfl(fixture({ narrative: { betting_plan: { bet_pick: 'Home' } } }))
            .narrative.betting_plan,
        { bet_pick: 'Home', reasoning: '' },
    );
    assert.equal(
        mlb(fixture({ narrative: { betting_plan: { bet_pick: 'Home' } } }))
            .narrative.betting_plan,
        null,
    );
});
test('sport-specific payload forms and canonical spread signs stay unchanged', () => {
    assert.equal(mlb([fixture()]).predicted_spread, 3);
    assert.equal(basketball([fixture()]), null);
    for (const normalize of [basketball, mlb, nfl]) {
        assert.equal(
            normalize(fixture({ phase: 'pregame', revision: 1 }))
                .predicted_spread,
            -3,
        );
        assert.equal(normalize(fixture()).predicted_spread, 3);
    }
    assert.equal(nfl(fixture({ predicted_spread: null })).predicted_spread, '');
    assert.equal(
        basketball(fixture({ predicted_spread: null })).predicted_spread,
        0,
    );
});
