import { computed, ref, watch, inject, toRefs, type InjectionKey } from 'vue';
import { router } from '@inertiajs/vue3';
import type {
    NflModelMonitoringProps,
    SignalGradeRow,
} from '@/types/nfl-model-monitoring';
export function useNflModelMonitoring(props: NflModelMonitoringProps) {
    const selectedArtifact = ref(props.artifact?.id ?? '');
    const refreshing = ref(false);
    const maximumReasonCount = computed(() =>
        Math.max(1, ...props.no_bet_reasons.map((row) => row.count)),
    );
    const evaluationSummary = computed(
        () => props.artifact?.evaluation_summary ?? {},
    );
    const promotionMarkets = computed(() =>
        Object.entries(props.artifact?.promotion_markets ?? {}),
    );
    const worstEvaluationWindow = computed(() => {
        const measured = props.evaluation_windows.filter(
            (window) =>
                window.brier_delta !== null && window.brier_delta !== undefined,
        );

        return measured.length === 0
            ? null
            : measured.reduce((worst, window) =>
                  (window.brier_delta ?? 0) < (worst.brier_delta ?? 0)
                      ? window
                      : worst,
              );
    });
    const activeSignalType = ref(
        props.signal_grades[0]?.signal_type ?? 'reason_code',
    );
    const selectedSignalKeys = ref<Record<string, string>>(
        Object.fromEntries(
            props.signal_grades.map((category) => [
                category.signal_type,
                category.signals[0]?.signal_key ?? '',
            ]),
        ),
    );
    const signalGradesLoading = ref(false);
    watch(
        () => props.signal_grades,
        (categories) => {
            for (const category of categories) {
                if (
                    !category.signals.some(
                        (signal) =>
                            signal.signal_key ===
                            selectedSignalKeys.value[category.signal_type],
                    )
                ) {
                    selectedSignalKeys.value[category.signal_type] =
                        category.signals[0]?.signal_key ?? '';
                }
            }
        },
    );
    function loadSignalGrades(value: string | number): void {
        if (
            value !== 'signals' ||
            props.signal_grades.length > 0 ||
            signalGradesLoading.value
        )
            return;
        signalGradesLoading.value = true;
        router.reload({
            only: ['signal_grades', 'signal_report_generated_at'],
            onFinish: () => {
                signalGradesLoading.value = false;
            },
        });
    }
    const activeSignalCategory = computed(
        () =>
            props.signal_grades.find(
                (category) => category.signal_type === activeSignalType.value,
            ) ?? props.signal_grades[0],
    );
    const activeSignalWindows = computed(() => {
        const category = activeSignalCategory.value;
        if (!category) return [];

        const signalKey = selectedSignalKeys.value[category.signal_type];

        return category.windows.filter((row) => row.signal_key === signalKey);
    });

    function changeArtifact(): void {
        router.get(
            '/admin/nfl-model-monitoring',
            { artifact: selectedArtifact.value || undefined },
            { preserveState: true, replace: true },
        );
    }

    function refresh(): void {
        refreshing.value = true;
        router.reload({
            only: [
                'artifacts',
                'artifact',
                'summary',
                'observations',
                'no_bet_reasons',
                'evaluation_windows',
                ...(props.signal_grades.length > 0 ? ['signal_grades'] : []),
                'evidence_leaderboard',
                'report_status',
                'signal_report_generated_at',
            ],
            onFinish: () => {
                refreshing.value = false;
            },
        });
    }

    function selectSignal(row: SignalGradeRow): void {
        selectedSignalKeys.value[row.signal_type] = row.signal_key;
    }

    function signalIsSelected(row: SignalGradeRow): boolean {
        return selectedSignalKeys.value[row.signal_type] === row.signal_key;
    }

    return {
        ...toRefs(props),
        selectedArtifact,
        refreshing,
        maximumReasonCount,
        evaluationSummary,
        promotionMarkets,
        worstEvaluationWindow,
        activeSignalType,
        selectedSignalKeys,
        signalGradesLoading,
        loadSignalGrades,
        activeSignalCategory,
        activeSignalWindows,
        changeArtifact,
        refresh,
        selectSignal,
        signalIsSelected,
    };
}
export const nflMonitoringKey: InjectionKey<
    ReturnType<typeof useNflModelMonitoring>
> = Symbol('nflMonitoring');
export function useNflMonitoringContext() {
    const context = inject(nflMonitoringKey);
    if (!context)
        throw new Error('NFL monitoring sections require their page context');
    return context;
}
