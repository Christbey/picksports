import { computed, toValue, unref, type MaybeRefOrGetter } from 'vue';
import type {
    BreadcrumbItem,
    GamePageGame,
    GamePageTeam,
    LineScoreEntry,
    PredictionSummary,
    SportGamePageConfig,
    TeamTrendData,
} from '@/types';

export interface UseSportDetailedPagePropsOptions {
    title: MaybeRefOrGetter<string>;
    breadcrumbs: MaybeRefOrGetter<BreadcrumbItem[]>;
    loading: MaybeRefOrGetter<boolean>;
    error?: MaybeRefOrGetter<string | null>;
    awayTeam: MaybeRefOrGetter<GamePageTeam | null>;
    homeTeam: MaybeRefOrGetter<GamePageTeam | null>;
    game: MaybeRefOrGetter<GamePageGame>;
    gameStatus: MaybeRefOrGetter<string>;
    formatDate: MaybeRefOrGetter<(dateString: string | null) => string>;
    config: MaybeRefOrGetter<SportGamePageConfig>;
    awayRecentForm?: MaybeRefOrGetter<string>;
    homeRecentForm?: MaybeRefOrGetter<string>;
    venueLabel?: MaybeRefOrGetter<string | null | undefined>;
    broadcastNetworks?: MaybeRefOrGetter<string[]>;
    extraInfoItems?: MaybeRefOrGetter<string[]>;
    showScoreStatuses?: MaybeRefOrGetter<string[]>;
    badgePulseStatuses?: MaybeRefOrGetter<string[]>;
    useTeamColorGlow?: MaybeRefOrGetter<boolean>;
    showLinescore?: MaybeRefOrGetter<boolean>;
    linescoreTitle?: MaybeRefOrGetter<string>;
    awayLinescores?: MaybeRefOrGetter<LineScoreEntry[]>;
    homeLinescores?: MaybeRefOrGetter<LineScoreEntry[]>;
    awayScore?: MaybeRefOrGetter<number | null | undefined>;
    homeScore?: MaybeRefOrGetter<number | null | undefined>;
    usePeriodNumbers?: MaybeRefOrGetter<boolean>;
    periodPrefix?: MaybeRefOrGetter<string | undefined>;
    showPredictionSummary?: MaybeRefOrGetter<boolean>;
    prediction?: MaybeRefOrGetter<PredictionSummary | null>;
    awayLabel?: MaybeRefOrGetter<string | null>;
    homeLabel?: MaybeRefOrGetter<string | null>;
    formatNumber?: MaybeRefOrGetter<(value: number | string | null | undefined, decimals?: number) => string>;
    projectedLabel?: MaybeRefOrGetter<string>;
    awayBarClass?: MaybeRefOrGetter<string>;
    homeBarClass?: MaybeRefOrGetter<string>;
    showTrends?: MaybeRefOrGetter<boolean>;
    trendsTitle?: MaybeRefOrGetter<string>;
    trendsSubtitle?: MaybeRefOrGetter<string | undefined>;
    trendsLoading?: MaybeRefOrGetter<boolean>;
    allTrendCategories?: MaybeRefOrGetter<string[]>;
    formatCategoryName?: MaybeRefOrGetter<(value: string) => string>;
    isLockedCategory?: MaybeRefOrGetter<(category: string) => boolean>;
    formatTierName?: MaybeRefOrGetter<(tier: string) => string>;
    getRequiredTier?: MaybeRefOrGetter<(category: string) => string>;
    awayTrends?: MaybeRefOrGetter<TeamTrendData | null>;
    homeTrends?: MaybeRefOrGetter<TeamTrendData | null>;
    trendsEmptyText?: MaybeRefOrGetter<string>;
}

export function useSportDetailedPageProps(options: UseSportDetailedPagePropsOptions) {
    const resolveFn = <T extends (...args: any[]) => any>(
        fn: MaybeRefOrGetter<T> | undefined,
    ): T | undefined => {
        if (!fn) return undefined;
        return unref(fn as T);
    };

    return computed(() => {
        const config = toValue(options.config);

        return {
            title: toValue(options.title),
            breadcrumbs: toValue(options.breadcrumbs),
            loading: toValue(options.loading),
            error: options.error ? toValue(options.error) : null,
            awayTeam: toValue(options.awayTeam),
            homeTeam: toValue(options.homeTeam),
            game: toValue(options.game),
            gameStatus: toValue(options.gameStatus),
            formatDate:
                resolveFn(options.formatDate) ??
                ((dateString: string | null) => dateString ?? ''),
            teamLink: config.teamLink,
            gradientClass: config.gradientClass || '',
            awayRecentForm: options.awayRecentForm ? toValue(options.awayRecentForm) : undefined,
            homeRecentForm: options.homeRecentForm ? toValue(options.homeRecentForm) : undefined,
            venueLabel: options.venueLabel ? toValue(options.venueLabel) : undefined,
            broadcastNetworks: options.broadcastNetworks ? toValue(options.broadcastNetworks) : [],
            extraInfoItems: options.extraInfoItems ? toValue(options.extraInfoItems) : [],
            showScoreStatuses: options.showScoreStatuses ? toValue(options.showScoreStatuses) : ['STATUS_FINAL'],
            badgePulseStatuses: options.badgePulseStatuses ? toValue(options.badgePulseStatuses) : [],
            useTeamColorGlow: options.useTeamColorGlow ? toValue(options.useTeamColorGlow) : false,
            showLinescore: options.showLinescore ? toValue(options.showLinescore) : false,
            linescoreTitle: options.linescoreTitle ? toValue(options.linescoreTitle) : 'Linescore',
            awayLinescores: options.awayLinescores ? toValue(options.awayLinescores) : [],
            homeLinescores: options.homeLinescores ? toValue(options.homeLinescores) : [],
            awayScore: options.awayScore ? toValue(options.awayScore) : null,
            homeScore: options.homeScore ? toValue(options.homeScore) : null,
            usePeriodNumbers: options.usePeriodNumbers ? toValue(options.usePeriodNumbers) : true,
            periodPrefix: options.periodPrefix ? toValue(options.periodPrefix) : undefined,
            showPredictionSummary: options.showPredictionSummary ? toValue(options.showPredictionSummary) : false,
            prediction: options.prediction ? toValue(options.prediction) : null,
            awayLabel: options.awayLabel ? toValue(options.awayLabel) : null,
            homeLabel: options.homeLabel ? toValue(options.homeLabel) : null,
            formatNumber: resolveFn(options.formatNumber),
            projectedLabel: options.projectedLabel ? toValue(options.projectedLabel) : config.projectedLabel || 'Projected points',
            awayBarClass: options.awayBarClass ? toValue(options.awayBarClass) : config.awayBarClass || 'bg-blue-500 dark:bg-blue-600',
            homeBarClass: options.homeBarClass ? toValue(options.homeBarClass) : config.homeBarClass || 'bg-blue-800 dark:bg-blue-400',
            showTrends: options.showTrends ? toValue(options.showTrends) : false,
            trendsTitle: options.trendsTitle ? toValue(options.trendsTitle) : config.trendsTitle || 'Team Trends',
            trendsSubtitle: options.trendsSubtitle ? toValue(options.trendsSubtitle) : undefined,
            trendsLoading: options.trendsLoading ? toValue(options.trendsLoading) : false,
            allTrendCategories: options.allTrendCategories ? toValue(options.allTrendCategories) : [],
            formatCategoryName: resolveFn(options.formatCategoryName),
            isLockedCategory: resolveFn(options.isLockedCategory),
            formatTierName: resolveFn(options.formatTierName),
            getRequiredTier: resolveFn(options.getRequiredTier),
            awayTrends: options.awayTrends ? toValue(options.awayTrends) : null,
            homeTrends: options.homeTrends ? toValue(options.homeTrends) : null,
            trendsEmptyText: options.trendsEmptyText ? toValue(options.trendsEmptyText) : 'No trends available for this matchup',
        };
    });
}
