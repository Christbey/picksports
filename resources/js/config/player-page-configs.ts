import MLBGameController from '@/actions/App/Http/Controllers/MLB/GameController';
import MLBTeamController from '@/actions/App/Http/Controllers/MLB/TeamController';
import NBAGameController from '@/actions/App/Http/Controllers/NBA/GameController';
import NBATeamController from '@/actions/App/Http/Controllers/NBA/TeamController';
import NFLGameController from '@/actions/App/Http/Controllers/NFL/GameController';
import NFLTeamController from '@/actions/App/Http/Controllers/NFL/TeamController';

export const nbaPlayerPageConfig = {
    sportLabel: 'NBA',
    predictionsHref: '/nba-predictions',
    teamLink: (id: number) => NBATeamController.url(id),
    gameLink: (id: number) => NBAGameController.url(id),
    playerEndpoint: (playerId: number) => `/api/v1/nba/players/${playerId}`,
    playerPropsEndpoint: (playerId: number) =>
        `/api/v1/nba/players/${playerId}/player-props`,
    statsEndpoint: (playerId: number) =>
        `/api/v1/nba/players/${playerId}/stats`,
    leaderboardEndpoint: '/api/v1/nba/player-stats/leaderboard',
};

export const cbbPlayerPageConfig = {
    sportLabel: 'CBB',
    predictionsHref: '/cbb-predictions',
    teamLink: (id: number) => `/cbb/teams/${id}`,
    gameLink: (id: number) => `/cbb/games/${id}`,
    playerEndpoint: (playerId: number) => `/api/v1/cbb/players/${playerId}`,
    playerPropsEndpoint: (playerId: number) =>
        `/api/v1/cbb/players/${playerId}/player-props`,
    statsEndpoint: (playerId: number) =>
        `/api/v1/cbb/players/${playerId}/stats`,
    leaderboardEndpoint: '/api/v1/cbb/player-stats/leaderboard',
};

export const nflPlayerPageConfig = {
    sportLabel: 'NFL',
    predictionsHref: '/nfl-predictions',
    teamLink: (id: number) => NFLTeamController.url(id),
    gameLink: (id: number) => NFLGameController.url(id),
    playerEndpoint: (playerId: number) => `/api/v1/nfl/players/${playerId}`,
    statsEndpoint: (playerId: number) =>
        `/api/v1/nfl/players/${playerId}/stats`,
    leaderboardEndpoint: '/api/v1/nfl/player-stats/leaderboard',
    summaryCards: [
        {
            label: 'YDS/G',
            value: (stats: any[]) =>
                (
                    stats.reduce((a, s) => a + Number(s.total_yards ?? 0), 0) /
                    Math.max(1, stats.length)
                ).toFixed(1),
            rankKey: 'points_per_game',
        },
        {
            label: 'Pass YDS/G',
            value: (stats: any[]) =>
                (
                    stats.reduce(
                        (a, s) => a + Number(s.passing_yards ?? 0),
                        0,
                    ) / Math.max(1, stats.length)
                ).toFixed(1),
            rankKey: 'passing_yards_per_game',
        },
        {
            label: 'Rush YDS/G',
            value: (stats: any[]) =>
                (
                    stats.reduce(
                        (a, s) => a + Number(s.rushing_yards ?? 0),
                        0,
                    ) / Math.max(1, stats.length)
                ).toFixed(1),
            rankKey: 'rushing_yards_per_game',
        },
        {
            label: 'Rec YDS/G',
            value: (stats: any[]) =>
                (
                    stats.reduce(
                        (a, s) => a + Number(s.receiving_yards ?? 0),
                        0,
                    ) / Math.max(1, stats.length)
                ).toFixed(1),
            rankKey: 'receiving_yards_per_game',
        },
        {
            label: 'TD/G',
            value: (stats: any[]) =>
                (
                    stats.reduce(
                        (a, s) => a + Number(s.total_touchdowns ?? 0),
                        0,
                    ) / Math.max(1, stats.length)
                ).toFixed(2),
            rankKey: 'rebounds_per_game',
        },
        {
            label: 'INT/G',
            value: (stats: any[]) =>
                (
                    stats.reduce(
                        (a, s) => a + Number(s.interceptions_thrown ?? 0),
                        0,
                    ) / Math.max(1, stats.length)
                ).toFixed(2),
            rankKey: 'interceptions_thrown_per_game',
        },
        {
            label: 'Tkl/G',
            value: (stats: any[]) =>
                (
                    stats.reduce(
                        (a, s) => a + Number(s.tackles_total ?? 0),
                        0,
                    ) / Math.max(1, stats.length)
                ).toFixed(1),
            rankKey: 'tackles_per_game',
        },
        {
            label: 'Sack/G',
            value: (stats: any[]) =>
                (
                    stats.reduce((a, s) => a + Number(s.sacks ?? 0), 0) /
                    Math.max(1, stats.length)
                ).toFixed(2),
            rankKey: 'sacks_per_game',
        },
    ],
    gameLogColumns: [
        {
            label: 'C/ATT',
            value: (s: any) =>
                `${Number(s.passing_completions ?? 0)}-${Number(s.passing_attempts ?? 0)}`,
        },
        { label: 'P YDS', value: (s: any) => Number(s.passing_yards ?? 0) },
        { label: 'P TD', value: (s: any) => Number(s.passing_touchdowns ?? 0) },
        {
            label: 'INT',
            value: (s: any) => Number(s.interceptions_thrown ?? 0),
        },
        { label: 'R ATT', value: (s: any) => Number(s.rushing_attempts ?? 0) },
        { label: 'R YDS', value: (s: any) => Number(s.rushing_yards ?? 0) },
        { label: 'R TD', value: (s: any) => Number(s.rushing_touchdowns ?? 0) },
        { label: 'REC', value: (s: any) => Number(s.receptions ?? 0) },
        { label: 'REC YDS', value: (s: any) => Number(s.receiving_yards ?? 0) },
        { label: 'TKL', value: (s: any) => Number(s.tackles_total ?? 0) },
        { label: 'SACK', value: (s: any) => Number(s.sacks ?? 0) },
        { label: 'PD', value: (s: any) => Number(s.passes_defended ?? 0) },
    ],
};

export const mlbPlayerPageConfig = {
    sportLabel: 'MLB',
    predictionsHref: '/mlb-predictions',
    teamLink: (id: number) => MLBTeamController.url(id),
    gameLink: (id: number) => MLBGameController.url(id),
    playerEndpoint: (playerId: number) => `/api/v1/mlb/players/${playerId}`,
    statsEndpoint: (playerId: number) =>
        `/api/v1/mlb/players/${playerId}/stats`,
    leaderboardEndpoint: '/api/v1/mlb/player-stats/leaderboard',
    summaryCards: [
        {
            label: 'H/G',
            value: (stats: any[]) =>
                (
                    stats.reduce((a, s) => a + Number(s.hits ?? 0), 0) /
                    Math.max(1, stats.length)
                ).toFixed(2),
            rankKey: 'points_per_game',
        },
        {
            label: 'HR/G',
            value: (stats: any[]) =>
                (
                    stats.reduce((a, s) => a + Number(s.home_runs ?? 0), 0) /
                    Math.max(1, stats.length)
                ).toFixed(2),
            rankKey: 'rebounds_per_game',
        },
        {
            label: 'RBI/G',
            value: (stats: any[]) =>
                (
                    stats.reduce((a, s) => a + Number(s.rbis ?? 0), 0) /
                    Math.max(1, stats.length)
                ).toFixed(2),
            rankKey: 'assists_per_game',
        },
        {
            label: 'SB/G',
            value: (stats: any[]) =>
                (
                    stats.reduce((a, s) => a + Number(s.stolen_bases ?? 0), 0) /
                    Math.max(1, stats.length)
                ).toFixed(2),
            rankKey: 'steals_per_game',
        },
        {
            label: 'AVG',
            value: (stats: any[]) => {
                const hits = stats.reduce((a, s) => a + Number(s.hits ?? 0), 0);
                const atBats = stats.reduce(
                    (a, s) => a + Number(s.at_bats ?? 0),
                    0,
                );
                return atBats > 0 ? (hits / atBats).toFixed(3) : '-';
            },
            rankKey: 'field_goal_percentage',
        },
        {
            label: 'OBP',
            value: (stats: any[]) => {
                const hits = stats.reduce((a, s) => a + Number(s.hits ?? 0), 0);
                const walks = stats.reduce(
                    (a, s) => a + Number(s.walks ?? 0),
                    0,
                );
                const atBats = stats.reduce(
                    (a, s) => a + Number(s.at_bats ?? 0),
                    0,
                );
                return atBats + walks > 0
                    ? ((hits + walks) / (atBats + walks)).toFixed(3)
                    : '-';
            },
            rankKey: 'three_point_percentage',
        },
        {
            label: 'SLG',
            value: (stats: any[]) => {
                const hits = stats.reduce((a, s) => a + Number(s.hits ?? 0), 0);
                const doubles = stats.reduce(
                    (a, s) => a + Number(s.doubles ?? 0),
                    0,
                );
                const triples = stats.reduce(
                    (a, s) => a + Number(s.triples ?? 0),
                    0,
                );
                const homeRuns = stats.reduce(
                    (a, s) => a + Number(s.home_runs ?? 0),
                    0,
                );
                const atBats = stats.reduce(
                    (a, s) => a + Number(s.at_bats ?? 0),
                    0,
                );
                const singles = Math.max(
                    0,
                    hits - doubles - triples - homeRuns,
                );
                return atBats > 0
                    ? (
                          (singles + 2 * doubles + 3 * triples + 4 * homeRuns) /
                          atBats
                      ).toFixed(3)
                    : '-';
            },
            rankKey: 'free_throw_percentage',
        },
    ],
    gameLogColumns: [
        { label: 'AB', value: (s: any) => Number(s.at_bats ?? 0) },
        { label: 'R', value: (s: any) => Number(s.runs ?? 0) },
        { label: 'H', value: (s: any) => Number(s.hits ?? 0) },
        { label: 'HR', value: (s: any) => Number(s.home_runs ?? 0) },
        { label: 'RBI', value: (s: any) => Number(s.rbis ?? 0) },
        { label: 'BB', value: (s: any) => Number(s.walks ?? 0) },
        { label: 'K', value: (s: any) => Number(s.strikeouts ?? 0) },
        { label: 'SB', value: (s: any) => Number(s.stolen_bases ?? 0) },
    ],
};

export const cfbPlayerPageConfig = {
    sportLabel: 'CFB',
    predictionsHref: '/cfb-predictions',
    playerEndpoint: (playerId: number) => `/api/v1/cfb/players/${playerId}`,
    statsEndpoint: (playerId: number) =>
        `/api/v1/cfb/players/${playerId}/stats`,
    leaderboardEndpoint: '/api/v1/cfb/player-stats/leaderboard',
    summaryCards: nflPlayerPageConfig.summaryCards,
    gameLogColumns: nflPlayerPageConfig.gameLogColumns,
};
