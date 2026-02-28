import type { UrlMethodPair } from '@inertiajs/core';

export type HrefLike = string | UrlMethodPair;

export interface MetricTile {
    label: string;
    value: (metrics: any) => string;
    class?: (metrics: any) => string;
}

export interface StatTile {
    label: string;
    value: (stats: any) => string;
    class?: (stats: any) => string;
    rankingKey?: string;
}

export interface TeamPageConfig {
    sport: string;
    sportLabel: string;
    predictionsHref: string;
    metricsHref?: string;
    headTitle: (team: any) => string;
    teamDisplayName: (team: any) => string;
    teamLogo: (team: any) => string | null;
    teamSubtitle: (team: any) => string;
    teamHref: (teamId: number) => HrefLike;
    gameLink: (gameId: number) => HrefLike;
    apiBase: string;

    metricTiles: MetricTile[];
    metricsGridCols?: string;

    seasonStatTiles?: StatTile[];
    seasonStatsGridCols?: string;
    overviewStatCount?: number;

    useTabs?: boolean;
    showPowerRanking?: boolean;
    showRecentForm?: boolean;
    showTrends?: boolean;
    trendsGames?: number;

    recentGamesLimit?: number;
    upcomingGamesLimit?: number;
    gamesLayout?: 'stacked' | 'side-by-side';
    sortRecentByDate?: boolean;

    headerInfo?: (team: any, computed: { record: { wins: number; losses: number } }) => { label: string; value: string }[];

    statRankingKeys?: { key: string; descending?: boolean }[];

    showRoster?: boolean;
    playerLink?: (playerId: number) => HrefLike;
}
