export const slateSports = ['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba'];
export const slateDate = '2026-11-17';
export const slatePageSize = 2;

export function slateParityFixture(source = 'legacy') {
    const rows = slateSports.flatMap((sport, sportIndex) =>
        Array.from({ length: 7 }, (_, index) => {
            const id = (sportIndex + 1) * 100 + index + 1;
            const date =
                index === 5
                    ? '2026-11-16'
                    : index === 6
                      ? '2026-11-18'
                      : slateDate;
            const home = {
                id: id * 2,
                abbreviation: `H${id}`,
                name: `Home ${id}`,
                display_name: `Home ${id}`,
            };
            const away = {
                id: id * 2 + 1,
                abbreviation: `A${id}`,
                name: `Away ${id}`,
                display_name: `Away ${id}`,
            };
            return {
                id:
                    source === 'canonical'
                        ? `forecast-${sport}-${id}`
                        : id + 10000,
                sport,
                game_id: id,
                status: 'STATUS_SCHEDULED',
                predicted_spread: -3,
                predicted_total: 45,
                win_probability: 0.65,
                projection: {
                    home_win_probability: 0.65,
                    away_win_probability: 0.35,
                    predicted_spread: -3,
                    predicted_total: 45,
                    confidence_score: 70,
                },
                game: {
                    id,
                    season: 2026,
                    season_type: 2,
                    week: 11,
                    game_date: date,
                    game_time: '18:00:00',
                    status: 'STATUS_SCHEDULED',
                    short_name: `${away.abbreviation} @ ${home.abbreviation}`,
                    home_team: home,
                    away_team: away,
                },
            };
        }),
    );
    const forDate = (sport, date = slateDate) =>
        rows.filter(
            (row) => row.sport === sport && row.game.game_date === date,
        );
    const dashboardProps = {
        stats: {
            total_predictions_today: 35,
            total_games_today: 35,
            healthcheck_status: 'passing',
        },
        sports: slateSports.map((sport) => ({
            name: sport.toUpperCase(),
            fullName: `${sport.toUpperCase()} fixture slate`,
            color: 'blue',
            predictions: forDate(sport).map((row) => ({
                ...row,
                sport: sport.toUpperCase(),
                game: row.game.short_name,
                home_team: row.game.home_team.abbreviation,
                away_team: row.game.away_team.abbreviation,
                game_time: `${slateDate}T18:00:00Z`,
                is_live: false,
                is_final: false,
            })),
        })),
    };
    function response(url) {
        const match = url.pathname.match(/^\/api\/v2\/sports\/(\w+)\/(.+)$/);
        if (!match) throw Error(`Unexpected API request: ${url}`);
        const [, sport, endpoint] = match;
        if (!slateSports.includes(sport))
            throw Error(`Unexpected sport: ${sport}`);
        if (endpoint === 'predictions/available-seasons')
            return { data: [2026] };
        if (endpoint === 'predictions/available-dates')
            return { data: ['2026-11-16', slateDate, '2026-11-18'] };
        if (endpoint === 'predictions') {
            const q = url.searchParams;
            if (
                q.get('from_date') !== q.get('to_date') ||
                q.get('season') !== '2026'
            )
                throw Error(`Wrong slate filters: ${url}`);
            const data = forDate(sport, q.get('from_date'));
            const page = Number(q.get('page') ?? 1);
            return {
                data: data.slice(
                    (page - 1) * slatePageSize,
                    page * slatePageSize,
                ),
                meta: {
                    prediction_source: source,
                    pagination: {
                        current_page: page,
                        last_page: Math.ceil(data.length / slatePageSize),
                        per_page: slatePageSize,
                        total: data.length,
                    },
                },
            };
        }
        if (endpoint === 'daily-picks')
            return {
                data: {
                    date: url.searchParams.get('date'),
                    candidates: [],
                    top_picks: [],
                    period_models_by_game: {},
                    summary: { slate_games: 5, candidate_count: 0 },
                    market_counts: {},
                    performance_summary: {},
                },
            };
        if (endpoint === 'signals')
            return {
                data: {
                    season: 2026,
                    as_of_date: slateDate,
                    slate_date: slateDate,
                    recommended_bets: [],
                    finals: [],
                    spread: [],
                    moneyline: [],
                    totals: [],
                    streaks: [],
                    backend_review: {
                        model: 'fixture',
                        strengths: [],
                        watch_items: [],
                    },
                },
            };
        throw Error(`Unexpected API request: ${url}`);
    }
    return { rows, forDate, dashboardProps, response };
}
