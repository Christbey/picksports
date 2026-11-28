import {
    averageObserved,
    aggregateObserved,
    displayObserved,
} from '@/lib/playerPagePresentation';
import MLBGameController from '@/actions/App/Http/Controllers/MLB/GameController';
import MLBTeamController from '@/actions/App/Http/Controllers/MLB/TeamController';
import NBAGameController from '@/actions/App/Http/Controllers/NBA/GameController';
import NBATeamController from '@/actions/App/Http/Controllers/NBA/TeamController';
import NFLGameController from '@/actions/App/Http/Controllers/NFL/GameController';
import NFLTeamController from '@/actions/App/Http/Controllers/NFL/TeamController';

const currentMlbSeason = new Date().getFullYear();

export const nbaPlayerPageConfig = {
    sport: 'nba',
    sportLabel: 'NBA',
    predictionsHref: '/nba/predictions',
    teamLink: (id: number) => NBATeamController.url(id),
    gameLink: (id: number) => NBAGameController.url(id),
    showPlayerProps: true,
};

export const cbbPlayerPageConfig = {
    sport: 'cbb',
    sportLabel: 'CBB',
    predictionsHref: '/cbb/predictions',
    teamLink: (id: number) => `/cbb/teams/${id}`,
    gameLink: (id: number) => `/cbb/games/${id}`,
    showPlayerProps: true,
};

export const nflPlayerPageConfig = {
    sport: 'nfl',
    sportLabel: 'NFL',
    predictionsHref: '/nfl/predictions',
    teamLink: (id: number) => NFLTeamController.url(id),
    gameLink: (id: number) => NFLGameController.url(id),
    summaryCards: [
        {
            label: 'YDS/G',
            value: (stats: any[]) => averageObserved(stats, 'total_yards', 1),
            rankKey: 'points_per_game',
        },
        {
            label: 'Pass YDS/G',
            value: (stats: any[]) => averageObserved(stats, 'passing_yards', 1),
            rankKey: 'passing_yards_per_game',
        },
        {
            label: 'Rush YDS/G',
            value: (stats: any[]) => averageObserved(stats, 'rushing_yards', 1),
            rankKey: 'rushing_yards_per_game',
        },
        {
            label: 'Rec YDS/G',
            value: (stats: any[]) =>
                averageObserved(stats, 'receiving_yards', 1),
            rankKey: 'receiving_yards_per_game',
        },
        {
            label: 'TD/G',
            value: (stats: any[]) =>
                averageObserved(stats, 'total_touchdowns', 2),
            rankKey: 'rebounds_per_game',
        },
        {
            label: 'INT/G',
            value: (stats: any[]) =>
                averageObserved(stats, 'interceptions_thrown', 2),
            rankKey: 'interceptions_thrown_per_game',
        },
        {
            label: 'Tkl/G',
            value: (stats: any[]) => averageObserved(stats, 'tackles_total', 1),
            rankKey: 'tackles_per_game',
        },
        {
            label: 'Sack/G',
            value: (stats: any[]) => averageObserved(stats, 'sacks', 2),
            rankKey: 'sacks_per_game',
        },
    ],
    gameLogColumns: [
        {
            label: 'C/ATT',
            value: (s: any) =>
                s.passing_completions == null || s.passing_attempts == null
                    ? '—'
                    : `${displayObserved(s.passing_completions)}-${displayObserved(s.passing_attempts)}`,
        },
        { label: 'P YDS', value: (s: any) => displayObserved(s.passing_yards) },
        {
            label: 'P TD',
            value: (s: any) => displayObserved(s.passing_touchdowns),
        },
        {
            label: 'INT',
            value: (s: any) => displayObserved(s.interceptions_thrown),
        },
        {
            label: 'R ATT',
            value: (s: any) => displayObserved(s.rushing_attempts),
        },
        { label: 'R YDS', value: (s: any) => displayObserved(s.rushing_yards) },
        {
            label: 'R TD',
            value: (s: any) => displayObserved(s.rushing_touchdowns),
        },
        { label: 'REC', value: (s: any) => displayObserved(s.receptions) },
        {
            label: 'REC YDS',
            value: (s: any) => displayObserved(s.receiving_yards),
        },
        { label: 'TKL', value: (s: any) => displayObserved(s.tackles_total) },
        { label: 'SACK', value: (s: any) => displayObserved(s.sacks) },
        { label: 'PD', value: (s: any) => displayObserved(s.passes_defended) },
    ],
};

export const mlbPlayerPageConfig = {
    sport: 'mlb',
    sportLabel: 'MLB',
    predictionsHref: '/mlb/predictions',
    teamLink: (id: number) => MLBTeamController.url(id),
    gameLink: (id: number) => MLBGameController.url(id),
    statsQuery: {
        season: currentMlbSeason,
        season_type: 2,
        stat_type: 'batting',
    },
    leaderboardQuery: {
        season: currentMlbSeason,
        season_type: 2,
        stat_type: 'batting',
    },
    summaryCards: [
        {
            label: 'H/G',
            value: (stats: any[]) => averageObserved(stats, 'hits', 2),
            rankKey: 'points_per_game',
        },
        {
            label: 'HR/G',
            value: (stats: any[]) => averageObserved(stats, 'home_runs', 2),
            rankKey: 'rebounds_per_game',
        },
        {
            label: 'RBI/G',
            value: (stats: any[]) => averageObserved(stats, 'rbis', 2),
            rankKey: 'assists_per_game',
        },
        {
            label: 'SB/G',
            value: (stats: any[]) => averageObserved(stats, 'stolen_bases', 2),
            rankKey: 'steals_per_game',
        },
        {
            label: 'AVG',
            value: (stats: any[]) =>
                aggregateObserved(
                    stats,
                    ['hits', 'at_bats'],
                    ([hits, ab]) => (ab > 0 ? hits / ab : null),
                    3,
                ),
            rankKey: 'field_goal_percentage',
        },
        {
            label: '(H+BB)/(AB+BB)',
            value: (stats: any[]) =>
                aggregateObserved(
                    stats,
                    ['hits', 'walks', 'at_bats'],
                    ([hits, walks, ab]) =>
                        ab + walks > 0 ? (hits + walks) / (ab + walks) : null,
                    3,
                ),
            rankKey: 'three_point_percentage',
        },
        {
            label: 'SLG',
            value: (stats: any[]) =>
                aggregateObserved(
                    stats,
                    ['hits', 'doubles', 'triples', 'home_runs', 'at_bats'],
                    ([hits, doubles, triples, hr, ab]) =>
                        ab > 0
                            ? (Math.max(0, hits - doubles - triples - hr) +
                                  2 * doubles +
                                  3 * triples +
                                  4 * hr) /
                              ab
                            : null,
                    3,
                ),
            rankKey: 'free_throw_percentage',
        },
    ],
    gameLogColumns: [
        { label: 'AB', value: (s: any) => displayObserved(s.at_bats) },
        { label: 'R', value: (s: any) => displayObserved(s.runs) },
        { label: 'H', value: (s: any) => displayObserved(s.hits) },
        { label: 'HR', value: (s: any) => displayObserved(s.home_runs) },
        { label: 'RBI', value: (s: any) => displayObserved(s.rbis) },
        { label: 'BB', value: (s: any) => displayObserved(s.walks) },
        { label: 'K', value: (s: any) => displayObserved(s.strikeouts) },
        { label: 'SB', value: (s: any) => displayObserved(s.stolen_bases) },
    ],
};

export const cfbPlayerPageConfig = {
    sport: 'cfb',
    sportLabel: 'CFB',
    predictionsHref: '/cfb/predictions',
    summaryCards: nflPlayerPageConfig.summaryCards,
    gameLogColumns: nflPlayerPageConfig.gameLogColumns,
};
