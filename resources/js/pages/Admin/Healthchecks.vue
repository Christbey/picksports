<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
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
        view: string | null;
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
const selectedView = ref(props.filters.view || 'heartbeat');
const isRunning = ref(false);
const syncingCheck = ref<string | null>(null);

function filterBySport() {
    router.get('/admin/healthchecks', {
        sport: selectedSport.value === 'all' ? null : selectedSport.value,
        view: selectedView.value,
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
        mode: selectedView.value,
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
    return [
        'heartbeat_sync',
        'heartbeat_live_scoreboard',
        'heartbeat_prediction_pipeline',
        'heartbeat_model_pipeline',
        'heartbeat_odds',
    ].includes(checkType);
}

function getSyncLabel(checkType: string): string {
    switch (checkType) {
        case 'heartbeat_sync':
            return 'Run Sync';
        case 'heartbeat_live_scoreboard':
            return 'Run Scoreboard';
        case 'heartbeat_prediction_pipeline':
            return 'Generate Predictions';
        case 'heartbeat_model_pipeline':
            return 'Run Model Jobs';
        case 'heartbeat_odds':
            return 'Sync Odds';
        default:
            return 'Run Command';
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

function formatStatus(status: string): string {
    return status.charAt(0).toUpperCase() + status.slice(1);
}

function formatCheckType(type: string): string {
    const labels: Record<string, string> = {
        heartbeat_sync: 'Data Sync',
        heartbeat_live_scoreboard: 'Live Scoreboard Sync',
        heartbeat_prediction_pipeline: 'Prediction Pipeline',
        heartbeat_model_pipeline: 'Model Pipeline',
        heartbeat_odds: 'Odds Sync',
        validation_game_coverage: 'Game Coverage',
        validation_team_stat_coverage: 'Team Stat Coverage',
    };

    if (labels[type]) {
        return labels[type];
    }

    const displayType = type
        .replace(/^heartbeat_/, '')
        .replace(/^validation_/, '');

    return displayType
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

function formatMetadataLabel(key: string): string {
    const labels: Record<string, string> = {
        in_season: 'In Season',
        is_during_game_hours: 'Game Window',
        current_hour: 'Current Hour',
        game_hours: 'Game Window Hours',
        age_minutes: 'Last Success Age',
        warning_after_minutes: 'Warning Threshold',
        failing_after_minutes: 'Failing Threshold',
        last_success_at: 'Last Success',
        last_failure_at: 'Last Failure',
        last_failure_error: 'Failure Error',
        command_patterns: 'Command Patterns',
        teams_with_games: 'Teams With Games',
        teams_missing_games: 'Teams Missing Games',
        teams_with_stats: 'Teams With Stats',
        teams_missing_stats: 'Teams Missing Stats',
        upcoming_games: 'Upcoming Games',
        expected_upcoming_games: 'Expected Upcoming Games',
    };

    return labels[key] ?? key.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
}

function formatMetadataValue(key: string, value: unknown): string {
    if (value === null || value === undefined || value === '') return '';

    if (key === 'warning_after_minutes' || key === 'failing_after_minutes') {
        return `${value} min`;
    }

    if (key === 'age_minutes') {
        return `${value} min`;
    }

    if (key === 'current_hour' && typeof value === 'number') {
        return `${value}:00`;
    }

    if (key === 'last_success_at' || key === 'last_failure_at') {
        if (typeof value === 'string') {
            return formatDate(value);
        }
    }

    if (key === 'command_patterns' && Array.isArray(value)) {
        return value.map((pattern) => String(pattern).replace(/%+$/g, '')).join(', ');
    }

    if (
        key === 'game_hours' &&
        typeof value === 'object' &&
        value !== null &&
        'start' in value &&
        'end' in value
    ) {
        const hours = value as { start: number; end: number };
        return `${String(hours.start).padStart(2, '0')}:00 - ${String(hours.end).padStart(2, '0')}:00`;
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

function displayMessage(message: string): string {
    if (!message) return '';

    // Legacy records may include debug lines after the first line; keep headline only.
    return message.split('\n')[0]?.trim() ?? message;
}

function metadataEntries(check: Healthcheck): Array<{ key: string; label: string; value: string; raw: unknown }> {
    if (!check.metadata) return [];

    const keys = [
        'in_season',
        'is_during_game_hours',
        'game_hours',
        'current_hour',
        'last_success_at',
        'last_failure_at',
        'age_minutes',
        'warning_after_minutes',
        'failing_after_minutes',
        'command_patterns',
        'last_failure_error',
        'teams_with_games',
        'teams_missing_games',
        'teams_with_stats',
        'teams_missing_stats',
        'upcoming_games',
        'expected_upcoming_games',
    ];

    return keys
        .filter((key) => key in check.metadata)
        .map((key) => ({
            key,
            label: formatMetadataLabel(key),
            value: formatMetadataValue(key, check.metadata?.[key]),
            raw: check.metadata?.[key],
        }))
        .filter((entry) => entry.value !== '');
}
</script>

<template>
    <Head title="Health Checks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :full-width="true">
            <RenderErrorBoundary title="Health Checks Render Error">
                <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Health Checks</h1>
                    <p class="mt-1 text-muted-foreground">
                        Monitor command heartbeat and pipeline freshness
                    </p>
                </div>
                <button
                    @click="runHealthChecks"
                    :disabled="isRunning"
                    class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {{ isRunning ? 'Running Checks...' : `Run ${selectedView === 'validation' ? 'Validation' : 'Heartbeat'} Checks` }}
                </button>
            </div>

            <div class="flex gap-2">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                    :class="selectedView === 'heartbeat' ? 'bg-primary text-primary-foreground' : 'bg-sidebar-accent hover:bg-sidebar-accent/80'"
                    @click="selectedView = 'heartbeat'; filterBySport()"
                >
                    Heartbeat
                </button>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                    :class="selectedView === 'validation' ? 'bg-primary text-primary-foreground' : 'bg-sidebar-accent hover:bg-sidebar-accent/80'"
                    @click="selectedView = 'validation'; filterBySport()"
                >
                    Data Validation
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
                                        {{ formatStatus(check.status) }}
                                    </span>
                                    <h3 class="font-semibold">{{ formatCheckType(check.check_type) }}</h3>
                                </div>
                                <p class="mt-2 text-sm text-muted-foreground">{{ displayMessage(check.message) }}</p>

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
                                <div v-else-if="check.metadata" class="mt-3 space-y-2">
                                    <div class="flex flex-wrap gap-2 text-xs">
                                        <template v-for="entry in metadataEntries(check)" :key="entry.key">
                                            <span
                                                v-if="entry.key !== 'command_patterns' && entry.key !== 'last_failure_error'"
                                                class="inline-flex items-center gap-1 rounded bg-white px-2 py-1 text-foreground dark:bg-sidebar"
                                            >
                                                <span class="font-medium text-muted-foreground">{{ entry.label }}</span>
                                                <span>{{ entry.value }}</span>
                                            </span>
                                        </template>
                                    </div>

                                    <template v-for="entry in metadataEntries(check)" :key="`${entry.key}-patterns-wrap`">
                                        <div
                                            v-if="entry.key === 'command_patterns'"
                                            :key="`${entry.key}-patterns`"
                                            class="space-y-1"
                                        >
                                            <p class="text-xs font-medium text-muted-foreground">{{ entry.label }}</p>
                                            <div class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="pattern in Array.isArray(entry.raw) ? entry.raw : [entry.value]"
                                                    :key="String(pattern)"
                                                    class="rounded border border-sidebar-border bg-white px-2 py-1 font-mono text-[11px] text-foreground dark:bg-sidebar"
                                                >
                                                    {{ String(pattern).replace(/%+$/g, '') }}
                                                </span>
                                            </div>
                                        </div>
                                    </template>

                                    <template v-for="entry in metadataEntries(check)" :key="`${entry.key}-error-wrap`">
                                        <div
                                            v-if="entry.key === 'last_failure_error'"
                                            :key="`${entry.key}-error`"
                                            class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300"
                                        >
                                            <p class="mb-1 font-medium">{{ entry.label }}</p>
                                            <p class="font-mono leading-relaxed">{{ entry.value }}</p>
                                        </div>
                                    </template>
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
                        <p v-if="selectedView === 'heartbeat'" class="mt-1">
                            Heartbeat checks verify sync, live scoreboard, prediction, model, and odds commands are executing on time.
                        </p>
                        <p v-else class="mt-1">
                            Data validation checks audit freshness, schedule coverage, predictions, Elo, team metrics, and live-update integrity.
                        </p>
                    </div>
                </div>
            </div>
                </div>
            </RenderErrorBoundary>
        </SettingsLayout>
    </AppLayout>
</template>
