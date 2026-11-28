import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createServer } from 'vite';
import { fileURLToPath } from 'node:url';
import { pregameBetCalls } from '../../resources/js/lib/pregameBetCalls.ts';
let server, formatHomeSpreadLine;
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
    ({ formatHomeSpreadLine } = await server.ssrLoadModule(
        '/resources/js/lib/publicSportPresentation.ts',
    ));
});
after(async () => {
    await server?.close();
});
test('production-shaped forecasts show all three directions despite null recommendations and blocked approvals', () => {
    assert.deepEqual(
        pregameBetCalls(
            {
                home_win_probability: 0.827308,
                predicted_total: 57.6,
                recommendation: null,
                value_signal: {
                    has_playable_value: false,
                    decision_status: 'blocked',
                },
                model_bet_context: {
                    model_home_spread: -9.4,
                    home_spread: -13,
                    total: 54.5,
                },
            },
            'Alabama',
            'South Carolina',
        ),
        {
            winner: 'Bet Alabama to win',
            spread: 'Bet South Carolina +13 to cover',
            total: 'Bet Over 54.5',
        },
    );
});
test('numeric strings, away winners, favorites and unders retain their signs', () => {
    assert.deepEqual(
        pregameBetCalls(
            {
                home_win_probability: '0.2',
                predicted_total: '40',
                model_bet_context: {
                    model_home_spread: '10',
                    home_spread: '3',
                    total: '45.5',
                },
            },
            'Home',
            'Away',
        ),
        {
            winner: 'Bet Away to win',
            spread: 'Bet Away -3 to cover',
            total: 'Bet Under 45.5',
        },
    );
});
test('missing lines and exact ties are reported honestly rather than replaced with No bet', () => {
    assert.deepEqual(
        pregameBetCalls(
            {
                home_win_probability: 0.5,
                predicted_total: 45,
                model_bet_context: {
                    model_home_spread: -3,
                    home_spread: -3,
                    total: 45,
                },
            },
            'Home',
            'Away',
        ),
        {
            winner: 'Even matchup',
            spread: 'Model projects a push',
            total: 'Model projects a push',
        },
    );
    assert.equal(
        pregameBetCalls({ home_win_probability: 0.7 }, 'Home', 'Away').spread,
        'Line unavailable',
    );
});

test('dashboard labels model projections when offers are missing or a game is postponed', () => {
    const forecast = {
        win_probability: 0.3,
        predicted_total: 141.2,
        model_bet_context: { model_home_spread: 4.5 },
    };
    const projection = {
        winner: 'Away',
        spread: 'Projected Away by 4.5',
        total: 'Projected 141.2',
    };

    assert.deepEqual(
        pregameBetCalls(forecast, 'Home', 'Away', true),
        projection,
    );

    const withLines = {
        ...forecast,
        model_bet_context: {
            ...forecast.model_bet_context,
            home_spread: 3.5,
            total: 140.5,
        },
    };
    assert.deepEqual(
        pregameBetCalls(
            { ...withLines, status: 'STATUS_POSTPONED' },
            'Home',
            'Away',
            true,
        ),
        projection,
    );
    assert.deepEqual(pregameBetCalls(withLines, 'Home', 'Away', true), {
        winner: 'Away',
        spread: 'Away -3.5 to cover',
        total: 'Over 140.5',
    });
});

test('dashboard grades the displayed spread and reports projection error without a market', () => {
    const forecast = {
        model_bet_context: { model_home_spread: -8, home_spread: -10 },
    };
    // The pick is Away +10 even though the model projects Home to win.
    assert.equal(
        pregameBetCalls(forecast, 'Home', 'Away', true, 9).spreadGrade,
        'Covered',
    );
    assert.equal(
        pregameBetCalls(forecast, 'Home', 'Away', true, 10).spreadGrade,
        'Push',
    );
    assert.equal(
        pregameBetCalls(forecast, 'Home', 'Away', true, 11).spreadGrade,
        'Missed',
    );
    const modelOnly = { model_bet_context: { model_home_spread: -8.2 } };
    assert.equal(
        pregameBetCalls(modelOnly, 'Home', 'Away', true, -3).spreadGrade,
        '11.2 pts off',
    );
    assert.equal(
        pregameBetCalls({ predicted_spread: 8 }, 'Home', 'Away', true, 8)
            .spreadGrade,
        'Exact margin',
    );
    assert.equal(
        pregameBetCalls(forecast, 'Home', 'Away', true).spreadGrade,
        undefined,
    );
});

test('dashboard grades the displayed total and distinguishes a model-only projection', () => {
    const forecast = {
        predicted_total: 150,
        model_bet_context: { total: 145 },
    };
    assert.equal(
        pregameBetCalls(forecast, 'Home', 'Away', true, 2, 146).totalGrade,
        'Hit',
    );
    assert.equal(
        pregameBetCalls(forecast, 'Home', 'Away', true, 2, 145).totalGrade,
        'Push',
    );
    assert.equal(
        pregameBetCalls(forecast, 'Home', 'Away', true, 2, 144).totalGrade,
        'Missed',
    );
    assert.equal(
        pregameBetCalls(
            { predicted_total: 150.2 },
            'Home',
            'Away',
            true,
            2,
            149,
        ).totalGrade,
        '1.2 pts off',
    );
    assert.equal(
        pregameBetCalls(forecast, 'Home', 'Away', true).totalGrade,
        undefined,
    );
});

test('assessment fallback keeps home margins while public spread labels invert them once', () => {
    const calls = pregameBetCalls(
        {
            value_signal: {
                spread_assessment: {
                    model_home_margin: 7,
                    market_home_line: -3,
                },
            },
        },
        'Home',
        'Away',
    );
    assert.equal(calls.winner, 'Bet Home to win');
    assert.equal(calls.spread, 'Bet Home -3 to cover');
    assert.equal(formatHomeSpreadLine(7), '-7.0');
    assert.equal(formatHomeSpreadLine(-3), '+3.0');
    assert.equal(formatHomeSpreadLine(0), 'PK');
    assert.equal(formatHomeSpreadLine(null), 'N/A');
});
