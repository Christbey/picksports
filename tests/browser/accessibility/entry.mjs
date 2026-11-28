// Mounted real SFCs/controls; only transport/page-props are synthetic.
import { createApp, h, reactive, provide, nextTick } from 'vue';
const id = globalThis.__A11Y_CASE_ID;
globalThis.__a11yCalls = [];
globalThis.__a11yForms = [];
globalThis.__a11yPage = reactive({
    url: location.pathname,
    props: {
        auth: {
            user: {
                id: 1,
                name: 'Fixture viewer',
                email: 'synthetic@example.test',
                email_verified_at: '2026-01-01',
            },
        },
        sidebarOpen: true,
        impersonation: { active: false },
        subscription: {
            tier: 'premium',
            tiers_enabled: false,
            is_subscribed: true,
        },
        name: 'PickSports',
    },
});
globalThis.__a11yValidation = {};
let Root,
    props = {};
if (['A02', 'A03', 'A04'].includes(id)) {
    // Existing actual Dashboard+AppLayout fixture; shared Inertia proxy reads this page.
    delete globalThis.__a11yPage;
    await import('/tests/frontend/fixtures/dashboardDesign.mjs');
    globalThis.__a11yReady = true;
} else {
    if (id === 'A01') {
        const { homepagePerformance } =
            await import('/tests/frontend/fixtures/homepageDesign.mjs');
        Root = (await import('/resources/js/pages/Welcome.vue')).default;
        props = { performance: homepagePerformance(), canRegister: true };
        globalThis.__a11yPage.props.auth.user = null;
    } else if (id === 'B04') {
        const { forecastRows } =
            await import('/tests/frontend/fixtures/forecastUi.mjs');
        const context =
            await import('/resources/js/composables/useCbbForecast.ts');
        const Card = (
            await import('/resources/js/components/predictions/CbbForecastChampionship.vue')
        ).default;
        globalThis.__a11yApi = {
            forecasts: {
                index: async () => ({
                    data: forecastRows(),
                    meta: {
                        available_seasons: [2026],
                        actual_field_size: 3,
                        mode: 'baseline',
                    },
                }),
            },
        };
        Root = {
            setup() {
                const state = context.useCbbForecast();
                provide(context.cbbForecastKey, state);
                return () =>
                    h('main', { class: 'min-w-0 max-w-full p-3' }, h(Card));
            },
        };
    } else if (
        (id.startsWith('B0') && id !== 'B05') ||
        ['D01', 'D02'].includes(id)
    ) {
        const { predictionBoardUi } =
            await import('/tests/frontend/fixtures/predictionBoardUi.mjs');
        // CBB's supported shared board owner, not a claim about NFL's dedicated wrapper.
        const f = predictionBoardUi(
            'cbb',
            'date',
            id === 'D02' ? 'error' : 'normal',
        );
        globalThis.__a11yBoard = f;
        if (id === 'D01') {
            let released = false;
            const pending = [];
            const empty = {
                data: [],
                meta: {
                    pagination: { current_page: 1, last_page: 1, total: 0 },
                },
            };
            f.api.predictions.index = () =>
                released
                    ? Promise.resolve(empty)
                    : new Promise((resolve) => pending.push(resolve));
            globalThis.__a11yReleaseEmpty = () => {
                released = true;
                pending.splice(0).forEach((resolve) => resolve(empty));
            };
        }
        if (id === 'B03') {
            const originalIndex = f.api.predictions.index;
            let hold = false;
            const pending = [];
            globalThis.__a11yPaginationDiagnostic = {
                pending: 0,
                hold() {
                    hold = true;
                },
                release() {
                    hold = false;
                    for (const item of pending.splice(0)) {
                        item.resolve(originalIndex(...item.args));
                    }
                    this.pending = 0;
                },
            };
            f.api.predictions.index = (...args) =>
                hold
                    ? new Promise((resolve) => {
                          pending.push({ resolve, args });
                          globalThis.__a11yPaginationDiagnostic.pending =
                              pending.length;
                      })
                    : originalIndex(...args);
        }
        globalThis.__a11yApi = f.api;
        Root = (await import('/resources/js/pages/CBB/Predictions.vue'))
            .default;
        props = {};
    } else if (id === 'B05') {
        const Card = (
            await import('/resources/js/components/player-props/NflPlayerPropCard.vue')
        ).default;
        const record = {
            wins: 1,
            games: 2,
            losses: 1,
            pushes: 0,
            win_rate: 50,
        };
        const rec = {
            id: 1,
            player: {
                name: 'Synthetic player',
                team: 'HOME',
                position: 'RB',
                url: null,
            },
            recommendation: 'Under',
            line: 13.5,
            market: 'Rushing yards',
            odds: -110,
            bookmaker: 'Fixture',
            confidence: 80,
            fetched_at: '2026-09-20T15:10:00Z',
            freshness_hours: 1,
            game: {
                away_team: 'AWAY',
                home_team: 'HOME',
                kickoff_label: 'Synthetic fixture',
            },
            stats: {
                season_avg: 7,
                historical_avg: 10,
                season_summary: { season: 2026, games: 2 },
                cover_record: {
                    season: record,
                    last_season: record,
                    all_time: record,
                    vs_opponent: record,
                    vs_conference: record,
                },
            },
        };
        Root = {
            render: () =>
                h(
                    'main',
                    {},
                    h(Card, { rec, nowMs: Date.parse('2026-09-20T16:00:00Z') }),
                ),
        };
    } else if (id === 'C01') {
        Root = (await import('/resources/js/pages/auth/Login.vue')).default;
        props = {
            canResetPassword: true,
            canRegister: true,
            oauthProviders: [],
        };
        globalThis.__a11yValidation = { email: 'Synthetic email rejected' };
    } else if (['C02', 'C04', 'C05'].includes(id)) {
        const nativeFetch = window.fetch.bind(window);
        globalThis.__a11yPasskeyRequests = [];
        window.fetch = (url, options) => {
            if (String(url) === '/passkeys' && options?.method === 'GET') {
                if (id === 'C04')
                    return new Promise((resolve) =>
                        globalThis.__a11yPasskeyRequests.push({ resolve }),
                    );
                return Promise.resolve(
                    new Response(JSON.stringify({ passkeys: [] }), {
                        headers: { 'Content-Type': 'application/json' },
                    }),
                );
            }
            return nativeFetch(url, options);
        };
        Root = (await import('/resources/js/pages/settings/Profile.vue'))
            .default;
        props = { mustVerifyEmail: false };
        globalThis.__a11yValidation = { email: 'Synthetic email rejected' };
    } else if (id === 'C03') {
        const { alertProps } =
            await import('/tests/frontend/fixtures/settingsPageSplit.mjs');
        const { installPushBrowser } =
            await import('/tests/frontend/fixtures/settingsPageSplitBrowser.mjs');
        installPushBrowser('normal');
        Root = (
            await import('/resources/js/pages/settings/AlertPreferences.vue')
        ).default;
        props = alertProps('normal');
        globalThis.__a11yValidation = {
            phone_number: 'Synthetic phone rejected',
        };
    } else if (id === 'D03') {
        const game = {
            id: 1722,
            season: 2026,
            season_type: '2',
            week: 2,
            status: 'STATUS_IN_PROGRESS',
            game_date: '2026-09-17',
            game_time: '20:15:00',
            starts_at: '2026-09-18T00:15:00Z',
            home_score: 14,
            away_score: 7,
            period: 2,
            game_clock: '5:00',
            home_team_id: 2,
            away_team_id: 1,
            home_team: { id: 2, abbreviation: 'HOME' },
            away_team: { id: 1, abbreviation: 'AWAY' },
        };
        globalThis.__a11yApi = {
            games: {
                page: async () => ({
                    data: { game, prediction: null, team_stats: [] },
                }),
                liveSnapshot: async () => ({
                    data: {
                        game,
                        projection: {
                            live_win_probability: 0.81,
                            live_predicted_spread: 12.5,
                            live_predicted_total: 48,
                            live_seconds_remaining: 1620,
                        },
                        warning: 'Synthetic live estimate',
                    },
                }),
            },
            stats: { teams: async () => ({ data: [] }) },
            teams: {
                games: async () => ({ data: [] }),
                trends: async () => ({
                    data: { sample_size: 0, trends: {}, locked_trends: {} },
                }),
            },
        };
        const { useNflGamePage } =
            await import('/resources/js/composables/useNflGamePage.ts');
        const Card = (
            await import('/resources/js/components/BettingAnalysisCard.vue')
        ).default;
        Root = {
            setup() {
                const state = useNflGamePage(1722);
                return () =>
                    h(
                        'main',
                        {},
                        h(Card, {
                            livePrediction: state.livePredictionData.value,
                            bettingValue: [],
                        }),
                    );
            },
        };
    } else if (id === 'D04') {
        const { monitorProps } =
            await import('/tests/frontend/fixtures/adminPages.mjs');
        const { Tabs, TabsList, TabsTrigger, TabsContent } =
            await import('/resources/js/components/ui/tabs/index.ts');
        const context =
            await import('/resources/js/composables/useNflModelMonitoring.ts');
        const Evaluation = (
            await import('/resources/js/components/admin/NflMonitoringEvaluation.vue')
        ).default;
        const data = reactive(
            monitorProps({
                summary: { ...monitorProps().summary, calibration_games: 2 },
                evaluation_windows: [],
            }),
        );
        Root = {
            setup() {
                provide(
                    context.nflMonitoringKey,
                    context.useNflModelMonitoring(data),
                );
                return () =>
                    h(Tabs, { defaultValue: 'evaluation' }, () => [
                        h(
                            TabsList,
                            { 'aria-label': 'Monitoring sections' },
                            () => [
                                h(
                                    TabsTrigger,
                                    { value: 'evaluation' },
                                    () => 'Evaluation',
                                ),
                                h(
                                    TabsTrigger,
                                    { value: 'details' },
                                    () => 'Details',
                                ),
                            ],
                        ),
                        h(Evaluation),
                        h(
                            TabsContent,
                            { value: 'details' },
                            () => 'Synthetic monitoring details',
                        ),
                    ]);
            },
        };
    } else throw new Error('Unknown main a11y fixture ' + id);
    const app = createApp(Root, props);
    app.config.globalProperties.$page = globalThis.__a11yPage;
    app.mount('#app');
    await nextTick();
    globalThis.__a11yReady = true;
}
