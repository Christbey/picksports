import { computed } from 'vue';
import type { BreadcrumbItem } from '@/types';
import type { PlayerPageProps } from '@/types/player-page';
import { usePlayerPageData } from '@/composables/usePlayerPageData';
import {
    defaultSummaryCards,
    toNumber,
    defaultGameLogColumns,
} from '@/lib/playerPagePresentation';

export function usePlayerPage(props: PlayerPageProps) {
    const data = usePlayerPageData(props);
    const { playerData, gameLogs, leaderboardRows, leaderboardRanks } = data;
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

    const rankForMetric = (
        metricKey: string,
    ): { rank: number; total: number } | null => {
        const focusedRank = leaderboardRanks.value[metricKey];
        if (focusedRank) return focusedRank;

        if (leaderboardRows.value.length === 0 || !playerData.value)
            return null;

        const eligibleRows = leaderboardRows.value
            .filter((row) => toNumber(row[metricKey]) > 0)
            .sort((a, b) => toNumber(b[metricKey]) - toNumber(a[metricKey]));

        if (eligibleRows.length === 0) return null;

        const rankIndex = eligibleRows.findIndex(
            (row) => toNumber(row.player_id) === playerData.value?.id,
        );

        if (rankIndex === -1) return null;

        return { rank: rankIndex + 1, total: eligibleRows.length };
    };

    const seasonSummaryValues = computed(() => {
        if (gameLogs.value.length === 0) return null;

        return summaryCards.value.map((card) => ({
            label: card.label,
            value: card.value(gameLogs.value),
            rank: card.rankKey ? rankForMetric(card.rankKey) : null,
        }));
    });

    const gameLogColumns = computed(
        () => props.config.gameLogColumns ?? defaultGameLogColumns,
    );

    return { ...data, breadcrumbs, seasonSummaryValues, gameLogColumns };
}
