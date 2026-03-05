import CBBTeamController from '@/actions/App/Http/Controllers/CBB/TeamController';
import MLBTeamController from '@/actions/App/Http/Controllers/MLB/TeamController';
import NBAPlayerController from '@/actions/App/Http/Controllers/NBA/PlayerController';
import NFLTeamController from '@/actions/App/Http/Controllers/NFL/TeamController';

const passingPositions = new Set(['QB']);
const rushingPositions = new Set(['RB', 'FB', 'HB', 'WR', 'TE', 'QB']);
const defensePositions = new Set([
    'DE',
    'DT',
    'NT',
    'DL',
    'EDGE',
    'LB',
    'ILB',
    'OLB',
    'MLB',
    'CB',
    'S',
    'FS',
    'SS',
    'DB',
]);
const specialTeamsPositions = new Set(['K', 'PK', 'P', 'KR', 'PR', 'LS']);

const playerPosition = (entry: {
    player?: { position?: string | null } | null;
}) => (entry.player?.position ?? '').toUpperCase();

const isPassingEntry = (entry: Record<string, any>) =>
    passingPositions.has(playerPosition(entry)) ||
    Number(entry.passing_yards_per_game ?? entry.steals_per_game ?? 0) > 0;

const isRushingEntry = (entry: Record<string, any>) =>
    rushingPositions.has(playerPosition(entry)) ||
    Number(entry.rushing_yards_per_game ?? entry.blocks_per_game ?? 0) > 0;

const isDefenseEntry = (entry: Record<string, any>) =>
    defensePositions.has(playerPosition(entry)) ||
    Number(entry.tackles_per_game ?? 0) > 0 ||
    Number(entry.sacks_per_game ?? 0) > 0 ||
    Number(entry.def_interceptions_per_game ?? 0) > 0 ||
    Number(entry.passes_defended_per_game ?? 0) > 0;

const isSpecialTeamsEntry = (entry: Record<string, any>) =>
    specialTeamsPositions.has(playerPosition(entry)) ||
    Number(entry.field_goals_made_per_game ?? 0) > 0 ||
    Number(entry.extra_points_made_per_game ?? 0) > 0;

export const nbaPlayerStatsPageConfig = {
    pageTitle: 'NBA Player Stats',
    heading: 'NBA Player Stats',
    description: 'Season averages leaderboard for NBA players',
    breadcrumb: { title: 'NBA Player Stats', href: '/nba-player-stats' },
    bannerStorageKey: 'nba-player-stats-banner-dismissed',
    leaderboardEndpoint: '/api/v1/nba/player-stats/leaderboard',
    showEpaColumns: true,
    playerLink: (id: number) => NBAPlayerController(id),
};

export const cbbPlayerStatsPageConfig = {
    pageTitle: 'CBB Player Stats',
    heading: 'CBB Player Stats',
    description: 'Season averages leaderboard for college basketball players',
    breadcrumb: { title: 'CBB Player Stats', href: '/cbb-player-stats' },
    bannerStorageKey: 'cbb-player-stats-banner-dismissed',
    leaderboardEndpoint: '/api/v1/cbb/player-stats/leaderboard',
    showEpaColumns: true,
    playerLink: (id: number) => `/cbb/players/${id}`,
    teamLink: (id: number) => CBBTeamController(id),
};

export const nflPlayerStatsPageConfig = {
    pageTitle: 'NFL Player Stats',
    heading: 'NFL Player Stats',
    description: 'Season leaderboard for NFL player production',
    breadcrumb: { title: 'NFL Player Stats', href: '/nfl-player-stats' },
    bannerStorageKey: 'nfl-player-stats-banner-dismissed',
    leaderboardEndpoint: '/api/v1/nfl/player-stats/leaderboard',
    showEpaColumns: false,
    sortOptions: [
        { key: 'points_per_game', label: 'Yds/G' },
        { key: 'rebounds_per_game', label: 'TD/G' },
        { key: 'assists_per_game', label: 'Rec/G' },
    ],
    statColumns: [
        {
            key: 'points_per_game',
            label: 'Yds/G',
            cellClass: 'p-2 text-right font-medium',
        },
        {
            key: 'rebounds_per_game',
            label: 'TD/G',
            cellClass: 'p-2 text-right',
        },
        {
            key: 'assists_per_game',
            label: 'Rec/G',
            cellClass: 'p-2 text-right',
        },
        {
            key: 'steals_per_game',
            label: 'Pass Yds/G',
            cellClass: 'hidden p-2 text-right md:table-cell',
        },
        {
            key: 'blocks_per_game',
            label: 'Rush Yds/G',
            cellClass: 'hidden p-2 text-right md:table-cell',
        },
        {
            key: 'field_goal_percentage',
            label: 'Comp%',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (value: number | undefined) =>
                `${Number(value ?? 0).toFixed(1)}%`,
        },
        {
            key: 'three_point_percentage',
            label: 'Catch%',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (value: number | undefined) =>
                `${Number(value ?? 0).toFixed(1)}%`,
        },
        {
            key: 'free_throw_percentage',
            label: 'Yds/Att',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (value: number | undefined) =>
                Number(value ?? 0).toFixed(1),
        },
        {
            key: 'minutes_per_game',
            label: 'Touch/G',
            cellClass:
                'hidden p-2 text-right text-muted-foreground lg:table-cell',
        },
    ],
    statCategoryOptions: [
        {
            key: 'passing',
            label: 'Passing',
            defaultSortBy: 'passing_yards_per_game',
            sortOptions: [
                { key: 'passing_yards_per_game', label: 'Pass Yds/G' },
                { key: 'passing_yards_total', label: 'Pass Yds' },
                { key: 'passing_touchdowns_per_game', label: 'Pass TD/G' },
                { key: 'passing_touchdowns_total', label: 'Pass TD' },
                { key: 'qbr', label: 'QBR' },
            ],
            statColumns: [
                {
                    key: 'passing_yards_per_game',
                    label: 'Pass Yds/G',
                    cellClass: 'p-2 text-right font-medium',
                },
                {
                    key: 'passing_yards_total',
                    label: 'Pass Yds',
                    cellClass: 'p-2 text-right',
                },
                {
                    key: 'passing_touchdowns_per_game',
                    label: 'Pass TD/G',
                    cellClass: 'p-2 text-right',
                },
                {
                    key: 'passing_touchdowns_total',
                    label: 'Pass TD',
                    cellClass: 'p-2 text-right',
                },
                {
                    key: 'qbr',
                    label: 'QBR',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(1),
                },
                {
                    key: 'completion_percentage',
                    label: 'Comp%',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(1)}%`,
                },
                {
                    key: 'interceptions_thrown_total',
                    label: 'INT',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
            ],
            match: isPassingEntry,
        },
        {
            key: 'rushing',
            label: 'Rushing',
            defaultSortBy: 'rushing_yards_per_game',
            sortOptions: [
                { key: 'rushing_yards_per_game', label: 'Rush Yds/G' },
                { key: 'rushing_yards_total', label: 'Rush Yds' },
                { key: 'rushing_touchdowns_per_game', label: 'Rush TD/G' },
                { key: 'rushing_touchdowns_total', label: 'Rush TD' },
                { key: 'yards_per_carry', label: 'Yds/Carry' },
            ],
            statColumns: [
                {
                    key: 'rushing_yards_per_game',
                    label: 'Rush Yds/G',
                    cellClass: 'p-2 text-right font-medium',
                },
                {
                    key: 'rushing_yards_total',
                    label: 'Rush Yds',
                    cellClass: 'p-2 text-right',
                },
                {
                    key: 'rushing_touchdowns_per_game',
                    label: 'Rush TD/G',
                    cellClass: 'p-2 text-right',
                },
                {
                    key: 'rushing_touchdowns_total',
                    label: 'Rush TD',
                    cellClass: 'p-2 text-right',
                },
                {
                    key: 'yards_per_carry',
                    label: 'Yds/Carry',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(1),
                },
                {
                    key: 'rushing_attempts_total',
                    label: 'Att',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'receiving_yards_total',
                    label: 'Rec Yds',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
            ],
            match: isRushingEntry,
        },
        {
            key: 'defense',
            label: 'Defense',
            defaultSortBy: 'tackles_per_game',
            sortOptions: [
                { key: 'tackles_per_game', label: 'Tkl/G' },
                { key: 'tackles_total', label: 'Tkl' },
                { key: 'sacks_per_game', label: 'Sack/G' },
                { key: 'sacks_total', label: 'Sacks' },
                { key: 'def_interceptions_total', label: 'INT' },
            ],
            statColumns: [
                {
                    key: 'tackles_per_game',
                    label: 'Tkl/G',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(1),
                },
                {
                    key: 'tackles_total',
                    label: 'Tkl',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'sacks_per_game',
                    label: 'Sack/G',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'sacks_total',
                    label: 'Sacks',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(1),
                },
                {
                    key: 'def_interceptions_total',
                    label: 'INT',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'passes_defended_total',
                    label: 'PD',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
            ],
            match: isDefenseEntry,
        },
        {
            key: 'special_teams',
            label: 'Special Teams',
            defaultSortBy: 'field_goals_made_per_game',
            sortOptions: [
                { key: 'field_goals_made_per_game', label: 'FGM/G' },
                { key: 'field_goals_made_total', label: 'FGM' },
                { key: 'extra_points_made_per_game', label: 'XPM/G' },
                { key: 'extra_points_made_total', label: 'XPM' },
                { key: 'field_goal_percentage_special', label: 'FG%' },
            ],
            statColumns: [
                {
                    key: 'field_goals_made_per_game',
                    label: 'FGM/G',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'field_goals_made_total',
                    label: 'FGM',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'extra_points_made_per_game',
                    label: 'XPM/G',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'extra_points_made_total',
                    label: 'XPM',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'field_goal_percentage_special',
                    label: 'FG%',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(1)}%`,
                },
                {
                    key: 'extra_point_percentage',
                    label: 'XP%',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(1)}%`,
                },
                {
                    key: 'field_goals_attempted_total',
                    label: 'FGA',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
            ],
            match: isSpecialTeamsEntry,
        },
    ],
    playerLink: (id: number) => `/nfl/players/${id}`,
    teamLink: (id: number) => NFLTeamController(id),
};

export const mlbPlayerStatsPageConfig = {
    pageTitle: 'MLB Player Stats',
    heading: 'MLB Player Stats',
    description: 'Season leaderboard for MLB hitters',
    breadcrumb: { title: 'MLB Player Stats', href: '/mlb-player-stats' },
    bannerStorageKey: 'mlb-player-stats-banner-dismissed',
    leaderboardEndpoint: '/api/v1/mlb/player-stats/leaderboard',
    showEpaColumns: false,
    sortOptions: [
        { key: 'points_per_game', label: 'H/G' },
        { key: 'rebounds_per_game', label: 'HR/G' },
        { key: 'assists_per_game', label: 'RBI/G' },
    ],
    statColumns: [
        {
            key: 'points_per_game',
            label: 'H/G',
            cellClass: 'p-2 text-right font-medium',
            format: (v: number | undefined) => Number(v ?? 0).toFixed(2),
        },
        {
            key: 'rebounds_per_game',
            label: 'HR/G',
            cellClass: 'p-2 text-right',
            format: (v: number | undefined) => Number(v ?? 0).toFixed(2),
        },
        {
            key: 'assists_per_game',
            label: 'RBI/G',
            cellClass: 'p-2 text-right',
            format: (v: number | undefined) => Number(v ?? 0).toFixed(2),
        },
        {
            key: 'steals_per_game',
            label: 'SB/G',
            cellClass: 'hidden p-2 text-right md:table-cell',
            format: (v: number | undefined) => Number(v ?? 0).toFixed(2),
        },
        {
            key: 'blocks_per_game',
            label: 'K/G',
            cellClass: 'hidden p-2 text-right md:table-cell',
            format: (v: number | undefined) => Number(v ?? 0).toFixed(2),
        },
        {
            key: 'field_goal_percentage',
            label: 'AVG',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (v: number | undefined) => Number(v ?? 0).toFixed(3),
        },
        {
            key: 'three_point_percentage',
            label: 'OBP',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (v: number | undefined) => Number(v ?? 0).toFixed(3),
        },
        {
            key: 'free_throw_percentage',
            label: 'SLG',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (v: number | undefined) => Number(v ?? 0).toFixed(3),
        },
        {
            key: 'minutes_per_game',
            label: 'AB/G',
            cellClass:
                'hidden p-2 text-right text-muted-foreground lg:table-cell',
            format: (v: number | undefined) => Number(v ?? 0).toFixed(1),
        },
    ],
    playerLink: (id: number) => `/mlb/players/${id}`,
    teamLink: (id: number) => MLBTeamController(id),
};

export const cfbPlayerStatsPageConfig = {
    pageTitle: 'CFB Player Stats',
    heading: 'CFB Player Stats',
    description: 'Season leaderboard for college football player production',
    breadcrumb: { title: 'CFB Player Stats', href: '/cfb-player-stats' },
    bannerStorageKey: 'cfb-player-stats-banner-dismissed',
    leaderboardEndpoint: '/api/v1/cfb/player-stats/leaderboard',
    showEpaColumns: false,
    sortOptions: [
        { key: 'points_per_game', label: 'Yds/G' },
        { key: 'rebounds_per_game', label: 'TD/G' },
        { key: 'assists_per_game', label: 'Rec/G' },
    ],
    statColumns: [
        {
            key: 'points_per_game',
            label: 'Yds/G',
            cellClass: 'p-2 text-right font-medium',
        },
        {
            key: 'rebounds_per_game',
            label: 'TD/G',
            cellClass: 'p-2 text-right',
        },
        {
            key: 'assists_per_game',
            label: 'Rec/G',
            cellClass: 'p-2 text-right',
        },
        {
            key: 'steals_per_game',
            label: 'Pass Yds/G',
            cellClass: 'hidden p-2 text-right md:table-cell',
        },
        {
            key: 'blocks_per_game',
            label: 'Rush Yds/G',
            cellClass: 'hidden p-2 text-right md:table-cell',
        },
        {
            key: 'field_goal_percentage',
            label: 'Comp%',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (v: number | undefined) => `${Number(v ?? 0).toFixed(1)}%`,
        },
        {
            key: 'three_point_percentage',
            label: 'Catch%',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (v: number | undefined) => `${Number(v ?? 0).toFixed(1)}%`,
        },
        {
            key: 'free_throw_percentage',
            label: 'Yds/Att',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (v: number | undefined) => Number(v ?? 0).toFixed(1),
        },
        {
            key: 'minutes_per_game',
            label: 'Touch/G',
            cellClass:
                'hidden p-2 text-right text-muted-foreground lg:table-cell',
        },
    ],
    statCategoryOptions: [
        {
            key: 'passing',
            label: 'Passing',
            defaultSortBy: 'passing_yards_per_game',
            sortOptions: [
                { key: 'passing_yards_per_game', label: 'Pass Yds/G' },
                { key: 'passing_touchdowns_per_game', label: 'Pass TD/G' },
                { key: 'completion_percentage', label: 'Comp%' },
            ],
            statColumns: [
                {
                    key: 'passing_yards_per_game',
                    label: 'Pass Yds/G',
                    cellClass: 'p-2 text-right font-medium',
                },
                {
                    key: 'passing_touchdowns_per_game',
                    label: 'Pass TD/G',
                    cellClass: 'p-2 text-right',
                },
                {
                    key: 'completion_percentage',
                    label: 'Comp%',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(1)}%`,
                },
                {
                    key: 'interceptions_thrown_per_game',
                    label: 'INT/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'points_per_game',
                    label: 'Tot Yds/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(1),
                },
            ],
            match: isPassingEntry,
        },
        {
            key: 'rushing',
            label: 'Rushing',
            defaultSortBy: 'rushing_yards_per_game',
            sortOptions: [
                { key: 'rushing_yards_per_game', label: 'Rush Yds/G' },
                { key: 'rushing_touchdowns_per_game', label: 'Rush TD/G' },
                { key: 'yards_per_carry', label: 'Yds/Carry' },
            ],
            statColumns: [
                {
                    key: 'rushing_yards_per_game',
                    label: 'Rush Yds/G',
                    cellClass: 'p-2 text-right font-medium',
                },
                {
                    key: 'rushing_touchdowns_per_game',
                    label: 'Rush TD/G',
                    cellClass: 'p-2 text-right',
                },
                {
                    key: 'yards_per_carry',
                    label: 'Yds/Carry',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(1),
                },
                {
                    key: 'receptions_per_game',
                    label: 'Rec/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'receiving_yards_per_game',
                    label: 'Rec Yds/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(1),
                },
            ],
            match: isRushingEntry,
        },
        {
            key: 'defense',
            label: 'Defense',
            defaultSortBy: 'tackles_per_game',
            sortOptions: [
                { key: 'tackles_per_game', label: 'Tkl/G' },
                { key: 'sacks_per_game', label: 'Sack/G' },
                { key: 'def_interceptions_per_game', label: 'INT/G' },
            ],
            statColumns: [
                {
                    key: 'tackles_per_game',
                    label: 'Tkl/G',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(1),
                },
                {
                    key: 'sacks_per_game',
                    label: 'Sack/G',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'def_interceptions_per_game',
                    label: 'INT/G',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'passes_defended_per_game',
                    label: 'PD/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'fumbles_recovered_per_game',
                    label: 'FR/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
            ],
            match: isDefenseEntry,
        },
        {
            key: 'special_teams',
            label: 'Special Teams',
            defaultSortBy: 'field_goals_made_per_game',
            sortOptions: [
                { key: 'field_goals_made_per_game', label: 'FGM/G' },
                { key: 'extra_points_made_per_game', label: 'XPM/G' },
                { key: 'field_goal_percentage_special', label: 'FG%' },
            ],
            statColumns: [
                {
                    key: 'field_goals_made_per_game',
                    label: 'FGM/G',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'extra_points_made_per_game',
                    label: 'XPM/G',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'field_goal_percentage_special',
                    label: 'FG%',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(1)}%`,
                },
                {
                    key: 'extra_point_percentage',
                    label: 'XP%',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(1)}%`,
                },
            ],
            match: isSpecialTeamsEntry,
        },
    ],
    playerLink: (id: number) => `/cfb/players/${id}`,
    teamLink: undefined,
};
