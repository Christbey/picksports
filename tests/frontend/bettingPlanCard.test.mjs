import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

test('CFB passes its forecast to the betting plan fallback', async () => {
    const page = await readFile(
        new URL('../../resources/js/pages/CFB/Game.vue', import.meta.url),
        'utf8',
    );
    assert.match(
        page,
        /<BettingPlanCard\s[^>]*:prediction="pageProps\.prediction"/,
    );
});

test('betting plans render public decisions without a narrative and disclose missing recommendations', async () => {
    const server = await createServer({
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
    try {
        const { default: Card } = await server.ssrLoadModule(
            '/resources/js/components/game-page/BettingPlanCard.vue',
        );
        const render = (props = {}) =>
            renderToString(createSSRApp(Card, props));
        const missing = await render({
            prediction: { recommendation: null, narrative: null },
        });
        assert.match(
            missing,
            /No betting recommendation is available for this forecast/,
        );
        assert.doesNotMatch(missing, /Check back|refresh/);
        const bet = await render({
            prediction: {
                recommendation: {
                    recommendation_type: 'bet',
                    is_bet: true,
                    prediction_phase: 'pregame',
                    team_name: 'Wisconsin',
                    market_type: 'moneyline',
                    market_price: 124,
                },
            },
        });
        assert.match(bet, /Wisconsin · Moneyline · \+124/);
        const blocked = await render({
            prediction: {
                public_recommendation: {
                    type: 'no_play',
                    label: 'No bet',
                    block_reasons: ['not_validated'],
                },
                narrative: {
                    betting_plan: {
                        bet_pick: 'Bet Wisconsin',
                        classification: 'bet',
                    },
                },
            },
        });
        assert.match(blocked, /No bet/);
        assert.doesNotMatch(blocked, /Bet Wisconsin/);
        assert.match(
            await render({ lockedMessage: 'Subscription required' }),
            /Subscription required/,
        );
        assert.match(await render(), /No betting plan is available/);
        assert.match(
            await render({
                bettingPlan: {
                    bet_pick: 'Stored analysis',
                    reasoning: 'Available narrative',
                },
            }),
            /Stored analysis/,
        );
    } finally {
        await server.close();
    }
});
