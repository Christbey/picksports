import {
    ref,
    computed,
    watch,
    onMounted,
    onBeforeUnmount,
    inject,
    type InjectionKey,
} from 'vue';
import { useAsyncResource } from '@/composables/useAsyncResource';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { BreadcrumbItem } from '@/types';
import type { BetsData, Statistics, UserBetsResponse } from '@/types/my-bets';
import {
    formatCurrency,
    formatDate,
    getSportName,
    displaySelection,
    getResultColor,
} from '@/lib/myBetsPresentation';
export function useMyBets() {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'My Bets',
            href: '/my-bets',
        },
    ];

    const bets = ref<BetsData>({
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
    });
    const statistics = ref<Statistics>({
        total_bets: 0,
        total_wagered: 0,
        wins: 0,
        losses: 0,
        pushes: 0,
        win_rate: 0,
        total_profit: 0,
        roi: 0,
    });
    const api = useApiV2Client();
    const showAddBetForm = ref(false);
    const form = ref({
        prediction_id: '',
        prediction_sport: 'nba',
        bet_amount: '',
        odds: '',
        bet_type: 'spread',
        selection_side: 'home',
        selection_label: '',
        line: '',
        notes: '',
    });

    const read = useAsyncResource(
        async (signal) => {
            const response = await api.userBets.index<UserBetsResponse>({
                init: { signal },
            });
            if (!response)
                throw new Error('Unable to load your bets. Please try again.');
            return response;
        },
        { clearOnError: false },
    );
    const loading = read.isLoading,
        error = read.error;
    const hasLoaded = computed(() => read.data.value !== null);
    watch(read.data, (response) => {
        if (response) {
            bets.value = response.bets;
            statistics.value = response.statistics;
        }
    });
    const fetchBets = () => read.execute();
    const submitting = ref(false),
        formError = ref<string | null>(null),
        mutationError = ref<string | null>(null);
    const pendingBetIds = ref(new Set<number>());
    const isBetPending = (id: number) => pendingBetIds.value.has(id);
    let disposed = false;
    onBeforeUnmount(() => {
        disposed = true;
    });
    function failureMessage(error: unknown, fallback: string): string {
        const data =
            error && typeof error === 'object' && 'data' in error
                ? error.data
                : null;
        const errors =
            data && typeof data === 'object' && 'errors' in data
                ? data.errors
                : null;
        if (errors && typeof errors === 'object') {
            const details = Object.values(errors)
                .flat()
                .filter((value) => typeof value === 'string');
            if (details.length) return details.join(' ');
        }
        return error instanceof Error ? error.message : fallback;
    }

    async function submitBet() {
        if (disposed || submitting.value) return;
        submitting.value = true;
        formError.value = null;
        try {
            await api.userBets.store({
                ...form.value,
                prediction_id:
                    form.value.prediction_id === ''
                        ? null
                        : form.value.prediction_id,
                prediction_sport:
                    form.value.prediction_id === ''
                        ? null
                        : form.value.prediction_sport,
            });
            if (disposed) return;
            showAddBetForm.value = false;
            form.value = {
                prediction_id: '',
                prediction_sport: 'nba',
                bet_amount: '',
                odds: '',
                bet_type: 'spread',
                selection_side: 'home',
                selection_label: '',
                line: '',
                notes: '',
            };
            await fetchBets();
        } catch (error) {
            if (!disposed)
                formError.value = failureMessage(
                    error,
                    'Unable to save this bet.',
                );
        } finally {
            if (!disposed) submitting.value = false;
        }
    }

    async function updateBetResult(
        betId: number,
        result: 'won' | 'lost' | 'push',
    ) {
        if (disposed || isBetPending(betId)) return;
        pendingBetIds.value.add(betId);
        mutationError.value = null;
        try {
            await api.userBets.update(betId, { result });
            if (!disposed) await fetchBets();
        } catch (error) {
            if (!disposed)
                mutationError.value = failureMessage(
                    error,
                    'Unable to update this bet.',
                );
        } finally {
            if (!disposed) pendingBetIds.value.delete(betId);
        }
    }
    async function deleteBet(betId: number) {
        if (
            disposed ||
            isBetPending(betId) ||
            !confirm('Are you sure you want to delete this bet?')
        )
            return;
        pendingBetIds.value.add(betId);
        mutationError.value = null;
        try {
            await api.userBets.destroy(betId);
            if (!disposed) await fetchBets();
        } catch (error) {
            if (!disposed)
                mutationError.value = failureMessage(
                    error,
                    'Unable to delete this bet.',
                );
        } finally {
            if (!disposed) pendingBetIds.value.delete(betId);
        }
    }

    function exportBets() {
        window.location.href = api.userBets.exportUrl();
    }

    onMounted(() => {
        fetchBets();
    });

    watch(
        () => form.value.bet_type,
        (betType) => {
            if (betType === 'total_over') {
                form.value.selection_side = 'over';
                return;
            }

            if (betType === 'total_under') {
                form.value.selection_side = 'under';
                return;
            }

            if (!['home', 'away'].includes(form.value.selection_side)) {
                form.value.selection_side = 'home';
            }
        },
    );

    return {
        breadcrumbs,
        bets,
        statistics,
        loading,
        error,
        hasLoaded,
        fetchBets,
        form,
        showAddBetForm,
        submitting,
        formError,
        mutationError,
        isBetPending,
        submitBet,
        updateBetResult,
        deleteBet,
        exportBets,
        formatCurrency,
        formatDate,
        getSportName,
        displaySelection,
        getResultColor,
    };
}
export const myBetsKey: InjectionKey<ReturnType<typeof useMyBets>> =
    Symbol('myBets');
export function useMyBetsContext() {
    const state = inject(myBetsKey);
    if (!state) throw new Error('My Bets provider is missing.');
    return state;
}
