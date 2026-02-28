<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface Healthcheck {
    id: number;
    sport: string;
    check_type: string;
    status: string;
    message: string;
    metadata: Record<string, any> | null;
    checked_at: string;
}

interface TeamScheduleOutlier {
    type: string;
    espn_id: number | string;
    team: string;
    games?: number;
    deviation_from_avg?: number;
}

interface Props {
    checks_by_sport: Record<string, Healthcheck[]>;
    status_counts: Record<string, number>;
    sports: string[];
    filters: {
        sport: string | null;
    };
}

const props = defineProps<Props>();

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
const isRunning = ref(false);
const syncingCheck = ref<string | null>(null);

function filterBySport() {
    router.get('/admin/healthchecks', {
        sport: selectedSport.value === 'all' ? null : selectedSport.value,
    }, {
        preserveState: true,
        replace: true,
    });
}

function runHealthChecks() {
    if (isRunning.value) return;

    isRunning.value = true;
    router.post('/admin/healthchecks/run', {
        sport: selectedSport.value === 'all' ? null : selectedSport.value,
    }, {
        onFinish: () => {
            isRunning.value = false;
        },
    });
}

function syncData(sport: string, checkType: string) {
    const syncKey = `${sport}-${checkType}`;
    if (syncingCheck.value === syncKey) return;

    syncingCheck.value = syncKey;
    router.post('/admin/healthchecks/sync', {
        sport,
        check_type: checkType,
    }, {
        onFinish: () => {
            syncingCheck.value = null;
        },
        preserveScroll: true,
    });
}

function canSync(sport: string, checkType: string): boolean {
    // MLB doesn't have sync-current command
    if (sport === 'mlb' && (checkType === 'data_freshness' || checkType === 'missing_games')) {
        return false;
    }
    // CFB and WNBA don't have generate-predictions or calculate-elo
    if ((sport === 'cfb' || sport === 'wnba') && (checkType === 'stale_predictions' || checkType === 'elo_status')) {
        return false;
    }
    // CFB, NFL, and WNBA don't have team metrics
    if ((sport === 'cfb' || sport === 'nfl' || sport === 'wnba') && checkType === 'team_metrics') {
        return false;
    }
    // Only CBB has team schedules sync
    if (checkType === 'team_schedules' && sport !== 'cbb') {
        return false;
    }
    return true;
}

function getSyncLabel(checkType: string): string {
    switch (checkType) {
        case 'data_freshness':
        case 'missing_games':
            return 'Sync Games';
        case 'stale_predictions':
            return 'Generate Predictions';
        case 'elo_status':
            return 'Calculate ELO';
        case 'team_schedules':
            return 'Sync Schedules';
        default:
            return 'Sync Data';
    }
}

function getStatusColor(status: string): string {
    switch (status) {
        case 'passing':
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
        case 'warning':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300';
        case 'failing':
            return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
    }
}

function getStatusIcon(status: string): string {
    switch (status) {
        case 'passing':
            return '✓';
        case 'warning':
            return '!';
        case 'failing':
            return '✕';
        default:
            return '?';
    }
}

function formatCheckType(type: string): string {
    return type
        .split('_')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function formatSportName(sport: string): string {
    return sport.toUpperCase();
}

function formatDate(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;

    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatAbsoluteDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
    });
}

const overallStatus = computed(() => {
    if ((props.status_counts.failing || 0) > 0) return 'failing';
    if ((props.status_counts.warning || 0) > 0) return 'warning';
    return 'passing';
});

function getTeamScheduleOutliers(metadata: Record<string, any> | null): TeamScheduleOutlier[] {
    if (!metadata || !Array.isArray(metadata.outliers)) {
        return [];
    }
    return metadata.outliers as TeamScheduleOutlier[];
}

function getNoGameOutliers(metadata: Record<string, any> | null): TeamScheduleOutlier[] {
    return getTeamScheduleOutliers(metadata).filter((outlier) => outlier.type === 'no_games');
}

function getScheduleOutliers(metadata: Record<string, any> | null): TeamScheduleOutlier[] {
    return getTeamScheduleOutliers(metadata).filter((outlier) => outlier.type !== 'no_games');
}
</script>

<template>
    <Head title="Health Checks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <RenderErrorBoundary title="Health Checks Render Error">
            <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Health Checks</h1>
                    <p class="mt-1 text-muted-foreground">
                        Monitor system health and data sync status
                    </p>
                </div>
                <button
                    @click="runHealthChecks"
                    :disabled="isRunning"
                    class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {{ isRunning ? 'Running Checks...' : 'Run Health Checks' }}
                </button>
            </div>

            <!-- Status Summary -->
            <div class="grid gap-4 md:grid-cols-4">
                <div class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Overall Status</p>
                            <p :class="['mt-2 text-2xl font-bold capitalize', getStatusColor(overallStatus)]">
                                {{ overallStatus }}
                            </p>
                        </div>
                        <div
                            :class="['flex h-12 w-12 items-center justify-center rounded-full text-2xl', getStatusColor(overallStatus)]"
                        >
                            {{ getStatusIcon(overallStatus) }}
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar p-6">
                    <p class="text-sm font-medium text-muted-foreground">Passing</p>
                    <p class="mt-2 text-2xl font-bold text-green-600 dark:text-green-400">
                        {{ status_counts.passing || 0 }}
                    </p>
                </div>

                <div class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar p-6">
                    <p class="text-sm font-medium text-muted-foreground">Warning</p>
                    <p class="mt-2 text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                        {{ status_counts.warning || 0 }}
                    </p>
                </div>

                <div class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar p-6">
                    <p class="text-sm font-medium text-muted-foreground">Failing</p>
                    <p class="mt-2 text-2xl font-bold text-red-600 dark:text-red-400">
                        {{ status_counts.failing || 0 }}
                    </p>
                </div>
            </div>

            <!-- Filter -->
            <div class="flex gap-4">
                <select
                    v-model="selectedSport"
                    @change="filterBySport"
                    class="rounded-lg border border-sidebar-border bg-white px-4 py-2 dark:bg-sidebar focus:outline-none focus:ring-2 focus:ring-primary"
                >
                    <option value="all">All Sports</option>
                    <option v-for="sport in sports" :key="sport" :value="sport">
                        {{ formatSportName(sport) }}
                    </option>
                </select>
            </div>

            <!-- Health Checks by Sport -->
            <div v-if="Object.keys(checks_by_sport).length === 0" class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar p-8 text-center">
                <p class="text-muted-foreground">No health checks found. Run <code class="rounded bg-sidebar-accent px-2 py-1">php artisan healthcheck:run</code> to generate checks.</p>
            </div>

            <div v-else class="space-y-6">
                <div
                    v-for="(checks, sport) in checks_by_sport"
                    :key="sport"
                    class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar p-6"
                >
                    <h2 class="mb-4 text-lg font-semibold">{{ formatSportName(sport) }}</h2>

                    <div class="space-y-3">
                        <div
                            v-for="check in checks"
                            :key="check.id"
                            class="flex items-start justify-between rounded-lg border border-sidebar-border bg-sidebar-accent p-4"
                        >
                            <div class="flex-1">
                                <div class="flex items-center gap-3">
                                    <span
                                        :class="['inline-flex items-center justify-center rounded-full px-3 py-1 text-sm font-medium', getStatusColor(check.status)]"
                                    >
                                        <span class="mr-1">{{ getStatusIcon(check.status) }}</span>
                                        {{ check.status }}
                                    </span>
                                    <h3 class="font-semibold">{{ formatCheckType(check.check_type) }}</h3>
                                </div>
                                <p class="mt-2 text-sm text-muted-foreground">{{ check.message }}</p>

                                <!-- Team Schedules Metadata -->
                                <div v-if="check.check_type === 'team_schedules' && check.metadata" class="mt-3 space-y-2">
                                    <div class="flex flex-wrap gap-2 text-xs text-muted-foreground">
                                        <span v-if="check.metadata.total_teams" class="rounded bg-white dark:bg-sidebar px-2 py-1">
                                            Total Teams: {{ check.metadata.total_teams }}
                                        </span>
                                        <span v-if="check.metadata.teams_with_games" class="rounded bg-white dark:bg-sidebar px-2 py-1">
                                            Teams with Games: {{ check.metadata.teams_with_games }}
                                        </span>
                                        <span v-if="check.metadata.average_games" class="rounded bg-white dark:bg-sidebar px-2 py-1">
                                            Avg Games: {{ Math.round(check.metadata.average_games * 10) / 10 }}
                                        </span>
                                        <span v-if="check.metadata.std_dev" class="rounded bg-white dark:bg-sidebar px-2 py-1">
                                            Std Dev: {{ Math.round(check.metadata.std_dev * 10) / 10 }}
                                        </span>
                                    </div>

                                    <!-- Teams with no games and outliers -->
                                    <div v-if="getTeamScheduleOutliers(check.metadata).length > 0" class="mt-2 space-y-2">
                                        <!-- Teams with no games -->
                                        <div v-if="getNoGameOutliers(check.metadata).length > 0">
                                            <p class="text-xs font-medium text-red-600 dark:text-red-400">
                                                Teams with no games ({{ getNoGameOutliers(check.metadata).length }}):
                                            </p>
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                <span
                                                    v-for="team in getNoGameOutliers(check.metadata)"
                                                    :key="team.espn_id"
                                                    class="rounded bg-red-100 dark:bg-red-900/30 px-2 py-1 text-xs text-red-800 dark:text-red-300"
                                                >
                                                    {{ team.team }} (#{{ team.espn_id }})
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Schedule outliers (too many/too few games) -->
                                        <div v-if="getScheduleOutliers(check.metadata).length > 0">
                                            <p class="text-xs font-medium text-yellow-600 dark:text-yellow-400">
                                                Schedule outliers ({{ getScheduleOutliers(check.metadata).length }}):
                                            </p>
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                <span
                                                    v-for="outlier in getScheduleOutliers(check.metadata)"
                                                    :key="outlier.espn_id"
                                                    class="rounded bg-yellow-100 dark:bg-yellow-900/30 px-2 py-1 text-xs text-yellow-800 dark:text-yellow-300"
                                                    :title="`${outlier.type}: ${Math.round((outlier.deviation_from_avg ?? 0) * 10) / 10} deviation from avg`"
                                                >
                                                    {{ outlier.team }} ({{ outlier.games }} games)
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Standard Metadata -->
                                <div v-else-if="check.metadata && check.check_type !== 'team_schedules'" class="mt-2 flex flex-wrap gap-2 text-xs text-muted-foreground">
                                    <span v-for="(value, key) in check.metadata" :key="key" class="rounded bg-white dark:bg-sidebar px-2 py-1">
                                        {{ key }}: {{ value }}
                                    </span>
                                </div>
                            </div>
                            <div class="ml-4 flex flex-col items-end gap-2">
                                <div
                                    class="text-sm text-muted-foreground cursor-help"
                                    :title="formatAbsoluteDate(check.checked_at)"
                                >
                                    {{ formatDate(check.checked_at) }}
                                </div>
                                <button
                                    v-if="canSync(check.sport, check.check_type)"
                                    @click="syncData(check.sport, check.check_type)"
                                    :disabled="syncingCheck === `${check.sport}-${check.check_type}`"
                                    class="rounded-md bg-primary px-3 py-1 text-xs font-medium text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {{ syncingCheck === `${check.sport}-${check.check_type}` ? 'Syncing...' : getSyncLabel(check.check_type) }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Info Box -->
            <div class="rounded-xl border border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950 p-4">
                <div class="flex gap-3">
                    <svg class="h-5 w-5 flex-shrink-0 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div class="text-sm text-blue-900 dark:text-blue-100">
                        <p class="font-medium">How Health Checks Work</p>
                        <p class="mt-1">
                            Health checks monitor data freshness, missing games, stale predictions, and ELO ratings across all sports. Run <code class="rounded bg-blue-100 dark:bg-blue-900 px-2 py-1">php artisan healthcheck:run</code> to update checks. Schedule this command to run regularly for continuous monitoring.
                        </p>
                    </div>
                </div>
            </div>
            </div>
        </RenderErrorBoundary>
    </AppLayout>
</template>
