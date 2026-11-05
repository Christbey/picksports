export function playerUi(sport) {
    const nfl = sport === 'nfl';
    const player = {
        id: 7,
        team_id: 1,
        full_name: 'Test Player',
        jersey_number: '7',
        position: nfl ? 'QB' : 'G',
        height: '6-2',
        weight: '200',
        headshot_url: null,
        team: {
            id: 1,
            name: 'Home',
            display_name: 'Home Team',
            abbreviation: 'HOM',
        },
        active_injuries_count: 1,
        active_injuries: [
            {
                id: 1,
                status: 'Questionable',
                detail: 'Fixture injury',
                type: 'ankle',
                return_date: null,
                source_updated_at: '2026-11-04T10:00:00Z',
            },
        ],
    };
    const logs = [12, 0].map((points, index) => ({
        id: index + 1,
        stats: {
            points,
            rebounds: 5,
            assists: 3,
            field_goals_made: index ? 0 : 2,
            field_goals_attempted: index ? 0 : 4,
            passing_yards: index ? 0 : 200,
        },
        game: {
            id: index + 11,
            game_date: `2026-11-0${3 - index}`,
            home_team_id: 1,
            away_team_id: 2,
            home_team: { abbreviation: 'HOM' },
            away_team: { abbreviation: 'AWY' },
        },
    }));
    const rows = Array.from({ length: 110 }, (_, index) => ({
        player_id: index + 1,
        player: {
            id: index + 1,
            full_name: `Player ${index}`,
            headshot_url: null,
            position: nfl ? (index < 105 ? 'QB' : 'RB') : 'G',
            jersey_number: '7',
            team: {
                id: 1,
                name: 'Home',
                display_name: 'Home Team',
                abbreviation: 'HOM',
            },
        },
        games_played: 3,
        points_per_game: index % 30,
        rebounds_per_game: 5,
        assists_per_game: 3,
        passing_yards_per_game: index,
        rushing_yards_per_game: 110 - index,
    }));
    const calls = [];
    const api = {
        players: {
            show: async () => ({ data: player }),
            playerProps: async () => ({
                data: [
                    {
                        id: 1,
                        market: nfl ? 'Passing Yards' : 'Points',
                        line: 20.5,
                        over_price: 110,
                        under_price: -120,
                        game: {
                            id: 11,
                            home_team: { abbreviation: 'HOM' },
                            away_team: { abbreviation: 'AWY' },
                        },
                    },
                ],
            }),
        },
        stats: { players: async () => ({ data: logs }) },
        leaderboards: {
            playerAvailableSeasons: async () => ({ data: [2026, 2025] }),
            players: async (_, options) => {
                calls.push({ query: options.query });
                return {
                    data: rows,
                    meta: {
                        focus_ranks: {
                            points_per_game: { rank: 2, total: 110 },
                            passing_yards_per_game: { rank: 2, total: 105 },
                        },
                    },
                };
            },
        },
    };
    const playerConfig = {
        sport,
        sportLabel: sport.toUpperCase(),
        predictionsHref: `/${sport}/predictions`,
        showPlayerProps: true,
        teamLink: (id) => `/${sport}/teams/${id}`,
        gameLink: (id) => `/${sport}/games/${id}`,
    };
    if (nfl) {
        playerConfig.summaryCards = [
            {
                label: 'Pass Yds',
                value: (stats) =>
                    (
                        stats.reduce(
                            (sum, row) => sum + Number(row.passing_yards ?? 0),
                            0,
                        ) / stats.length
                    ).toFixed(1),
                rankKey: 'passing_yards_per_game',
            },
        ];
        playerConfig.gameLogColumns = [
            { label: 'YDS', value: (row) => Number(row.passing_yards ?? 0) },
        ];
    }
    const config = {
        sport,
        pageTitle: 'Player stats',
        heading: `${sport.toUpperCase()} Player Stats`,
        description: 'Player statistics and rankings',
        breadcrumb: { title: 'Stats', href: `/${sport}/player-stats` },
        bannerStorageKey: 'player-test-banner',
        playerLink: (id) => `/${sport}/players/${id}`,
        teamLink: (id) => `/${sport}/teams/${id}`,
    };
    if (nfl) {
        config.minGames = 1;
        config.defaultSeasonType = '2';
        config.seasonTypeOptions = [
            { value: '2', label: 'Regular Season' },
            { value: '3', label: 'Postseason' },
        ];
        config.statCategoryOptions = [
            {
                key: 'passing',
                label: 'Passing',
                minGames: 2,
                match: (row) => row.player?.position === 'QB',
                defaultSortBy: 'passing_yards_per_game',
                sortOptions: [
                    { key: 'passing_yards_per_game', label: 'Passing yards' },
                ],
                statColumns: [
                    { key: 'passing_yards_per_game', label: 'PASS YDS' },
                ],
            },
            {
                key: 'rushing',
                label: 'Rushing',
                minGames: 1,
                match: (row) => row.player?.position === 'RB',
                defaultSortBy: 'rushing_yards_per_game',
                sortOptions: [
                    { key: 'rushing_yards_per_game', label: 'Rushing yards' },
                ],
                statColumns: [
                    { key: 'rushing_yards_per_game', label: 'RUSH YDS' },
                ],
            },
        ];
    }
    return {
        api,
        calls,
        playerProps: { playerId: 7, config: playerConfig },
        statsProps: { config },
    };
}
