import {
    computed,
    ref,
    watch,
    onMounted,
    onBeforeUnmount,
    inject,
    type InjectionKey,
} from 'vue';
import {
    BarChart3,
    ShieldCheck,
    Target,
    Zap,
    Gauge,
    CalendarDays,
    AlertTriangle,
} from 'lucide-vue-next';
import { useAsyncResource } from '@/composables/useAsyncResource';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { safeMlbBoardMode } from '@/lib/mlbRecommendationLabels';
import {
    formatDate,
    formatPercent,
    formatRecord,
    formatHitRate,
    achievementIcon,
    safeTrackingCopy,
    healthBarClass,
    bestCandidatePerGame,
} from '@/lib/mlbDailyPicksPresentation';
import type {
    MlbDailyPick,
    MlbDailyPicksPayload,
} from '@/types/mlb-daily-picks';

export function useMlbDailyPicksBoard(
    props: Readonly<{ season?: string | number | null }>,
) {
    const api = useApiV2Client();
    const payload = ref<MlbDailyPicksPayload['data'] | null>(null),
        selectedDate = ref(''),
        selectedTab = ref('all'),
        selectedPick = ref<MlbDailyPick | null>(null),
        detailOpen = ref(false);
    let disposed = false,
        loadedQuery: string | null = null;
    const request = useAsyncResource(
        async (signal) => {
            const query: Record<string, string | number> = {};
            if (props.season) query.season = props.season;
            if (selectedDate.value) query.date = selectedDate.value;
            const response = await api.dailyPicks.index<MlbDailyPicksPayload>(
                'mlb',
                { query, init: { signal } },
            );
            if (!response?.data)
                throw new Error('Failed to load MLB daily board');
            return {
                payload: response.data,
                key: JSON.stringify([
                    String(query.season ?? ''),
                    query.date ?? response.data.date,
                ]),
            };
        },
        { clearOnError: false },
    );
    const loading = request.isLoading,
        error = request.error;
    const queryKey = () =>
        JSON.stringify([String(props.season || ''), selectedDate.value]);
    const loadPicks = () => {
        if (disposed) return Promise.resolve(null);
        if (loadedQuery !== queryKey()) {
            payload.value = null;
            detailOpen.value = false;
        }
        return request.execute();
    };
    watch(request.data, (response) => {
        if (response) {
            payload.value = response.payload;
            loadedQuery = response.key;
            if (!selectedDate.value) selectedDate.value = response.payload.date;
        }
    });
    watch(
        () => props.season,
        () => {
            selectedDate.value = '';
            void loadPicks();
        },
    );
    onMounted(() => void loadPicks());
    onBeforeUnmount(() => {
        disposed = true;
    });
    const allCandidates = computed(() => payload.value?.candidates ?? []);
    const summary = computed(() => payload.value?.summary ?? null);
    const boardHealth = computed(() => payload.value?.board_health ?? null);
    const marketCounts = computed(() => payload.value?.market_counts ?? {});

    const marketTabs = computed(() =>
        [
            { key: 'all', label: 'All', count: marketCounts.value.all ?? 0 },
            {
                key: 'moneyline',
                label: 'Moneyline',
                count: marketCounts.value.moneyline ?? 0,
            },
            {
                key: 'run_line',
                label: 'Run Line',
                count: marketCounts.value.run_line ?? 0,
            },
            {
                key: 'total',
                label: 'Totals',
                count: marketCounts.value.total ?? 0,
            },
            {
                key: 'first_inning',
                label: '1st Inning',
                count: marketCounts.value.first_inning ?? 0,
            },
            {
                key: 'first_5',
                label: 'F5',
                count: marketCounts.value.first_5 ?? 0,
            },
            {
                key: 'first_3',
                label: 'F3',
                count: marketCounts.value.first_3 ?? 0,
            },
            {
                key: 'player_prop',
                label: 'Props',
                count: marketCounts.value.player_prop ?? 0,
            },
            {
                key: 'tracking',
                label: 'Watchlist',
                count: marketCounts.value.tracking ?? 0,
            },
        ].filter((tab) => tab.key === 'all' || tab.count > 0),
    );

    const filteredCandidates = computed(() => {
        const tab = selectedTab.value;
        if (tab === 'all') return allCandidates.value;
        if (tab === 'tracking') {
            return allCandidates.value.filter((pick) => pick.is_tracking_only);
        }
        if (tab === 'first_5') {
            return allCandidates.value.filter((pick) =>
                pick.market_type.startsWith('first_5'),
            );
        }
        if (tab === 'first_inning') {
            return allCandidates.value.filter((pick) =>
                pick.market_type.startsWith('first_inning'),
            );
        }
        if (tab === 'first_3') {
            return allCandidates.value.filter((pick) =>
                pick.market_type.startsWith('first_3'),
            );
        }

        return allCandidates.value.filter((pick) => pick.market_type === tab);
    });

    const visibleCandidates = computed(() =>
        bestCandidatePerGame(filteredCandidates.value).slice(0, 12),
    );

    const hiddenCandidateCount = computed(() =>
        Math.max(
            filteredCandidates.value.length - visibleCandidates.value.length,
            0,
        ),
    );

    const statusStrip = computed(() => [
        {
            label: 'Games',
            value: summary.value?.slate_games ?? 0,
            tone: 'text-sky-400',
        },
        {
            label: 'Priced',
            value: summary.value?.priced_games ?? 0,
            tone:
                (summary.value?.priced_games ?? 0) > 0
                    ? 'text-emerald-400'
                    : 'text-amber-400',
        },
        {
            label: 'Candidates',
            value: summary.value?.candidate_count ?? 0,
            tone:
                (summary.value?.candidate_count ?? 0) > 0
                    ? 'text-emerald-400'
                    : 'text-muted-foreground',
        },
        {
            label: 'Top Picks',
            value: summary.value?.top_candidate_count ?? 0,
            tone:
                (summary.value?.top_candidate_count ?? 0) > 0
                    ? 'text-emerald-400'
                    : 'text-muted-foreground',
        },
    ]);

    const healthRows = computed(() => [
        {
            icon: BarChart3,
            label: 'Slate Coverage',
            value: coverageDisplay.value,
            detail: coverageDetail.value,
            percent: boardHealth.value?.slate_coverage ?? null,
            tone:
                (boardHealth.value?.slate_coverage ?? 0) > 0
                    ? 'emerald'
                    : 'amber',
        },
        {
            icon: ShieldCheck,
            label: 'Pregame Safety',
            value: formatPercent(boardHealth.value?.pregame_safe_rate),
            detail:
                boardHealth.value?.pregame_safe_rate == null
                    ? 'Waiting for scan'
                    : 'Safety checks',
            percent: boardHealth.value?.pregame_safe_rate ?? null,
            tone: 'emerald',
        },
        {
            icon: Target,
            label: 'Market Agreement',
            value: formatPercent(boardHealth.value?.market_agreement_rate),
            detail:
                boardHealth.value?.market_agreement_rate == null
                    ? 'No candidates yet'
                    : 'Model-market context',
            percent: boardHealth.value?.market_agreement_rate ?? null,
            tone: 'sky',
        },
        {
            icon: Gauge,
            label: 'Board Signal',
            value:
                boardHealth.value?.score != null
                    ? String(boardHealth.value.score)
                    : 'Pending',
            detail:
                boardHealth.value?.score == null
                    ? 'Run pick engine'
                    : 'Average top score',
            percent:
                boardHealth.value?.score != null
                    ? boardHealth.value.score / 100
                    : null,
            tone: 'emerald',
        },
    ]);

    const coverageDisplay = computed(() => {
        const slateGames = summary.value?.slate_games ?? 0;
        const pricedGames = summary.value?.priced_games ?? 0;
        if (slateGames === 0) return 'No slate';

        return `${pricedGames}/${slateGames} priced`;
    });

    const coverageDetail = computed(() => {
        const pricedGames = summary.value?.priced_games ?? 0;
        if (pricedGames === 0) return 'Needs odds';

        return 'Pricing available';
    });

    const emptyState = computed(() => {
        const slateGames = summary.value?.slate_games ?? 0;
        const pricedGames = summary.value?.priced_games ?? 0;
        const candidateCount = summary.value?.candidate_count ?? 0;

        if (slateGames === 0) {
            return {
                title: 'No MLB slate found.',
                body: 'Select another date to review the daily board.',
                icon: CalendarDays,
            };
        }

        if (pricedGames === 0) {
            return {
                title: 'Market odds are not available yet.',
                body: 'Candidates will populate once pricing is synced for moneyline, totals, run line, F5, F3, and props.',
                icon: AlertTriangle,
            };
        }

        if (candidateCount === 0) {
            return {
                title: 'Daily board has not been scanned yet.',
                body: "Run today's pick engine to generate tracking candidates across sides, totals, F5/F3, and props.",
                icon: Zap,
            };
        }

        return {
            title: "No candidates cleared today's threshold.",
            body: 'The system did not force weak picks into the board.',
            icon: ShieldCheck,
        };
    });

    function selectPick(candidate: MlbDailyPick): void {
        selectedPick.value = candidate;
        detailOpen.value = true;
    }

    return {
        payload,
        loading,
        loadPicks,
        boardHealth,
        marketTabs,
        filteredCandidates,
        visibleCandidates,
        hiddenCandidateCount,
        statusStrip,
        healthRows,
        emptyState,
        selectPick,
        selectedDate,
        selectedTab,
        selectedPick,
        detailOpen,
        error,
        safeMlbBoardMode,
        formatDate,
        formatPercent,
        formatRecord,
        formatHitRate,
        achievementIcon,
        safeTrackingCopy,
        healthBarClass,
    };
}
export const mlbDailyPicksBoardKey: InjectionKey<
    ReturnType<typeof useMlbDailyPicksBoard>
> = Symbol('mlbDailyPicksBoardKey');
export function useMlbDailyPicksBoardContext() {
    const state = inject(mlbDailyPicksBoardKey);
    if (!state) throw new Error('MLB board provider is missing.');
    return state;
}
