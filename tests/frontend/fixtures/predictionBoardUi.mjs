export function predictionBoardUi(sport, filterMode, scenario = 'normal') {
    const calls = [];
    const flags = { live: false, fail: false };
    const row = (id) => ({
        id,
        game_id: id,
        status: flags.live ? 'STATUS_IN_PROGRESS' : 'STATUS_SCHEDULED',
        projection: {
            home_win_probability: 0.65,
            away_win_probability: 0.35,
            win_probability: 0.65,
            predicted_spread: 3,
            predicted_total: 45,
            confidence_score: 80,
        },
        live_status: flags.live ? 'live' : null,
        live_win_probability: flags.live ? 0.7 : null,
        live_predicted_total: flags.live ? 50 : null,
        live_predicted_spread: flags.live ? 0 : null,
        live_seconds_remaining: flags.live ? 1800 : null,
        value_signal: id % 2 ? { has_playable_value: true } : null,
        recommendation:
            id % 2
                ? {
                      recommendation_type: 'bet',
                      is_bet: true,
                      prediction_phase: 'pregame',
                      side: 'home',
                  }
                : null,
        updated_at: '2026-09-26T16:00:00Z',
        created_at: '2026-09-26T15:00:00Z',
        game: {
            id,
            season: 2026,
            season_type: sport === 'mlb' ? 1 : 2,
            week: 0,
            game_date: '2026-09-26',
            game_time: '17:00:00',
            status: flags.live ? 'STATUS_IN_PROGRESS' : 'STATUS_SCHEDULED',
            home_score: flags.live ? 7 : null,
            away_score: flags.live ? 0 : null,
            period: flags.live ? 2 : null,
            clock: flags.live ? '12:00' : null,
            home_team: {
                id: 1,
                abbreviation: `HOM${id}`,
                location: 'Home City',
                name: id % 2 ? 'Tigers' : 'Lions',
                display_name: id % 2 ? 'Home Tigers' : 'Home Lions',
            },
            away_team: {
                id: 2,
                abbreviation: `AWY${id}`,
                location: 'Away City',
                name: 'Bears',
                display_name: 'Away Bears',
            },
        },
    });
    const api = {
        predictions: {
            availableSeasons: async () => ({ data: [2026, 2025] }),
            availableDates: async () => ({
                data: scenario === 'empty' ? [] : ['2026-09-26', '2026-09-27'],
            }),
            index: async (_, options) => {
                calls.push({
                    query: { ...options.query },
                    cache: options.init?.cache,
                });
                if (scenario === 'error' || flags.fail) return null;
                const page = Number(options.query.page ?? 1);
                return {
                    data:
                        scenario === 'empty'
                            ? []
                            : options.query.per_page === 1
                              ? [row(1)]
                              : [row(page * 2 - 1), row(page * 2)],
                    meta: {
                        pagination: {
                            current_page: page,
                            last_page: 2,
                            total: 4,
                        },
                    },
                };
            },
        },
        userBets: { index: async () => ({ tracking: null }) },
    };
    const config = {
        sport,
        title: `${sport.toUpperCase()} Predictions`,
        subtitle: 'Game predictions',
        useEasternTime: false,
        showGameTime: true,
        confidenceIsDecimal: false,
        confidenceDecimals: 1,
        filterMode,
        seasonWeekConfig: {
            regularSeasonWeeks: 15,
            postseasonOptions: [{ value: '1', label: 'First round' }],
        },
    };
    return { api, calls, flags, config };
}
