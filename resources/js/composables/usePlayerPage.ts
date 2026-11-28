import { computed } from 'vue';
import type { BreadcrumbItem } from '@/types';
import type { PlayerPageProps } from '@/types/player-page';
import { usePlayerPageData } from '@/composables/usePlayerPageData';
import {
    defaultSummaryCards,
    defaultGameLogColumns,
} from '@/lib/playerPagePresentation';

export function usePlayerPage(props: PlayerPageProps) {
    const data = usePlayerPageData(props);
    const { playerData, gameLogs } = data;
    const breadcrumbs = computed<BreadcrumbItem[]>(() => {
        const items: BreadcrumbItem[] = [
            {
                title: props.config.sportLabel,
                href: props.config.predictionsHref,
            },
        ];
        if (playerData.value?.team?.id != null && props.config.teamLink) {
            items.push({
                title: playerData.value.team.name,
                href: String(props.config.teamLink(playerData.value.team.id)),
            });
        }
        items.push({ title: playerData.value?.name ?? 'Player', href: '#' });
        return items;
    });

    const summaryCards = computed(
        () => props.config.summaryCards ?? defaultSummaryCards,
    );

    const seasonSummaryValues = computed(() => {
        if (gameLogs.value.length === 0) return null;

        return summaryCards.value.map((card) => ({
            label: card.label,
            value: card.value(gameLogs.value),
            // Loaded log rows can cross seasons or be truncated; leaderboard ranks describe a different population.
            rank: null,
        }));
    });

    const gameLogColumns = computed(
        () => props.config.gameLogColumns ?? defaultGameLogColumns,
    );

    return { ...data, breadcrumbs, seasonSummaryValues, gameLogColumns };
}
