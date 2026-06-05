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
    sport: 'nba',
    pageTitle: 'NBA Player Stats',
    heading: 'NBA Player Stats',
    description: 'Season averages leaderboard for NBA players',
    breadcrumb: { title: 'NBA Player Stats', href: '/nba/player-stats' },
    bannerStorageKey: 'nba-player-stats-banner-dismissed',
    seasonTypeOptions: [
        { value: '1', label: 'Preseason' },
        { value: '2', label: 'Regular Season' },
        { value: '3', label: 'Postseason' },
        { value: '4', label: 'All-Star' },
    ],
    showEpaColumns: true,
    playerLink: (id: number) => NBAPlayerController(id),
};

export const cbbPlayerStatsPageConfig = {
    sport: 'cbb',
    pageTitle: 'CBB Player Stats',
    heading: 'CBB Player Stats',
    description: 'Season averages leaderboard for college basketball players',
    breadcrumb: { title: 'CBB Player Stats', href: '/cbb/player-stats' },
    bannerStorageKey: 'cbb-player-stats-banner-dismissed',
    seasonTypeOptions: [
        { value: '1', label: 'Preseason' },
        { value: '2', label: 'Regular Season' },
        { value: '3', label: 'Postseason' },
    ],
    showEpaColumns: true,
    playerLink: (id: number) => `/cbb/players/${id}`,
    teamLink: (id: number) => CBBTeamController(id),
};

export const nflPlayerStatsPageConfig = {
    sport: 'nfl',
    pageTitle: 'NFL Player Stats',
    heading: 'NFL Player Stats',
    description: 'Season leaderboard for NFL player production',
    breadcrumb: { title: 'NFL Player Stats', href: '/nfl/player-stats' },
    bannerStorageKey: 'nfl-player-stats-banner-dismissed',
    seasonTypeOptions: [
        { value: '1', label: 'Preseason' },
        { value: '2', label: 'Regular Season' },
        { value: '3', label: 'Postseason' },
    ],
    showEpaColumns: false,
    sortOptions: [
        { key: 'estimated_epa_per_game', label: 'EPA/G' },
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
        {
            key: 'estimated_epa_per_game',
            label: 'EPA/G',
            cellClass: 'p-2 text-right font-medium',
            format: (value: number | undefined) =>
                Number(value ?? 0).toFixed(2),
        },
        {
            key: 'estimated_epa_per_opportunity',
            label: 'EPA/Opp',
            cellClass: 'hidden p-2 text-right xl:table-cell',
            format: (value: number | undefined) =>
                Number(value ?? 0).toFixed(3),
        },
    ],
    statCategoryOptions: [
        {
            key: 'impact',
            label: 'Impact',
            defaultSortBy: 'estimated_epa_per_game',
            sortOptions: [
                { key: 'estimated_epa_per_game', label: 'EPA/G' },
                { key: 'estimated_epa_total', label: 'EPA Total' },
                { key: 'estimated_epa_per_opportunity', label: 'EPA/Opp' },
                { key: 'points_per_game', label: 'Yds/G' },
                { key: 'rebounds_per_game', label: 'TD/G' },
            ],
            statColumns: [
                {
                    key: 'estimated_epa_per_game',
                    label: 'EPA/G',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'estimated_epa_total',
                    label: 'EPA Total',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'estimated_epa_per_opportunity',
                    label: 'EPA/Opp',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(3),
                },
                {
                    key: 'points_per_game',
                    label: 'Yds/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                },
                {
                    key: 'rebounds_per_game',
                    label: 'TD/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                },
                {
                    key: 'assists_per_game',
                    label: 'Rec/G',
                    cellClass: 'hidden p-2 text-right lg:table-cell',
                },
            ],
            match: () => true,
        },
        {
            key: 'passing',
            label: 'Passing',
            defaultSortBy: 'passing_yards_total',
            sortOptions: [
                { key: 'passing_completions_total', label: 'Comp' },
                { key: 'passing_attempts_total', label: 'Att' },
                { key: 'passing_yards_total', label: 'Pass Yds' },
                { key: 'passing_touchdowns_total', label: 'Pass TD' },
                { key: 'passing_touchdown_percentage', label: 'TD%' },
                { key: 'interceptions_thrown_total', label: 'INT' },
                { key: 'interception_percentage', label: 'INT%' },
                { key: 'games_with_interception', label: 'G w/ INT' },
                { key: 'completion_percentage', label: 'Comp%' },
                { key: 'yards_per_pass_thrown', label: 'Yds/Att' },
                { key: 'passing_long_total', label: 'Long' },
                { key: 'sacks_taken_total', label: 'Sacked' },
                { key: 'sack_yards_lost_total', label: 'Sack Yds Lost' },
                { key: 'passing_yards_net_total', label: 'Net Pass Yds' },
                { key: 'net_yards_per_passing_play', label: 'Net Yds/Play' },
                { key: 'qb_rating', label: 'QB Rating' },
                {
                    key: 'passing_two_point_conversions_total',
                    label: '2PT Pass',
                },
                { key: 'passing_rushing_yards_total', label: 'Pass+Rush Yds' },
            ],
            statColumns: [
                {
                    key: 'passing_completions_total',
                    label: 'Comp',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'passing_attempts_total',
                    label: 'Att',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'passing_yards_total',
                    label: 'Pass Yds',
                    cellClass: 'p-2 text-right',
                },
                {
                    key: 'passing_touchdowns_total',
                    label: 'Pass TD',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'passing_touchdown_percentage',
                    label: 'TD%',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(2)}%`,
                },
                {
                    key: 'interceptions_thrown_total',
                    label: 'INT',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'interception_percentage',
                    label: 'INT%',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(2)}%`,
                },
                {
                    key: 'games_with_interception',
                    label: 'G w/ INT',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'games_with_interception_percentage',
                    label: 'G w/ INT%',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(1)}%`,
                },
                {
                    key: 'completion_percentage',
                    label: 'Comp%',
                    cellClass: 'hidden p-2 text-right lg:table-cell',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(1)}%`,
                },
                {
                    key: 'yards_per_pass_thrown',
                    label: 'Yds/Att',
                    cellClass: 'hidden p-2 text-right lg:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'passing_long_total',
                    label: 'Long',
                    cellClass: 'hidden p-2 text-right xl:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'sacks_taken_total',
                    label: 'Sacked',
                    cellClass: 'hidden p-2 text-right xl:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'sack_yards_lost_total',
                    label: 'Sack Yds Lost',
                    cellClass: 'hidden p-2 text-right xl:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'passing_yards_net_total',
                    label: 'Net Pass Yds',
                    cellClass: 'hidden p-2 text-right 2xl:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'net_yards_per_passing_play',
                    label: 'Net Yds/Play',
                    cellClass: 'hidden p-2 text-right 2xl:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'qb_rating',
                    label: 'QB Rating',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        v == null ? '-' : Number(v).toFixed(1),
                },
                {
                    key: 'passing_two_point_conversions_total',
                    label: '2PT Pass',
                    cellClass: 'hidden p-2 text-right 2xl:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'passing_rushing_yards_total',
                    label: 'Pass+Rush Yds',
                    cellClass: 'hidden p-2 text-right 2xl:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
            ],
            match: isPassingEntry,
        },
        {
            key: 'rushing',
            label: 'Rushing',
            defaultSortBy: 'rushing_yards_total',
            sortOptions: [
                { key: 'rushing_attempts_total', label: 'Rush Att' },
                { key: 'rushing_yards_total', label: 'Rush Yds' },
                { key: 'yards_per_carry', label: 'Yds/Rush' },
                { key: 'rushing_touchdowns_total', label: 'Rush TD' },
                { key: 'rushing_long_total', label: 'Long Rush' },
                {
                    key: 'rushing_two_point_conversions_total',
                    label: 'Rush 2PT',
                },
                { key: 'rushing_receiving_yards_total', label: 'Rush+Rec Yds' },
                {
                    key: 'rushing_receiving_touchdowns_total',
                    label: 'Rush+Rec TD',
                },
            ],
            statColumns: [
                {
                    key: 'rushing_attempts_total',
                    label: 'Rush Att',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'rushing_yards_total',
                    label: 'Rush Yds',
                    cellClass: 'p-2 text-right',
                },
                {
                    key: 'yards_per_carry',
                    label: 'Yds/Rush',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'rushing_touchdowns_total',
                    label: 'Rush TD',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'rushing_long_total',
                    label: 'Long Rush',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'rushing_two_point_conversions_total',
                    label: 'Rush 2PT',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'rushing_receiving_yards_total',
                    label: 'Rush+Rec Yds',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'rushing_receiving_touchdowns_total',
                    label: 'Rush+Rec TD',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
            ],
            match: isRushingEntry,
        },
        {
            key: 'receiving',
            label: 'Receiving',
            defaultSortBy: 'receiving_yards_total',
            sortOptions: [
                { key: 'receptions_total', label: 'Receptions' },
                { key: 'receiving_yards_total', label: 'Rec Yds' },
                { key: 'yards_per_reception', label: 'Yds/Catch' },
                { key: 'receiving_touchdowns_total', label: 'Rec TD' },
                { key: 'receiving_long_total', label: 'Long Rec' },
                { key: 'pass_targets_total', label: 'Targets' },
                { key: 'catch_rate', label: 'Catch Rate' },
                {
                    key: 'receiving_two_point_conversions_total',
                    label: 'Rec 2PT',
                },
            ],
            statColumns: [
                {
                    key: 'receptions_total',
                    label: 'Receptions',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'receiving_yards_total',
                    label: 'Rec Yds',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'yards_per_reception',
                    label: 'Yds/Catch',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'receiving_touchdowns_total',
                    label: 'Rec TD',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'receiving_long_total',
                    label: 'Long Rec',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'pass_targets_total',
                    label: 'Targets',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'catch_rate',
                    label: 'Catch Rate',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        `${Number(v ?? 0).toFixed(1)}%`,
                },
                {
                    key: 'receiving_two_point_conversions_total',
                    label: 'Rec 2PT',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
            ],
            match: (entry: Record<string, any>) =>
                Number(entry.receptions_total ?? 0) > 0 ||
                Number(entry.receiving_yards_total ?? 0) > 0 ||
                Number(entry.pass_targets_total ?? 0) > 0,
        },
        {
            key: 'returning',
            label: 'Returning',
            defaultSortBy: 'kickoff_return_yards_total',
            sortOptions: [
                { key: 'kickoff_returns_total', label: 'KR' },
                { key: 'kickoff_return_yards_total', label: 'KR Yds' },
                { key: 'yards_per_kickoff_return', label: 'Yds/KR' },
                { key: 'kickoff_return_touchdowns_total', label: 'KR TD' },
                { key: 'kickoff_return_long_total', label: 'Long KR' },
                { key: 'kickoff_return_fair_catches_total', label: 'KR FC' },
                { key: 'punt_returns_total', label: 'PR' },
                { key: 'punt_return_yards_total', label: 'PR Yds' },
                { key: 'yards_per_punt_return', label: 'Yds/PR' },
                { key: 'punt_return_touchdowns_total', label: 'PR TD' },
                { key: 'punt_return_long_total', label: 'Long PR' },
                { key: 'punt_return_fair_catches_total', label: 'PR FC' },
            ],
            statColumns: [
                {
                    key: 'kickoff_returns_total',
                    label: 'KR',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'kickoff_return_yards_total',
                    label: 'KR Yds',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'yards_per_kickoff_return',
                    label: 'Yds/KR',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'kickoff_return_touchdowns_total',
                    label: 'KR TD',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'kickoff_return_long_total',
                    label: 'Long KR',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'kickoff_return_fair_catches_total',
                    label: 'KR FC',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'punt_returns_total',
                    label: 'PR',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'punt_return_yards_total',
                    label: 'PR Yds',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'yards_per_punt_return',
                    label: 'Yds/PR',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'punt_return_touchdowns_total',
                    label: 'PR TD',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'punt_return_long_total',
                    label: 'Long PR',
                    cellClass: 'hidden p-2 text-right lg:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
                {
                    key: 'punt_return_fair_catches_total',
                    label: 'PR FC',
                    cellClass: 'hidden p-2 text-right lg:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(0),
                },
            ],
            match: (entry: Record<string, any>) =>
                Number(entry.kickoff_returns_total ?? 0) > 0 ||
                Number(entry.punt_returns_total ?? 0) > 0 ||
                Number(entry.kickoff_return_yards_total ?? 0) > 0 ||
                Number(entry.punt_return_yards_total ?? 0) > 0,
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
    sport: 'mlb',
    pageTitle: 'MLB Player Stats',
    heading: 'MLB Player Stats',
    description: 'Season leaderboard for MLB hitters',
    breadcrumb: { title: 'MLB Player Stats', href: '/mlb/player-stats' },
    bannerStorageKey: 'mlb-player-stats-banner-dismissed',
    minGames: 10,
    defaultSeasonType: '2',
    seasonTypeOptions: [
        { value: '1', label: 'Spring Training' },
        { value: '2', label: 'Regular Season' },
        { value: '3', label: 'Postseason' },
    ],
    showEpaColumns: false,
    sortOptions: [
        { key: 'points_per_game', label: 'H/G' },
        { key: 'rebounds_per_game', label: 'HR/G' },
        { key: 'assists_per_game', label: 'RBI/G' },
    ],
    statCategoryOptions: [
        {
            key: 'hitting',
            label: 'Hitting',
            defaultSortBy: 'points_per_game',
            sortOptions: [
                { key: 'points_per_game', label: 'H/G' },
                { key: 'rebounds_per_game', label: 'HR/G' },
                { key: 'assists_per_game', label: 'RBI/G' },
                { key: 'steals_per_game', label: 'SB/G' },
                { key: 'blocks_per_game', label: 'K/G' },
                { key: 'field_goal_percentage', label: 'AVG' },
                { key: 'three_point_percentage', label: 'OBP' },
                { key: 'free_throw_percentage', label: 'SLG' },
            ],
            statColumns: [
                {
                    key: 'points_per_game',
                    label: 'H/G',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'rebounds_per_game',
                    label: 'HR/G',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'assists_per_game',
                    label: 'RBI/G',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'steals_per_game',
                    label: 'SB/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'blocks_per_game',
                    label: 'K/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'field_goal_percentage',
                    label: 'AVG',
                    cellClass: 'hidden p-2 text-right lg:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(3),
                },
                {
                    key: 'three_point_percentage',
                    label: 'OBP',
                    cellClass: 'hidden p-2 text-right lg:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(3),
                },
                {
                    key: 'free_throw_percentage',
                    label: 'SLG',
                    cellClass: 'hidden p-2 text-right lg:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(3),
                },
                {
                    key: 'minutes_per_game',
                    label: 'AB/G',
                    cellClass:
                        'hidden p-2 text-right text-muted-foreground lg:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(1),
                },
            ],
            match: () => true,
        },
        {
            key: 'pitching',
            label: 'Pitching',
            minGames: 1,
            defaultSortBy: 'strikeouts_pitched_per_game',
            sortOptions: [
                { key: 'strikeouts_pitched_per_game', label: 'K/G' },
                { key: 'innings_pitched_per_game', label: 'IP/G' },
                { key: 'era_per_game', label: 'ERA' },
                { key: 'whip_per_game', label: 'WHIP' },
                { key: 'walks_allowed_per_game', label: 'BB/G' },
                { key: 'hits_allowed_per_game', label: 'H/G Allowed' },
                { key: 'home_runs_allowed_per_game', label: 'HR/G Allowed' },
            ],
            statColumns: [
                {
                    key: 'strikeouts_pitched_per_game',
                    label: 'K/G',
                    cellClass: 'p-2 text-right font-medium',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'innings_pitched_per_game',
                    label: 'IP/G',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'era_per_game',
                    label: 'ERA',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'whip_per_game',
                    label: 'WHIP',
                    cellClass: 'p-2 text-right',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(3),
                },
                {
                    key: 'walks_allowed_per_game',
                    label: 'BB/G',
                    cellClass: 'hidden p-2 text-right md:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'hits_allowed_per_game',
                    label: 'H/G A',
                    cellClass: 'hidden p-2 text-right lg:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
                {
                    key: 'home_runs_allowed_per_game',
                    label: 'HR/G A',
                    cellClass: 'hidden p-2 text-right lg:table-cell',
                    format: (v: number | undefined) =>
                        Number(v ?? 0).toFixed(2),
                },
            ],
            match: (entry: Record<string, any>) =>
                Number(entry.innings_pitched_per_game ?? 0) > 0 ||
                Number(entry.strikeouts_pitched_per_game ?? 0) > 0,
        },
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
    sport: 'cfb',
    pageTitle: 'CFB Player Stats',
    heading: 'CFB Player Stats',
    description: 'Season leaderboard for college football player production',
    breadcrumb: { title: 'CFB Player Stats', href: '/cfb/player-stats' },
    bannerStorageKey: 'cfb-player-stats-banner-dismissed',
    seasonTypeOptions: [
        { value: '1', label: 'Preseason' },
        { value: '2', label: 'Regular Season' },
        { value: '3', label: 'Postseason' },
    ],
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
