import { createApp, h, reactive, nextTick } from 'vue';
import Dashboard from '/resources/js/pages/Dashboard.vue';
import AppLayout from '/resources/js/layouts/AppLayout.vue';
globalThis.__dashboardReloads = [];
globalThis.__dashboardPosts = [];
globalThis.__dashboardPage = reactive({
    url: '/dashboard',
    props: {
        auth: {
            user: {
                id: 1,
                name: 'Test User',
                email: 'test@example.com',
                email_verified_at: '2026-01-01',
            },
        },
        sidebarOpen: true,
        impersonation: { active: false },
        subscription: { tier: 'free', tiers_enabled: true },
    },
});
const prediction = {
    id: 1,
    sport: 'nfl',
    game_id: 101,
    game: 'AWY @ HOME',
    game_time: '2026-11-07T20:00:00Z',
    home_team: 'HOME',
    away_team: 'AWY',
    win_probability: 0.65,
    predicted_spread: -3.5,
    predicted_total: 45.5,
};
globalThis.__dashboardProps = reactive({
    stats: {
        total_predictions_today: 3,
        total_games_today: 4,
        healthcheck_status: 'passing',
    },
    sports: [
        {
            name: 'NFL',
            fullName: 'National Football League',
            color: 'blue',
            predictions: [
                prediction,
                {
                    ...prediction,
                    id: 2,
                    game_id: 102,
                    is_live: true,
                    away_score: 0,
                    home_score: 7,
                    period: 2,
                    game_clock: '08:23',
                    live_win_probability: 0.7,
                },
            ],
        },
        {
            name: 'MLB',
            fullName: 'Major League Baseball',
            color: 'green',
            predictions: [
                {
                    ...prediction,
                    id: 3,
                    sport: 'mlb',
                    game_id: 201,
                    season_type: 1,
                    is_final: true,
                    away_score: 0,
                    home_score: 2,
                },
            ],
        },
    ],
});
window.fetch = async (url) =>
    new Response(
        JSON.stringify({
            data: String(url).includes('live-scoreboard')
                ? { games: [], updated_at: '2026-11-07' }
                : [],
            tracking_summary: null,
        }),
        { headers: { 'Content-Type': 'application/json' } },
    );
const app = createApp({
    setup() {
        return () =>
            location.search.includes('plain=1')
                ? h(AppLayout, {}, () => h('p', 'Other page'))
                : h(Dashboard, __dashboardProps);
    },
});
app.config.globalProperties.$page = __dashboardPage;
globalThis.__dashboardApp = app;
app.mount('#app');
await nextTick();
globalThis.__dashboardReady = true;
