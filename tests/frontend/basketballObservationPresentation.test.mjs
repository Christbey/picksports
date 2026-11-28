import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h, provide, reactive } from 'vue';
import { renderToString } from 'vue/server-renderer';
let server, Component, usePlayerStats, playerStatsKey;
before(async () => {
    server = await createServer({
        configFile: false,
        plugins: [
            vue(),
            {
                name: 'observed-player-api',
                enforce: 'pre',
                load(id) {
                    if (id.endsWith('/composables/useApiV2Client.ts'))
                        return 'export const useApiV2Client = () => ({});';
                },
            },
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
    ({ default: Component } = await server.ssrLoadModule(
        '/resources/js/components/player-stats/PlayerStatsResults.vue',
    ));
    ({ usePlayerStats, playerStatsKey } = await server.ssrLoadModule(
        '/resources/js/composables/usePlayerStats.ts',
    ));
});
after(async () => {
    await server?.close();
});
test('actual player results render independent unknown cells and observed zero without fabricated estimates', async () => {
    const player = (full_name) => ({
        full_name,
        position: null,
        headshot_url: null,
        team: null,
    });
    const app = createSSRApp({
        setup() {
            const state = usePlayerStats(
                reactive({
                    config: {
                        sport: 'nba',
                        minGames: 1,
                        statColumns: [
                            { key: 'points_per_game', label: 'PPG' },
                            {
                                key: 'estimated_epa_per_game',
                                label: 'Estimated EPA',
                                format: (n) => n.toFixed(2),
                            },
                        ],
                    },
                }),
            );
            state.loading.value = false;
            state.players.value = [
                {
                    player_id: 1,
                    player: player('Unknown Observation'),
                    games_played: 1,
                    points_per_game: null,
                    estimated_epa_per_game: null,
                },
                {
                    player_id: 2,
                    player: player('Observed Zero'),
                    games_played: 1,
                    points_per_game: 0,
                    estimated_epa_per_game: 0,
                },
            ];
            provide(playerStatsKey, state);
            return () => h(Component);
        },
    });
    const html = await renderToString(app);
    assert.ok(
        html.indexOf('Observed Zero') < html.indexOf('Unknown Observation'),
    );
    const known = html.slice(
        html.indexOf('Observed Zero'),
        html.indexOf('</tr>', html.indexOf('Observed Zero')),
    );
    const unknown = html.slice(
        html.indexOf('Unknown Observation'),
        html.indexOf('</tr>', html.indexOf('Unknown Observation')),
    );
    assert.match(known, />0.0<\/td>/);
    assert.match(known, />0.00<\/td>/);
    assert.equal((unknown.match(/>—<\/td>/g) ?? []).length, 2);
    assert.doesNotMatch(unknown, />0.00<\/td>/);
});
