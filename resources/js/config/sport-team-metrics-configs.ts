import CBBTeamController from '@/actions/App/Http/Controllers/CBB/TeamController';
import MLBTeamController from '@/actions/App/Http/Controllers/MLB/TeamController';
import NBATeamController from '@/actions/App/Http/Controllers/NBA/TeamController';
import NFLTeamController from '@/actions/App/Http/Controllers/NFL/TeamController';
import WCBBTeamController from '@/actions/App/Http/Controllers/WCBB/TeamController';
import WNBATeamController from '@/actions/App/Http/Controllers/WNBA/TeamController';
import {
    formatBattingAverage,
    formatNumber,
    formatPercent,
    ratingClass,
} from '@/components/sport-team-metrics-helpers';
import type { MetricsConfig } from '@/components/SportTeamMetrics.vue';

type SupportedMetricsSport = 'nba' | 'cbb' | 'wnba' | 'wcbb' | 'nfl' | 'mlb';

interface CreateSportTeamMetricsConfigParams {
    sport: SupportedMetricsSport;
    title: string;
    subtitle: string;
    teamLink: MetricsConfig['teamLink'];
    sortOptions: MetricsConfig['sortOptions'];
    defaultSort: string;
    columns: MetricsConfig['columns'];
    hasMeetsMinimum?: boolean;
    apiEndpoint?: string;
    breadcrumbHref?: string;
}

export function createSportTeamMetricsConfig(
    params: CreateSportTeamMetricsConfigParams,
): MetricsConfig {
    return {
        sport: params.sport,
        title: params.title,
        subtitle: params.subtitle,
        apiEndpoint:
            params.apiEndpoint ?? `/api/v1/${params.sport}/team-metrics`,
        breadcrumbHref:
            params.breadcrumbHref ?? `/${params.sport}-team-metrics`,
        teamLink: params.teamLink,
        sortOptions: params.sortOptions,
        defaultSort: params.defaultSort,
        columns: params.columns,
        hasMeetsMinimum: params.hasMeetsMinimum,
    };
}

const eraClass = (value: number | null): string => {
    if (value === null) return '';
    if (value < 3.5)
        return 'text-green-600 dark:text-green-400 font-semibold';
    if (value < 4.0) return 'text-green-600 dark:text-green-400';
    if (value > 5.0) return 'text-red-600 dark:text-red-400 font-semibold';
    if (value > 4.5) return 'text-red-600 dark:text-red-400';
    return '';
};

const runsClass = (value: number | null): string => {
    if (value === null) return '';
    if (value > 5) return 'text-green-600 dark:text-green-400 font-semibold';
    if (value > 4.5) return 'text-green-600 dark:text-green-400';
    if (value < 3.5) return 'text-red-600 dark:text-red-400 font-semibold';
    if (value < 4) return 'text-red-600 dark:text-red-400';
    return '';
};

const turnoverClass = (value: number | null): string => {
    if (value === null) return '';
    if (value > 5) return 'text-green-600 dark:text-green-400 font-semibold';
    if (value > 0) return 'text-green-600 dark:text-green-400';
    if (value < -5) return 'text-red-600 dark:text-red-400 font-semibold';
    if (value < 0) return 'text-red-600 dark:text-red-400';
    return '';
};

export const nbaTeamMetricsConfig = createSportTeamMetricsConfig({
    sport: 'nba',
    title: 'NBA Team Metrics',
    subtitle: 'Advanced efficiency metrics for NBA teams',
    teamLink: (id: number) => NBATeamController.url(id),
    defaultSort: 'net_rating',
    sortOptions: [
        { key: 'net_rating', label: 'Net Rating', getValue: (m: any) => m.net_rating },
        { key: 'offensive_efficiency', label: 'Offense', getValue: (m: any) => m.offensive_efficiency },
        { key: 'defensive_efficiency', label: 'Defense', getValue: (m: any) => m.defensive_efficiency, lowerIsBetter: true },
    ],
    columns: [
        {
            label: 'Record',
            value: (m: any) => (m.wins !== null ? `${m.wins}-${m.losses}` : '-'),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'ORtg',
            value: (m: any) => formatNumber(m.offensive_efficiency),
        },
        {
            label: 'DRtg',
            value: (m: any) => formatNumber(m.defensive_efficiency),
        },
        {
            label: 'Net',
            value: (m: any) => formatNumber(m.net_rating),
            class: (m: any) => ratingClass(m.net_rating, 5),
        },
        {
            label: 'Tempo',
            value: (m: any) => formatNumber(m.tempo),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'SOS',
            value: (m: any) => formatNumber(m.strength_of_schedule, 3),
            class: () => 'text-muted-foreground',
        },
    ],
});

export const wnbaTeamMetricsConfig = createSportTeamMetricsConfig({
    sport: 'wnba',
    title: 'WNBA Team Metrics',
    subtitle: 'Advanced efficiency metrics for WNBA teams',
    teamLink: (id: number) => WNBATeamController.url(id),
    defaultSort: 'net_rating',
    sortOptions: [
        { key: 'net_rating', label: 'Net Rating', getValue: (m: any) => m.net_rating },
        { key: 'offensive_rating', label: 'Offense', getValue: (m: any) => m.offensive_rating },
        { key: 'defensive_rating', label: 'Defense', getValue: (m: any) => m.defensive_rating, lowerIsBetter: true },
        { key: 'true_shooting_percentage', label: 'TS%', getValue: (m: any) => m.true_shooting_percentage },
    ],
    columns: [
        {
            label: 'ORtg',
            value: (m: any) => formatNumber(m.offensive_rating),
        },
        {
            label: 'DRtg',
            value: (m: any) => formatNumber(m.defensive_rating),
        },
        {
            label: 'Net',
            value: (m: any) => formatNumber(m.net_rating),
            class: (m: any) => ratingClass(m.net_rating, 5),
        },
        {
            label: 'Pace',
            value: (m: any) => formatNumber(m.pace),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'eFG%',
            value: (m: any) => formatPercent(m.effective_field_goal_percentage),
        },
        {
            label: 'TO%',
            value: (m: any) => formatPercent(m.turnover_percentage),
        },
        {
            label: 'OREB%',
            value: (m: any) => formatPercent(m.offensive_rebound_percentage),
        },
        {
            label: 'FTR',
            value: (m: any) => formatPercent(m.free_throw_rate),
        },
        {
            label: 'TS%',
            value: (m: any) => formatPercent(m.true_shooting_percentage),
            class: () => 'font-medium',
        },
    ],
});

export const cbbTeamMetricsConfig = createSportTeamMetricsConfig({
    sport: 'cbb',
    title: 'CBB Team Metrics',
    subtitle: 'Advanced efficiency metrics for college basketball teams',
    teamLink: (id: number) => CBBTeamController.url(id),
    defaultSort: 'adj_net_rating',
    hasMeetsMinimum: true,
    sortOptions: [
        { key: 'adj_net_rating', label: 'Net Rating', getValue: (m: any) => m.adj_net_rating ?? m.net_rating },
        { key: 'offensive_efficiency', label: 'Offense', getValue: (m: any) => m.adj_offensive_efficiency ?? m.offensive_efficiency },
        { key: 'defensive_efficiency', label: 'Defense', getValue: (m: any) => m.adj_defensive_efficiency ?? m.defensive_efficiency, lowerIsBetter: true },
    ],
    columns: [
        {
            label: 'Record',
            value: (m: any) => (m.wins !== null ? `${m.wins}-${m.losses}` : `${m.games_played}`),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'AdjO',
            value: (m: any) => formatNumber(m.adj_offensive_efficiency ?? m.offensive_efficiency),
        },
        {
            label: 'AdjD',
            value: (m: any) => formatNumber(m.adj_defensive_efficiency ?? m.defensive_efficiency),
        },
        {
            label: 'AdjNet',
            value: (m: any) => formatNumber(m.adj_net_rating ?? m.net_rating),
            class: (m: any) => ratingClass(m.adj_net_rating ?? m.net_rating, 10),
        },
        {
            label: 'Tempo',
            value: (m: any) => formatNumber(m.adj_tempo ?? m.tempo),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'SOS',
            value: (m: any) => formatNumber(m.strength_of_schedule, 3),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'L10 Net',
            value: (m: any) => {
                const val = formatNumber(m.rolling_net_rating);
                const count = m.rolling_games_count ? ` (${m.rolling_games_count})` : '';
                return `${val}${count}`;
            },
            class: (m: any) => ratingClass(m.rolling_net_rating, 10),
        },
        {
            label: 'Home',
            value: (m: any) => {
                const net =
                    m.home_offensive_efficiency && m.home_defensive_efficiency
                        ? formatNumber(m.home_offensive_efficiency - m.home_defensive_efficiency)
                        : '-';
                const count = m.home_games ? ` (${m.home_games})` : '';
                return `${net}${count}`;
            },
        },
        {
            label: 'Away',
            value: (m: any) => {
                const net =
                    m.away_offensive_efficiency && m.away_defensive_efficiency
                        ? formatNumber(m.away_offensive_efficiency - m.away_defensive_efficiency)
                        : '-';
                const count = m.away_games ? ` (${m.away_games})` : '';
                return `${net}${count}`;
            },
        },
    ],
});

export const wcbbTeamMetricsConfig = createSportTeamMetricsConfig({
    sport: 'wcbb',
    title: 'WCBB Team Metrics',
    subtitle: "Advanced efficiency metrics for women's college basketball teams",
    teamLink: (id: number) => WCBBTeamController.url(id),
    defaultSort: 'adj_net_rating',
    hasMeetsMinimum: true,
    sortOptions: [
        { key: 'adj_net_rating', label: 'Net Rating', getValue: (m: any) => m.adj_net_rating ?? m.net_rating },
        { key: 'offensive_efficiency', label: 'Offense', getValue: (m: any) => m.adj_offensive_efficiency ?? m.offensive_efficiency },
        { key: 'defensive_efficiency', label: 'Defense', getValue: (m: any) => m.adj_defensive_efficiency ?? m.defensive_efficiency, lowerIsBetter: true },
    ],
    columns: [
        {
            label: 'GP',
            value: (m: any) => `${m.games_played}`,
            class: () => 'text-muted-foreground',
        },
        {
            label: 'AdjO',
            value: (m: any) => formatNumber(m.adj_offensive_efficiency ?? m.offensive_efficiency),
        },
        {
            label: 'AdjD',
            value: (m: any) => formatNumber(m.adj_defensive_efficiency ?? m.defensive_efficiency),
        },
        {
            label: 'AdjNet',
            value: (m: any) => formatNumber(m.adj_net_rating ?? m.net_rating),
            class: (m: any) => ratingClass(m.adj_net_rating ?? m.net_rating, 10),
        },
        {
            label: 'Tempo',
            value: (m: any) => formatNumber(m.adj_tempo ?? m.tempo),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'SOS',
            value: (m: any) => formatNumber(m.strength_of_schedule, 3),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'L10 Net',
            value: (m: any) => {
                const val = formatNumber(m.rolling_net_rating);
                const count = m.rolling_games_count ? ` (${m.rolling_games_count})` : '';
                return `${val}${count}`;
            },
            class: (m: any) => ratingClass(m.rolling_net_rating, 10),
        },
        {
            label: 'Home',
            value: (m: any) => {
                const net =
                    m.home_offensive_efficiency && m.home_defensive_efficiency
                        ? formatNumber(m.home_offensive_efficiency - m.home_defensive_efficiency)
                        : '-';
                const count = m.home_games ? ` (${m.home_games})` : '';
                return `${net}${count}`;
            },
        },
        {
            label: 'Away',
            value: (m: any) => {
                const net =
                    m.away_offensive_efficiency && m.away_defensive_efficiency
                        ? formatNumber(m.away_offensive_efficiency - m.away_defensive_efficiency)
                        : '-';
                const count = m.away_games ? ` (${m.away_games})` : '';
                return `${net}${count}`;
            },
        },
    ],
});

export const nflTeamMetricsConfig = createSportTeamMetricsConfig({
    sport: 'nfl',
    title: 'NFL Team Metrics',
    subtitle: 'Advanced metrics for NFL teams',
    teamLink: (id: number) => NFLTeamController.url(id),
    defaultSort: 'net_rating',
    sortOptions: [
        { key: 'net_rating', label: 'Net', getValue: (m: any) => m.net_rating },
        { key: 'offensive_rating', label: 'Offense', getValue: (m: any) => m.offensive_rating },
        { key: 'defensive_rating', label: 'Defense', getValue: (m: any) => m.defensive_rating, lowerIsBetter: true },
    ],
    columns: [
        {
            label: 'PPG',
            value: (m: any) => formatNumber(m.points_per_game),
        },
        {
            label: 'PA/G',
            value: (m: any) => formatNumber(m.points_allowed_per_game),
        },
        {
            label: 'Net',
            value: (m: any) => formatNumber(m.net_rating),
            class: (m: any) => ratingClass(m.net_rating, 5),
        },
        {
            label: 'YPG',
            value: (m: any) => formatNumber(m.yards_per_game),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'YA/G',
            value: (m: any) => formatNumber(m.yards_allowed_per_game),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'TO+/-',
            value: (m: any) => formatNumber(m.turnover_differential, 0),
            class: (m: any) => turnoverClass(m.turnover_differential),
        },
        {
            label: 'SOS',
            value: (m: any) => formatNumber(m.strength_of_schedule, 3),
            class: () => 'text-muted-foreground',
        },
    ],
});

export const mlbTeamMetricsConfig = createSportTeamMetricsConfig({
    sport: 'mlb',
    title: 'MLB Team Metrics',
    subtitle: 'Advanced metrics for MLB teams',
    teamLink: (id: number) => MLBTeamController.url(id),
    defaultSort: 'offensive_rating',
    sortOptions: [
        { key: 'offensive_rating', label: 'Offense', getValue: (m: any) => m.offensive_rating },
        { key: 'pitching_rating', label: 'Pitching', getValue: (m: any) => m.pitching_rating },
        { key: 'runs_per_game', label: 'R/G', getValue: (m: any) => m.runs_per_game },
    ],
    columns: [
        {
            label: 'R/G',
            value: (m: any) => formatNumber(m.runs_per_game, 2),
            class: (m: any) => runsClass(m.runs_per_game),
        },
        {
            label: 'RA/G',
            value: (m: any) => formatNumber(m.runs_allowed_per_game, 2),
            class: (m: any) => eraClass(m.runs_allowed_per_game),
        },
        {
            label: 'AVG',
            value: (m: any) => formatBattingAverage(m.batting_average),
        },
        {
            label: 'ERA',
            value: (m: any) => formatNumber(m.team_era, 2),
            class: (m: any) => eraClass(m.team_era),
        },
        {
            label: 'ORtg',
            value: (m: any) => formatNumber(m.offensive_rating),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'PRtg',
            value: (m: any) => formatNumber(m.pitching_rating),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'SOS',
            value: (m: any) => formatNumber(m.strength_of_schedule, 3),
            class: () => 'text-muted-foreground',
        },
    ],
});
