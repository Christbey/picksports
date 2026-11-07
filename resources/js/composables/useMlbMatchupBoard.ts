import { computed, ref, inject, type InjectionKey } from 'vue';
import { useMlbMatchupBoardData } from '@/composables/useMlbMatchupBoardData';
import { numberValue } from '@/lib/mlbMatchupPresentation';
import {
    marketMatches,
    searchHaystack,
    formatDateLabel,
} from '@/lib/mlbBoardPresentation';
import type { ApiV2Prediction } from '@/types';
import type { MarketFilter } from '@/types/mlb-board';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';

export function useMlbMatchupBoard() {
    const data = useMlbMatchupBoardData();
    const { predictions, dailyPayload } = data;
    const searchQuery = ref('');
    const selectedFilter = ref<MarketFilter>('all');
    const allCandidates = computed(() => dailyPayload.value?.candidates ?? []);
    const summary = computed(() => dailyPayload.value?.summary ?? null);

    const boardStats = computed(() => [
        {
            label: 'Games',
            value: summary.value?.slate_games ?? predictions.value.length,
        },
        {
            label: 'Priced',
            value: summary.value?.priced_games ?? 0,
        },
        {
            label: 'F3 priced',
            value: summary.value?.first_3_priced_games ?? 0,
        },
        {
            label: 'F5 priced',
            value: summary.value?.first_5_priced_games ?? 0,
        },
        {
            label: 'Candidates',
            value:
                summary.value?.candidate_count ?? candidateByGameId.value.size,
        },
        {
            label: 'Visible',
            value: filteredPredictions.value.length,
        },
    ]);

    const visibleBoardStats = computed(() =>
        boardStats.value.filter(
            (stat) =>
                stat.label === 'Games' ||
                stat.label === 'Visible' ||
                stat.value > 0,
        ),
    );

    const candidatesByGameId = computed(() => {
        const map = new Map<number, MlbDailyPick[]>();

        for (const candidate of allCandidates.value) {
            const candidates = map.get(candidate.game_id) ?? [];
            candidates.push(candidate);
            map.set(candidate.game_id, candidates);
        }

        return map;
    });

    const candidateByGameId = computed(() => {
        const map = new Map<number, MlbDailyPick>();

        for (const [gameId, candidates] of candidatesByGameId.value) {
            const candidate = candidates[0];

            if (candidate) {
                map.set(gameId, candidate);
            }
        }

        return map;
    });

    const marketFilters = computed(() => [
        { key: 'all' as const, label: 'All', count: predictions.value.length },
        {
            key: 'candidates' as const,
            label: 'Candidates',
            count: candidateByGameId.value.size,
        },
        {
            key: 'moneyline' as const,
            label: 'Moneyline',
            count: countMarket('moneyline'),
        },
        {
            key: 'run_line' as const,
            label: 'Run Line',
            count: countMarket('run_line'),
        },
        { key: 'total' as const, label: 'Totals', count: countMarket('total') },
        {
            key: 'first_inning' as const,
            label: '1st Inning',
            count: countMarket('first_inning'),
        },
        { key: 'first_5' as const, label: 'F5', count: countMarket('first_5') },
        { key: 'first_3' as const, label: 'F3', count: countMarket('first_3') },
        {
            key: 'player_prop' as const,
            label: 'Props',
            count: countMarket('player_prop'),
        },
        {
            key: 'tracked' as const,
            label: 'Tracked',
            count: allCandidates.value.filter(
                (candidate) => candidate.is_tracking_only,
            ).length,
        },
    ]);

    const visibleMarketFilters = computed(() =>
        marketFilters.value.filter(
            (filter) => filter.key === 'all' || filter.count > 0,
        ),
    );

    const normalizedSearch = computed(() =>
        searchQuery.value.trim().toLowerCase(),
    );

    const filteredPredictions = computed(() => {
        return predictions.value.filter((prediction) => {
            const candidate = candidateForPrediction(
                prediction,
                selectedFilter.value,
            );

            if (selectedFilter.value === 'candidates' && !candidate) {
                return false;
            }

            if (selectedFilter.value === 'tracked') {
                if (!candidate?.is_tracking_only) return false;
            } else if (!['all', 'candidates'].includes(selectedFilter.value)) {
                if (!candidate) return false;
            }

            if (!normalizedSearch.value) return true;

            return searchHaystack(prediction, candidate).includes(
                normalizedSearch.value,
            );
        });
    });

    const quietEmptyState = computed(() => {
        if (predictions.value.length === 0) {
            return {
                title: 'No matchup board found for this date.',
                body: 'Choose another date or rerun the MLB sync and prediction pipeline.',
            };
        }

        if (selectedFilter.value !== 'all') {
            return {
                title: 'No matchups match this market filter.',
                body: 'Use All to review the full slate while the candidate engine keeps scoring markets.',
            };
        }

        return {
            title: 'No matchups match this search.',
            body: 'Try a team abbreviation, city, or matchup code.',
        };
    });

    function candidateForPrediction(
        prediction: ApiV2Prediction,
        filter: MarketFilter = selectedFilter.value,
    ): MlbDailyPick | null {
        const gameId = numberValue(prediction.game_id ?? prediction.game?.id);

        if (gameId == null) return null;

        const candidates = candidatesByGameId.value.get(gameId) ?? [];

        if (filter === 'tracked') {
            return (
                candidates.find((candidate) => candidate.is_tracking_only) ??
                null
            );
        }

        if (!['all', 'candidates'].includes(filter)) {
            return (
                candidates.find((candidate) =>
                    marketMatches(prediction, candidate, filter),
                ) ?? null
            );
        }

        return candidates[0] ?? null;
    }

    function countMarket(filter: MarketFilter): number {
        const gameIds = new Set(
            allCandidates.value
                .filter((candidate) => marketMatches(null, candidate, filter))
                .map((candidate) => candidate.game_id),
        );

        return gameIds.size;
    }

    return {
        ...data,
        searchQuery,
        visibleBoardStats,
        candidatesByGameId,
        visibleMarketFilters,
        filteredPredictions,
        quietEmptyState,
        candidateForPrediction,
        selectedFilter,
        numberValue,
        formatDateLabel,
    };
}
export const mlbMatchupBoardKey: InjectionKey<
    ReturnType<typeof useMlbMatchupBoard>
> = Symbol('mlbMatchupBoardKey');
export function useMlbMatchupBoardContext() {
    const state = inject(mlbMatchupBoardKey);
    if (!state) throw new Error('MLB board provider is missing.');
    return state;
}
