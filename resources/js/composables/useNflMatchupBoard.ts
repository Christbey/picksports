import { computed, ref, inject, type InjectionKey } from 'vue';
import { useNflMatchupBoardData } from '@/composables/useNflMatchupBoardData';
import {
    filterMatches,
    searchHaystack,
} from '@/lib/nflMatchupBoardPresentation';
import { formatDateLabel } from '@/lib/matchupBoardPresentation';
import type { BoardFilter } from '@/types/nfl-matchup-board';
export function useNflMatchupBoard() {
    const data = useNflMatchupBoardData();
    const { predictions } = data;
    const searchQuery = ref(''),
        selectedFilter = ref<BoardFilter>('all'),
        showInsights = ref(false);
    const boardFilters = computed(() => [
        { key: 'all' as const, label: 'All', count: predictions.value.length },
        { key: 'bets' as const, label: 'Bets', count: countFilter('bets') },
        {
            key: 'watchlist' as const,
            label: 'Watchlist',
            count: countFilter('watchlist'),
        },
        {
            key: 'spread' as const,
            label: 'Spread edges',
            count: countFilter('spread'),
        },
        { key: 'total' as const, label: 'Totals', count: countFilter('total') },
        {
            key: 'winner' as const,
            label: 'ML signals',
            count: countFilter('winner'),
        },
        {
            key: 'finals' as const,
            label: 'Finals',
            count: countFilter('finals'),
        },
    ]);

    const visibleBoardFilters = computed(() =>
        boardFilters.value.filter(
            (filter) =>
                filter.key === 'all' ||
                filter.key === selectedFilter.value ||
                filter.count > 0,
        ),
    );

    const normalizedSearch = computed(() =>
        searchQuery.value.trim().toLowerCase(),
    );

    const filteredPredictions = computed(() => {
        return predictions.value.filter((prediction) => {
            if (
                selectedFilter.value !== 'all' &&
                !filterMatches(prediction, selectedFilter.value)
            ) {
                return false;
            }

            if (!normalizedSearch.value) return true;

            return searchHaystack(prediction).includes(normalizedSearch.value);
        });
    });

    const quietEmptyState = computed(() => {
        if (predictions.value.length === 0) {
            return {
                title: 'No NFL board found for this date.',
                body: 'Choose another date or rerun the NFL sync and prediction pipeline.',
            };
        }

        if (selectedFilter.value !== 'all') {
            return {
                title: 'No matchups match this board filter.',
                body: 'Use All to review the full slate while the signal layer keeps scoring edges.',
            };
        }

        return {
            title: 'No matchups match this search.',
            body: 'Try a team abbreviation, city, or matchup code.',
        };
    });

    function countFilter(filter: BoardFilter): number {
        return predictions.value.filter((prediction) =>
            filterMatches(prediction, filter),
        ).length;
    }

    return {
        ...data,
        searchQuery,
        selectedFilter,
        showInsights,
        visibleBoardFilters,
        filteredPredictions,
        quietEmptyState,
        formatDateLabel,
    };
}
export const nflMatchupBoardKey: InjectionKey<
    ReturnType<typeof useNflMatchupBoard>
> = Symbol('nflMatchupBoard');
export function useNflMatchupBoardContext() {
    const state = inject(nflMatchupBoardKey);
    if (!state) throw new Error('NFL matchup board provider is missing.');
    return state;
}
