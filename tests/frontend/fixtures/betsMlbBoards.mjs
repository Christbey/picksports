export const betRows = () =>
    [1, 2, 3].map((id) => ({
        id,
        prediction_id: id,
        prediction_sport: id === 3 ? null : 'mlb',
        bet_amount: 25,
        odds: id === 2 ? '-110' : '+120',
        bet_type: id === 3 ? 'total_under' : 'spread',
        selection_side: id === 3 ? 'under' : 'home',
        selection_label: id === 1 ? 'Home -1.5' : null,
        line: id === 1 ? -1.5 : 0,
        result: ['pending', 'won', 'push'][id - 1],
        profit_loss: id === 2 ? 30 : 0,
        notes: id === 1 ? 'Stored bet note' : null,
        placed_at: '2026-11-06T12:00:00Z',
        settled_at: null,
        prediction: null,
    }));
export const betsPayload = (empty = false) => ({
    bets: {
        data: empty ? [] : betRows(),
        links: [],
        current_page: 1,
        last_page: 1,
    },
    statistics: {
        total_bets: empty ? 0 : 3,
        wins: empty ? 0 : 1,
        losses: 0,
        pushes: empty ? 0 : 1,
        win_rate: empty ? 0 : 100,
        total_wagered: empty ? 0 : 75,
        total_profit: empty ? 0 : 30,
        roi: empty ? 0 : 40,
    },
});
export const mlbPredictions = () =>
    [1, 2].map((id) => ({
        id,
        game_id: id,
        status: 'scheduled',
        game: {
            id,
            short_name: 'AWY @ HOM',
            game_date: '2026-11-06',
            game_time: id === 1 ? '18:00:00' : '20:00:00',
            status: 'scheduled',
            home_team: {
                id: 1,
                abbreviation: 'HOM',
                name: 'Home',
                display_name: 'Home Team',
            },
            away_team: {
                id: 2,
                abbreviation: 'AWY',
                name: 'Away',
                display_name: 'Away Team',
            },
        },
        projection: {
            home_win_probability: 0.6,
            predicted_home_score: 5,
            predicted_away_score: 4,
        },
        market_summary: {
            markets: ['moneyline', 'total'],
            bookmakers: ['Fixture'],
        },
    }));
export const mlbCandidates = () =>
    [1, 2, 3].map((id) => ({
        id,
        season: 2026,
        game_id: id === 3 ? 2 : 1,
        market_type: id === 2 ? 'total' : 'moneyline',
        market_key: 'fixture',
        label: id === 2 ? 'Under 8.5' : 'Home Team',
        side: id === 2 ? 'under' : 'home',
        team: { id: 1, abbreviation: 'HOM', display_name: 'Home Team' },
        player: null,
        game: {
            id: id === 3 ? 2 : 1,
            short_name: 'AWY @ HOM',
            game_date: '2026-11-06T20:00:00Z',
            status: 'scheduled',
            home_team: 'Home Team',
            away_team: 'Away Team',
        },
        line: id === 2 ? 8.5 : 0,
        price: id === 2 ? -110 : 120,
        book: 'Fixture',
        score: 70 + id,
        model_probability: 0.6,
        edge_raw: 0.05,
        status: 'tracking',
        recommendation_label: 'Tracking',
        is_public: false,
        is_tracking_only: true,
        is_bet: false,
        reason_codes: [],
        risk_flags: [],
        generated_at: '2026-11-06T12:00:00Z',
        feature_snapshot: {},
        market_snapshot: {},
    }));
export const dailyPayload = (empty = false) => ({
    data: {
        date: '2026-11-06',
        mode: 'tracking',
        summary: {
            slate_games: empty ? 0 : 2,
            priced_games: empty ? 0 : 2,
            first_inning_priced_games: 0,
            first_3_priced_games: 0,
            first_5_priced_games: 0,
            candidate_count: empty ? 0 : 3,
            top_candidate_count: 0,
            tracking_count: empty ? 0 : 3,
            public_promoted_count: 0,
        },
        board_health: {
            status: empty ? 'no_slate' : 'tracking_ready',
            score: 0,
            slate_coverage: 1,
            pregame_safe_rate: 1,
            market_agreement_rate: 0.5,
            message: 'Stored board fixture',
        },
        market_counts: {
            all: empty ? 0 : 3,
            moneyline: empty ? 0 : 2,
            total: empty ? 0 : 1,
            tracking: empty ? 0 : 3,
        },
        performance_summary: {
            last_7_days: {
                rows: 2,
                wins: 1,
                losses: 1,
                pushes: 0,
                hit_rate: 0.5,
                units: 0,
                avg_score: 70,
            },
            last_30_days: {
                rows: 2,
                wins: 1,
                losses: 1,
                pushes: 0,
                hit_rate: 0.5,
                units: 0,
                avg_score: 70,
            },
            by_market: {},
            mode_note: 'Tracking results',
            sample_warning: 'Only two results',
        },
        achievements: [
            {
                key: 'slate',
                label: 'Slate covered',
                description: 'Stored fixture evidence',
            },
        ],
        target_count: 3,
        public_promoted_count: 0,
        candidate_count: empty ? 0 : 3,
        top_picks: [],
        candidates: empty ? [] : mlbCandidates(),
        period_models_by_game: {},
        blocked_reasons: [],
    },
});
export function installBoardApi(kind, empty = false) {
    globalThis.__boardCalls = [];
    globalThis.__boardFail = false;
    globalThis.__writeFail = false;
    const read = async (type, fn, ...args) => {
        __boardCalls.push({ type, args });
        if (__boardFail) throw Error('Fixture read unavailable');
        return fn();
    };
    globalThis.__boardCleanupApi = {
        userBets: {
            index: (...args) => read('bets', () => betsPayload(empty), ...args),
            store: async (data) => {
                __boardCalls.push({ type: 'store', data });
                if (__writeFail) throw Error('Fixture save failed');
            },
            update: async () => {},
            destroy: async () => {},
            exportUrl: () => '/fixture/export',
        },
        predictions: {
            availableSeasons: (...args) =>
                read('seasons', () => ({ data: [2026, 2025] }), ...args),
            availableDates: (...args) =>
                read(
                    'dates',
                    () => ({ data: ['2026-11-06', '2026-11-07'] }),
                    ...args,
                ),
            index: (...args) =>
                read(
                    'predictions',
                    () => ({ data: empty ? [] : mlbPredictions() }),
                    ...args,
                ),
        },
        dailyPicks: {
            index: (...args) =>
                read('picks', () => dailyPayload(empty), ...args),
        },
    };
}
