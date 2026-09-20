// Synthetic local visual QA only; no production data or API calls.
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';
const root = fileURLToPath(new URL('../../', import.meta.url));
const metrics = [
    {
        id: 1,
        display_rank: 1,
        team: { id: 1, display_name: 'Detroit Lions' },
        record_label: '1-0-1',
        games_played: 2,
        net_true_epa_per_play: 0.125,
        offensive_true_epa_per_play: 0.2,
        defensive_true_epa_per_play: 0.075,
        points_per_game: 27.5,
        points_allowed_per_game: 21,
        yards_per_game: 400,
        home_rating: 6.5,
        consistency_rating: 72,
        sample_sizes: {
            games: 2,
            home: 2,
            away: 0,
            last_5: 2,
            last_10: 2,
            offensive_epa_plays: 124,
            defensive_epa_plays: 118,
            yards_per_game: 1,
        },
        updated_at: '2026-09-20T12:00:00Z',
    },
    {
        id: 2,
        display_rank: null,
        team: { id: 2, display_name: 'Buffalo Bills' },
        sample_sizes: null,
        updated_at: '2026-09-18T12:00:00Z',
    },
];
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        {
            name: 'metrics-preview',
            configureServer(vite) {
                vite.middlewares.use('/__nfl-metrics', async (_req, res) => {
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            '/__nfl-metrics',
                            `<!doctype html><html class="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><main class="mx-auto max-w-6xl space-y-4 p-3"><p>SYNTHETIC QA FIXTURE — not production data</p><h1 class="text-2xl font-semibold">NFL Team Metrics</h1><div id="app"></div></main><script type="module">
import { createApp } from 'vue';
import Board from '/resources/js/components/NflTeamMetricsBoard.vue';
import { nflTeamMetricsConfig as config } from '/resources/js/config/sport-team-metrics-configs.ts';
import '/resources/css/app.css';
createApp(Board, { metrics: ${JSON.stringify(metrics)}, columns: config.columns, teamLink: config.teamLink, sortLabel: 'Net EPA/Play' }).mount('#app');
</script></body></html>`,
                        ),
                    );
                });
            },
        },
        vue(),
        tailwind(),
    ],
    resolve: { alias: { '@': `${root}resources/js` } },
    server: {
        host: '127.0.0.1',
        port: 5193,
        strictPort: true,
        hmr: false,
        ws: false,
        watch: null,
    },
});
await server.listen();
console.log('http://127.0.0.1:5193/__nfl-metrics');
