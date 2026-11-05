import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { BreadcrumbItem } from '@/types';
import { inject, type InjectionKey, toRefs } from 'vue';
import type { MlbMonitoringProps } from '@/types/mlb-monitoring';

export function useMlbMonitoring(props: MlbMonitoringProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Admin', href: '/settings/admin' },
        { title: 'MLB Model Monitor', href: '/admin/mlb-model-monitoring' },
    ];
    const maximumReasonCount = computed(() =>
        Math.max(1, ...props.no_bet_reasons.map((row) => row.count)),
    );
    function selectArtifact(event: Event): void {
        const artifact = (event.target as HTMLSelectElement).value;

        router.get(
            '/admin/mlb-model-monitoring',
            artifact ? { artifact } : {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    }
    function formatPercent(value: number | null, digits = 1): string {
        return value === null ? 'N/A' : `${(value * 100).toFixed(digits)}%`;
    }
    function formatNumber(value: number | null, digits = 3): string {
        return value === null ? 'N/A' : value.toFixed(digits);
    }
    function formatUnits(value: number | null): string {
        if (value === null) return 'N/A';
        return `${value >= 0 ? '+' : ''}${value.toFixed(2)}u`;
    }
    function formatDate(value: string | null): string {
        if (!value) return 'Never';
        return new Intl.DateTimeFormat(undefined, {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(new Date(value));
    }
    function labelize(value: string): string {
        return value
            .replaceAll('_', ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase());
    }
    function shortHash(value: string | null): string {
        return value ? `${value.slice(0, 10)}...${value.slice(-6)}` : 'N/A';
    }
    function freshnessLabel(hours: number | null): string {
        if (hours === null) return 'No data';
        if (hours < 1) return `${Math.max(1, Math.round(hours * 60))}m ago`;
        return `${hours.toFixed(1)}h ago`;
    }
    function statusClass(status: string): string {
        if (status === 'promoted' || status === 'completed') {
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300';
        }
        if (['failed', 'failure', 'error'].includes(status)) {
            return 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300';
        }
        return 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300';
    }
    return {
        ...toRefs(props),
        props,
        breadcrumbs,
        maximumReasonCount,
        selectArtifact,
        formatPercent,
        formatNumber,
        formatUnits,
        formatDate,
        labelize,
        shortHash,
        freshnessLabel,
        statusClass,
    };
}
export const mlbMonitoringKey: InjectionKey<
    ReturnType<typeof useMlbMonitoring>
> = Symbol('MlbMonitoring');
export function useMlbMonitoringContext() {
    const state = inject(mlbMonitoringKey);
    if (!state) throw new Error('MlbMonitoring provider is missing.');
    return state;
}

export type { MlbMonitoringProps } from '@/types/mlb-monitoring';
