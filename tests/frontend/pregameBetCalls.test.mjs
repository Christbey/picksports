import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pregameBetCalls } from '../../resources/js/lib/pregameBetCalls.ts';
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
