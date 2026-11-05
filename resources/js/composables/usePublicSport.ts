import {
    formatMetric,
    formatHomeSpreadLine,
    formatGameDate,
    impactTone,
    formatProbability,
    coverageBreakdown,
    predictionBarWidth,
    propRecommendationTone,
    propCardTone,
} from '@/lib/publicSportPresentation';
import { computed } from 'vue';
import { inject, type InjectionKey, toRefs } from 'vue';
import type { PublicSportProps } from '@/types/public-sport';

export function usePublicSport(props: PublicSportProps) {
    const navLinks = computed(() =>
        [
            { label: 'Predictions', href: props.links.predictions },
            { label: 'Injuries', href: props.links.injuries },
            { label: 'Teams', href: props.links.teamMetrics },
            { label: 'Players', href: props.links.playerStats },
            ...(props.hasPlayerProps
                ? [{ label: 'Props', href: props.links.playerProps }]
                : []),
        ].filter((link): link is { label: string; href: string } =>
            Boolean(link.href),
        ),
    );

    const topPrediction = computed(() => props.featuredPredictions[0] ?? null);
    const topTeam = computed(() => props.topTeams[0] ?? null);
    const topPlayer = computed(() => props.topPlayers[0] ?? null);
    return {
        ...toRefs(props),
        props,
        navLinks,
        formatMetric,
        formatHomeSpreadLine,
        formatGameDate,
        impactTone,
        formatProbability,
        coverageBreakdown,
        predictionBarWidth,
        propRecommendationTone,
        propCardTone,
        topPrediction,
        topTeam,
        topPlayer,
    };
}
export const publicSportKey: InjectionKey<ReturnType<typeof usePublicSport>> =
    Symbol('PublicSport');
export function usePublicSportContext() {
    const state = inject(publicSportKey);
    if (!state) throw new Error('PublicSport provider is missing.');
    return state;
}

export type { PublicSportProps } from '@/types/public-sport';
