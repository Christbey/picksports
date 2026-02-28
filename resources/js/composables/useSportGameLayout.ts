import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import { useSportDetailedPageProps, type UseSportDetailedPagePropsOptions } from '@/composables/useSportDetailedPageProps';
import { buildSportGameBreadcrumbs, createSportGamePageConfig, type SupportedGameSport } from '@/config/sport-game-page-configs';
import type { SportGamePageConfig } from '@/types';

interface UseSportGameLayoutOptions {
    sport: SupportedGameSport;
    gameId: MaybeRefOrGetter<number>;
    teamLink: SportGamePageConfig['teamLink'];
    configOverrides?: Partial<Omit<SportGamePageConfig, 'sport' | 'teamLink'>>;
    pageProps: Omit<UseSportDetailedPagePropsOptions, 'config' | 'breadcrumbs'>;
}

interface UseSportGameLayoutFromConfigOptions {
    config: SportGamePageConfig;
    gameId: MaybeRefOrGetter<number>;
    pageProps: Omit<UseSportDetailedPagePropsOptions, 'config' | 'breadcrumbs'>;
}

export function useSportGameLayout(options: UseSportGameLayoutOptions) {
    const config = createSportGamePageConfig({
        sport: options.sport,
        teamLink: options.teamLink,
        sportLabel: options.configOverrides?.sportLabel,
        predictionsHref: options.configOverrides?.predictionsHref,
        gameHrefPrefix: options.configOverrides?.gameHrefPrefix,
        gradientClass: options.configOverrides?.gradientClass,
        awayBarClass: options.configOverrides?.awayBarClass,
        homeBarClass: options.configOverrides?.homeBarClass,
        projectedLabel: options.configOverrides?.projectedLabel,
        metricsTitle: options.configOverrides?.metricsTitle,
        topPerformersMode: options.configOverrides?.topPerformersMode,
        trendsTitle: options.configOverrides?.trendsTitle,
    });

    const breadcrumbs = computed(() => buildSportGameBreadcrumbs(config, Number(toValue(options.gameId))));
    const pageProps = useSportDetailedPageProps({
        ...options.pageProps,
        breadcrumbs,
        config,
    });

    return {
        config,
        breadcrumbs,
        pageProps,
    };
}

export function useSportGameLayoutFromConfig(options: UseSportGameLayoutFromConfigOptions) {
    const breadcrumbs = computed(() => buildSportGameBreadcrumbs(options.config, Number(toValue(options.gameId))));
    const pageProps = useSportDetailedPageProps({
        ...options.pageProps,
        breadcrumbs,
        config: options.config,
    });

    return {
        config: options.config,
        breadcrumbs,
        pageProps,
    };
}
