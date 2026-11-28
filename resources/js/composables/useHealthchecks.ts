import {
    canSync,
    getSyncLabel,
    getStatusColor,
    getStatusIcon,
    formatStatus,
    formatCheckType,
    formatSportName,
    formatDate,
    formatAbsoluteDate,
    formatDelta,
    labelize,
    percent,
    widthPercent,
    countWidth,
    getTeamScheduleOutliers,
    getNoGameOutliers,
    getScheduleOutliers,
    formatMetadataLabel,
    formatMetadataValue,
    displayMessage,
    metadataEntries,
} from '@/lib/healthcheckPresentation';
import { router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { type BreadcrumbItem } from '@/types';
import { inject, type InjectionKey, toRefs } from 'vue';
import type { HealthchecksProps } from '@/types/healthchecks';

export function useHealthchecks(props: HealthchecksProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Admin',
            href: '/admin/healthchecks',
        },
        {
            title: 'Health Checks',
            href: '/admin/healthchecks',
        },
    ];
    const selectedSport = ref(props.filters.sport || 'all');
    const selectedView = ref(props.filters.view || 'heartbeat');
    const isRunning = ref(false);
    const syncingCheck = ref<string | null>(null);
    const pendingSyncKeys = ref(new Set<string>());
    const isSyncing = (sport: string, checkType: string) =>
        pendingSyncKeys.value.has(`${sport}-${checkType}`);
    function filterBySport() {
        router.get(
            '/admin/healthchecks',
            {
                sport:
                    selectedSport.value === 'all' ? null : selectedSport.value,
                view: selectedView.value,
                validation_run: null,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    }
    function selectValidationRun(runId: number) {
        router.get(
            '/admin/healthchecks',
            {
                sport:
                    selectedSport.value === 'all' ? null : selectedSport.value,
                view: 'validation',
                validation_run: runId,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }
    function runHealthChecks() {
        if (isRunning.value) return;

        isRunning.value = true;
        router.post(
            '/admin/healthchecks/run',
            {
                sport:
                    selectedSport.value === 'all' ? null : selectedSport.value,
                mode: selectedView.value,
            },
            {
                onFinish: () => {
                    isRunning.value = false;
                },
            },
        );
    }
    function syncData(sport: string, checkType: string) {
        const syncKey = `${sport}-${checkType}`;
        if (pendingSyncKeys.value.has(syncKey)) return;

        pendingSyncKeys.value.add(syncKey);
        syncingCheck.value = syncKey;
        router.post(
            '/admin/healthchecks/sync',
            {
                sport,
                check_type: checkType,
            },
            {
                onFinish: () => {
                    pendingSyncKeys.value.delete(syncKey);
                    if (syncingCheck.value === syncKey)
                        syncingCheck.value =
                            pendingSyncKeys.value.values().next().value ?? null;
                },
                preserveScroll: true,
            },
        );
    }

    const overallStatus = computed(() => {
        if ((props.status_counts.failing || 0) > 0) return 'failing';
        if ((props.status_counts.warning || 0) > 0) return 'warning';
        return (props.status_counts.passing || 0) > 0 ? 'passing' : 'Unknown';
    });

    return {
        ...toRefs(props),
        props,
        breadcrumbs,
        selectedSport,
        selectedView,
        isRunning,
        syncingCheck,
        isSyncing,
        filterBySport,
        selectValidationRun,
        runHealthChecks,
        syncData,
        canSync,
        getSyncLabel,
        getStatusColor,
        getStatusIcon,
        formatStatus,
        formatCheckType,
        formatSportName,
        formatDate,
        formatAbsoluteDate,
        formatDelta,
        labelize,
        percent,
        widthPercent,
        countWidth,
        overallStatus,
        getTeamScheduleOutliers,
        getNoGameOutliers,
        getScheduleOutliers,
        formatMetadataLabel,
        formatMetadataValue,
        displayMessage,
        metadataEntries,
    };
}
export const healthchecksKey: InjectionKey<ReturnType<typeof useHealthchecks>> =
    Symbol('Healthchecks');
export function useHealthchecksContext() {
    const state = inject(healthchecksKey);
    if (!state) throw new Error('Healthchecks provider is missing.');
    return state;
}

export type { HealthchecksProps } from '@/types/healthchecks';
