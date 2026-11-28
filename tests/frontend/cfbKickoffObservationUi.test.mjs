import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            {
                name: 'no-cfb-http',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiClient.ts'))
                        return 'export const fetchJson = () => { throw new Error("SSR must not request a provider or application endpoint"); };';
                },
            },
            vue(),
        ],
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
for (const line of [null, 0]) {
    test(`compiled CFB card preserves actual zero and ${line === null ? 'unavailable' : 'real zero'} pregame line`, async () => {
        const { default: Card } = await server.ssrLoadModule(
            '/resources/js/components/game-page/CfbLiveBettingCard.vue',
        );
        const original = Card.setup;
        const FixtureCard = {
            ...Card,
            setup(props, context) {
                const bindings = original(props, context);
                bindings.payload.value = {
                    data: {
                        id: 1,
                        observed_at: '2027-01-12T18:00:00Z',
                        status: 'live',
                        stale: false,
                        pregame: {
                            source: 'unavailable',
                            spread: null,
                            total: null,
                            home_win_probability: null,
                        },
                        state: {
                            period: 1,
                            clock: '00:00',
                            home_score: 0,
                            away_score: 0,
                        },
                        projection: null,
                        markets: [],
                        props: [
                            {
                                player_id: 1,
                                player: 'Observed player',
                                market: 'player_pass_yds',
                                pregame_line: line,
                                pregame_mean: null,
                                actual: 0,
                                projected: null,
                                status: 'kickoff_unknown',
                                quotes: [],
                            },
                        ],
                    },
                    history: [],
                    meta: {
                        game_status: 'STATUS_IN_PROGRESS',
                        snapshot_count: 1,
                    },
                };
                return bindings;
            },
        };
        const html = await renderToString(
            createSSRApp(FixtureCard, { gameId: 1 }),
        );
        const text = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
        assert.match(text, /Recorded 0\.0/);
        assert.match(text, /Estimated finish —/);
        assert.match(text, /kickoff unknown/);
        assert.match(
            text,
            line === null ? /Pregame line —/ : /Pregame line 0\.0/,
        );
        if (line === null) assert.doesNotMatch(text, /Pregame line 0\.0/);
    });
}
