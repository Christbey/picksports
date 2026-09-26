// Local visual fixture, not production data. Open the printed URL with browser tooling.
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';

const root = fileURLToPath(new URL('../../', import.meta.url));
const signal = {
    id: 2,
    label: 'Top-5 offensive EPA vs bottom-5 defensive EPA',
    category: 'overall',
    status: 'matched',
    offense_team_id: 1,
    defense_team_id: 2,
    reason: null,
    evidence: {
        metric: 'epa',
        source: 'nflverse_pbp_plays: pass/run plays (sacks included)',
        league_teams: 32,
        offense: { value: 0.15, rank: 3, games: 5 },
        defense: { value: 0.2, rank: 30, games: 5 },
    },
};
const side = (team_id, label) => ({
    team_id,
    label,
    window: 'Current and previous 3 regular seasons',
    records: [
        {
            id: 'home',
            catalog_id: 321,
            label: 'Home record',
            definition: 'Designated home games, excluding neutral sites.',
            minimum_sample: 3,
            sample_size: 12,
            record: { wins: 7, losses: 4, ties: 1 },
            status: 'descriptive_record',
            from_date: '2025-09-01',
            through_date: '2026-09-13',
            game_ids: [],
        },
    ],
    market_records: {
        ats: {
            status: 'unavailable',
            reason: 'Verified immutable historical quotes are not supplied.',
        },
    },
    limitations: ['Descriptive W-L-T only; situations overlap.'],
});
const data = {
    generated_at: '2026-09-19T23:00:00Z',
    matchup: {
        version: 'fixture',
        mode: 'descriptive_only',
        season: 2026,
        cutoff_at: '2026-10-18T17:00:00Z',
        window: 'season_to_date',
        minimum_games: 3,
        minimum_league_teams: 32,
        summary: { supported_rules: 52 },
        signals: [
            signal,
            {
                ...signal,
                id: 6,
                label: 'Top-10 offensive EPA vs bottom-10 defensive EPA',
            },
        ],
        catalog: [
            {
                id: 2,
                label: signal.label,
                category: 'overall',
                support: 'implemented',
                reason: null,
            },
            {
                id: 195,
                label: 'QB elite vs blitz vs blitz-heavy defense',
                category: 'quarterback_scheme',
                support: 'unavailable',
                reason: 'Requires validated charting and an explicit threshold.',
                required_inputs: ['Verified QB-level blitz splits'],
            },
            {
                id: 321,
                label: 'Home record',
                category: 'travel_home_road',
                support: 'situational_records',
                reason: null,
            },
        ],
        limitations: [
            'Reconstructed from currently stored history, not an immutable as-known snapshot.',
        ],
    },
    situational: { away: side(1, 'DET'), home: side(2, 'BUF') },
};
let requests = 0;
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        {
            name: 'matchup-signals-fixture',
            configureServer(vite) {
                vite.middlewares.use(
                    '/api/v2/sports/nfl/games/1722/matchup-signals',
                    (_request, response) => {
                        console.log(`Fixture evidence request ${++requests}`);
                        response.setHeader('Content-Type', 'application/json');
                        const previous =
                            _request.url.includes('previous_season');
                        response.end(
                            JSON.stringify({
                                data: {
                                    ...data,
                                    matchup: {
                                        ...data.matchup,
                                        window: previous
                                            ? 'previous_season'
                                            : 'season_to_date',
                                        season: previous ? 2025 : 2026,
                                    },
                                },
                            }),
                        );
                    },
                );
                vite.middlewares.use(
                    '/__matchup-signals',
                    async (_request, response) => {
                        response.setHeader('Content-Type', 'text/html');
                        response.end(
                            await vite.transformIndexHtml(
                                '/__matchup-signals',
                                `<!doctype html><html class="dark"><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><main class="mx-auto max-w-4xl space-y-4 p-3"><p class="text-sm text-muted-foreground">SYNTHETIC QA FIXTURE — not production picks</p><div id="app"></div></main><script type="module">
import { createApp } from 'vue';
import Signals from '/resources/js/components/game-page/NflMatchupSignals.vue';
import '/resources/css/app.css';
createApp(Signals, { gameId: 1722 }).mount('#app');
</script></body></html>`,
                            ),
                        );
                    },
                );
            },
        },
        vue(),
        tailwind(),
    ],
    resolve: { alias: { '@': `${root}resources/js` } },
    server: {
        host: '127.0.0.1',
        port: 5192,
        strictPort: true,
        hmr: false,
        ws: false,
        watch: null,
    },
});
await server.listen();
console.log('http://127.0.0.1:5192/__matchup-signals');
