import type { BreadcrumbItem, SportGamePageConfig } from '@/types';

export type SupportedGameSport = 'nba' | 'cbb' | 'wnba' | 'wcbb' | 'nfl' | 'mlb';

const defaultLabels: Record<SupportedGameSport, string> = {
    nba: 'NBA',
    cbb: 'CBB',
    wnba: 'WNBA',
    wcbb: 'WCBB',
    nfl: 'NFL',
    mlb: 'MLB',
};

const defaultTheme: Partial<Record<SupportedGameSport, Pick<SportGamePageConfig, 'gradientClass' | 'awayBarClass' | 'homeBarClass' | 'projectedLabel' | 'metricsTitle' | 'topPerformersMode' | 'trendsTitle' | 'linescoreTitle' | 'linescoreUsePeriodNumbers' | 'linescorePeriodPrefix' | 'trendsEmptyText'>>> = {
    nba: {
        gradientClass: 'bg-gradient-to-r from-orange-600 to-orange-800 dark:from-orange-800 dark:to-orange-950',
        awayBarClass: 'bg-orange-500 dark:bg-orange-600',
        homeBarClass: 'bg-orange-800 dark:bg-orange-400',
        projectedLabel: 'Projected points',
        metricsTitle: 'Team Stats Comparison',
        topPerformersMode: 'list',
        trendsTitle: 'Team Trends',
        linescoreTitle: 'Quarter by Quarter',
        linescoreUsePeriodNumbers: true,
        trendsEmptyText: 'No trends available for this matchup',
    },
    cbb: {
        gradientClass: 'bg-gradient-to-r from-blue-600 to-blue-800 dark:from-blue-800 dark:to-blue-950',
        awayBarClass: 'bg-blue-500 dark:bg-blue-600',
        homeBarClass: 'bg-blue-800 dark:bg-blue-400',
        projectedLabel: 'Projected points',
        metricsTitle: 'Team Metrics Comparison',
        topPerformersMode: 'table',
        trendsTitle: 'Team Trends Comparison',
        linescoreTitle: 'Half by Half',
        linescoreUsePeriodNumbers: false,
        trendsEmptyText: 'No trends available for this matchup',
    },
    wnba: {
        gradientClass: 'bg-gradient-to-r from-purple-600 to-purple-800 dark:from-purple-800 dark:to-purple-950',
        awayBarClass: 'bg-purple-500 dark:bg-purple-600',
        homeBarClass: 'bg-purple-800 dark:bg-purple-400',
        projectedLabel: 'Projected points',
        trendsEmptyText: 'No trends available for this matchup',
        metricsTitle: 'Team Stats Comparison',
    },
    wcbb: {
        gradientClass: 'bg-gradient-to-r from-purple-600 to-purple-800 dark:from-purple-800 dark:to-purple-950',
        awayBarClass: 'bg-purple-500 dark:bg-purple-600',
        homeBarClass: 'bg-purple-800 dark:bg-purple-400',
        projectedLabel: 'Projected points',
        trendsEmptyText: 'No trends available for this matchup',
        metricsTitle: 'Team Stats Comparison',
    },
    nfl: {
        gradientClass: 'bg-gradient-to-r from-green-600 to-green-800 dark:from-green-800 dark:to-green-950',
        projectedLabel: 'Projected points',
        linescoreTitle: 'Quarter by Quarter',
        linescoreUsePeriodNumbers: true,
        trendsEmptyText: 'No trends available for this matchup',
    },
    mlb: {
        gradientClass: 'bg-gradient-to-r from-orange-600 to-orange-800 dark:from-orange-800 dark:to-orange-950',
        awayBarClass: 'bg-orange-500 dark:bg-orange-600',
        homeBarClass: 'bg-orange-800 dark:bg-orange-400',
        projectedLabel: 'Projected runs',
        linescoreTitle: 'Inning by Inning',
        linescoreUsePeriodNumbers: true,
        linescorePeriodPrefix: '',
        trendsEmptyText: 'No trends available for this matchup',
    },
};

interface CreateSportGamePageConfigParams {
    sport: SupportedGameSport;
    teamLink: SportGamePageConfig['teamLink'];
    sportLabel?: string;
    predictionsHref?: string;
    gameHrefPrefix?: string;
    gradientClass?: string;
    awayBarClass?: string;
    homeBarClass?: string;
    projectedLabel?: string;
    metricsTitle?: string;
    topPerformersMode?: 'list' | 'table';
    trendsTitle?: string;
    linescoreTitle?: string;
    linescoreUsePeriodNumbers?: boolean;
    linescorePeriodPrefix?: string;
    trendsEmptyText?: string;
}

export function createSportGamePageConfig(params: CreateSportGamePageConfigParams): SportGamePageConfig {
    const defaults = defaultTheme[params.sport] ?? {};

    return {
        sport: params.sport,
        sportLabel: params.sportLabel ?? defaultLabels[params.sport],
        predictionsHref: params.predictionsHref ?? `/${params.sport}-predictions`,
        gameHrefPrefix: params.gameHrefPrefix ?? `/${params.sport}/games`,
        teamLink: params.teamLink,
        gradientClass: params.gradientClass ?? defaults.gradientClass,
        awayBarClass: params.awayBarClass ?? defaults.awayBarClass,
        homeBarClass: params.homeBarClass ?? defaults.homeBarClass,
        projectedLabel: params.projectedLabel ?? defaults.projectedLabel,
        metricsTitle: params.metricsTitle ?? defaults.metricsTitle,
        topPerformersMode: params.topPerformersMode ?? defaults.topPerformersMode,
        trendsTitle: params.trendsTitle ?? defaults.trendsTitle,
        linescoreTitle: params.linescoreTitle ?? defaults.linescoreTitle,
        linescoreUsePeriodNumbers: params.linescoreUsePeriodNumbers ?? defaults.linescoreUsePeriodNumbers,
        linescorePeriodPrefix: params.linescorePeriodPrefix ?? defaults.linescorePeriodPrefix,
        trendsEmptyText: params.trendsEmptyText ?? defaults.trendsEmptyText,
    };
}

export function buildSportGameBreadcrumbs(config: SportGamePageConfig, gameId: number): BreadcrumbItem[] {
    return [
        { title: config.sportLabel, href: config.predictionsHref },
        { title: 'Games', href: config.predictionsHref },
        { title: `Game ${gameId}`, href: `${config.gameHrefPrefix}/${gameId}` },
    ];
}
