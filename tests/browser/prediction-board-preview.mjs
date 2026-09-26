// Local visual fixture. Synthetic games; no production calls or credentials.
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';
const root = fileURLToPath(new URL('../../', import.meta.url));
const games = [
    ['Iowa', 'Michigan', 1.6, 42],
    ['Ole Miss', 'Florida', 2.9, 54],
    ['South Carolina', 'Alabama', 9.4, 57.6],
    ['Arizona', 'Washington State', 12.1, 49.5],
];
const rows = games.map(([away, home, margin, total], i) => ({
    id: i + 1,
    game_id: i + 1,
    recommendation: i === 0 ? { is_bet: true, recommendation_type: 'bet', market_type: 'moneyline', pick_side: 'home', prediction_phase: 'pregame' } : null,
    value_signal: i < 2 ? { has_playable_value: true, decision_status: 'validated', best: i === 0 ? {type:'spread', side:'away', market_line:5.5} : {type:'total', side:'under', market_line:55.5} } : null,
    projection: {
        predicted_spread: margin,
        predicted_total: total,
        home_win_probability: 0.6,
    },
    game: {
        id: i + 1,
        season: 2026,
        season_type: 2,
        week: 5,
        game_date: '2026-09-26',
        game_time: '19:30:00',
        status: 'STATUS_SCHEDULED',
        away_team: { display_name: away, abbreviation: away },
        home_team: { display_name: home, abbreviation: home },
    },
}));
const api = `export const useApiV2Client = () => ({userBets:{index:async()=>({tracking:null})},predictions:{availableSeasons:async()=>({data:[2026,2025]}),availableDates:async()=>({data:['2026-08-29','2026-09-26']}),index:async(_sport,{query})=>({data: query.per_page===1 ? [${JSON.stringify(rows[0])}] : query.week==='4' ? [] : ${JSON.stringify(rows)},meta:{pagination:{current_page:1,last_page:1,total:4}}})}});`;
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        {
            name: 'board-preview',
            enforce: 'pre',
            load(id) {
                if (id.endsWith('/composables/useApiV2Client.ts')) return api;
            },
            configureServer(vite) {
                vite.middlewares.use('/__board-preview', async (_req, res) => {
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await vite.transformIndexHtml(
                            '/__board-preview',
                            `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><main class="mx-auto max-w-6xl px-4 py-8 sm:px-8"><p class="mb-8 text-xs text-muted-foreground">LOCAL DESIGN PREVIEW · Synthetic data</p><div id="app"></div></main><script type="module">import {createApp} from 'vue';import Board from '/resources/js/components/SportPredictions.vue';import {cfbPredictionsConfig} from '/resources/js/config/predictions-configs.ts';import '/resources/css/app.css';createApp(Board,{config:cfbPredictionsConfig}).mount('#app');</script></body></html>`,
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
        port: 5194,
        strictPort: true,
        hmr: false,
        ws: false,
        watch: null,
    },
});
await server.listen();
console.log('http://127.0.0.1:5194/__board-preview');
