export const teamRow = (id = 1) => ({
    id,
    name: 'Home',
    display_name: 'Home Team',
    location: 'Home City',
    abbreviation: 'HOM',
    conference: 'East',
    division: 'Central',
    league: 'AL',
    elo_rating: 1500,
    logo: null,
});
export const metricRow = () => ({
    team_id: 1,
    season: 2026,
    offensive_rating: 0,
    defensive_rating: 105,
    net_rating: 0,
    pace: 70,
    strength_of_schedule: 0.5,
    runs_per_game: 4.5,
    runs_allowed_per_game: 4.2,
    run_differential: 0,
    offensive_epa_per_play: 0,
    defensive_epa_per_play: 0,
});
export const gameRows = () => [
    {
        id: 1,
        season: 2026,
        status: 'STATUS_FINAL',
        game_date: '2026-01-01T20:00:00Z',
        home_team_id: 1,
        away_team_id: 2,
        home_team: teamRow(),
        away_team: { ...teamRow(2), name: 'Away', abbreviation: 'AWY' },
        home_score: 0,
        away_score: 0,
    },
    {
        id: 2,
        season: 2026,
        status: 'STATUS_SCHEDULED',
        game_date: '2099-11-06T20:00:00Z',
        home_team_id: 1,
        away_team_id: 2,
        home_team: teamRow(),
        away_team: { ...teamRow(2), name: 'Away', abbreviation: 'AWY' },
        home_score: null,
        away_score: null,
    },
];
export const boardRows = () =>
    [1, 2].map((id) => ({
        id,
        sport: 'nfl',
        game_id: id,
        win_probability: 0.65,
        predicted_spread: 5,
        predicted_total: 44,
        game: {
            ...gameRows()[1],
            id,
            game_date: '2026-11-06',
            game_time: id === 1 ? '12:00:00' : '16:00:00',
            short_name: 'AWY @ HOM',
        },
        nfl_board: {
            market: {
                home_spread: -2.5,
                total_line: 45,
                observed_at: '2026-11-06T12:00:00Z',
            },
            research: { status: 'reviewed', decision: 'pass' },
            can_retry_research: false,
        },
        pro_signal_layer: {
            bet_classification: 'lean',
            market_scores: { winner: { tier: 'watchlist' } },
            reason_codes: ['stored_signal'],
        },
    }));
export function installTeamNflApi(empty = false, initialFailure = '') {
    globalThis.__teamNflCalls = [];
    globalThis.__teamNflFailures = { [initialFailure]: !!initialFailure };
    const read = async (section, value, args) => {
        __teamNflCalls.push({ section, args });
        if (__teamNflFailures[section])
            throw Error('Fixture ' + section + ' unavailable');
        return value;
    };
    globalThis.__boardCleanupApi = {
        teams: {
            show: (...a) => read('team', { data: teamRow(a[1]) }, a),
            metrics: (...a) =>
                read('metrics', { data: empty ? null : metricRow() }, a),
            games: (...a) =>
                read('games', { data: empty ? [] : gameRows() }, a),
            players: (...a) =>
                read(
                    'roster',
                    {
                        data: empty
                            ? []
                            : [
                                  {
                                      id: 1,
                                      name: 'Stored Player',
                                      first_name: 'Stored',
                                      last_name: 'Player',
                                      position: 'G',
                                      jersey_number: '1',
                                  },
                              ],
                    },
                    a,
                ),
            statSeasonAverages: (...a) =>
                read(
                    'stats',
                    {
                        data: empty
                            ? null
                            : {
                                  points_per_game: 0,
                                  assists_per_game: 3,
                                  batting_average: 0.25,
                              },
                    },
                    a,
                ),
            trends: (...a) =>
                read(
                    'trends',
                    {
                        data: {
                            trends: empty
                                ? {}
                                : { scoring: ['Stored scoring trend'] },
                            locked_trends: {},
                        },
                    },
                    a,
                ),
            depthCharts: (...a) =>
                read(
                    'depth',
                    { data: empty ? null : { team: teamRow(), entries: [] } },
                    a,
                ),
        },
        metrics: {
            teams: (...a) =>
                read('ranks', { data: empty ? [] : [metricRow()] }, a),
        },
        stats: {
            teamSeasonAverages: (...a) =>
                read(
                    'statRanks',
                    {
                        data: empty
                            ? []
                            : [
                                  {
                                      team_id: 1,
                                      points_per_game: 0,
                                      season: 2026,
                                  },
                              ],
                    },
                    a,
                ),
        },
        predictions: {
            availableSeasons: (...a) =>
                read('seasons', { data: [2026, 2025] }, a),
            availableDates: (...a) =>
                read('dates', { data: ['2026-11-06', '2026-11-07'] }, a),
            index: (...a) =>
                read('board', { data: empty ? [] : boardRows() }, a),
        },
        signals: {
            index: (...a) =>
                read(
                    'signals',
                    {
                        data: {
                            season: 2026,
                            as_of_date: '2026-11-06',
                            week: 9,
                            super_bowl: [],
                            winners: [],
                            covers: [],
                            streaks: [],
                        },
                    },
                    a,
                ),
        },
    };
}
