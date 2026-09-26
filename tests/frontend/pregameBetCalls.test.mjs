import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pregameBetCalls } from '../../resources/js/lib/pregameBetCalls.ts';
test('forecasts and unpublished candidates never become bets', () => {
    assert.deepEqual(
        pregameBetCalls(
            {
                predicted_spread: 12,
                predicted_total: 50,
                recommendation: {
                    candidate: {
                        is_bet: true,
                        recommendation_type: 'bet',
                        market_type: 'moneyline',
                        pick_side: 'home',
                    },
                },
            },
            'Home',
            'Away',
        ),
        { winner: 'No bet', spread: 'No bet', total: 'No bet' },
    );
});
test('approved winner and cover use the actual side and handicap', () => {
    assert.deepEqual(
        pregameBetCalls(
            {
                recommendation: {
                    is_bet: true,
                    recommendation_type: 'bet',
                    market_type: 'moneyline',
                    pick_side: 'away',
                },
                value_signal: {
                    has_playable_value: true,
                    best: { type: 'spread', side: 'away', market_line: 3.5 },
                },
            },
            'Home',
            'Away',
        ),
        {
            winner: 'Bet Away to win',
            spread: 'Bet Away +3.5 to cover',
            total: 'No bet',
        },
    );
});
test('totals require an approved direction and actual market line', () => {
    assert.equal(
        pregameBetCalls(
            {
                value_signal: {
                    has_playable_value: true,
                    best: { type: 'total', side: 'under', market_line: 45.5 },
                },
            },
            'Home',
            'Away',
        ).total,
        'Bet Under 45.5',
    );
    assert.equal(
        pregameBetCalls(
            {
                value_signal: {
                    has_playable_value: false,
                    best: { type: 'total', side: 'over', market_line: 45.5 },
                },
            },
            'Home',
            'Away',
        ).total,
        'No bet',
    );
});
