<script setup lang="ts">
import { useHealthchecksContext } from '@/composables/useHealthchecks';
const {
    syncingCheck,
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
    getTeamScheduleOutliers,
    getNoGameOutliers,
    getScheduleOutliers,
    displayMessage,
    metadataEntries,
    checks_by_sport,
} = useHealthchecksContext();
</script>
<template>
    <div class="space-y-6">
        <div
            v-for="(checks, sport) in checks_by_sport"
            :key="sport"
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <h2 class="mb-4 text-lg font-semibold">
                {{ formatSportName(sport) }}
            </h2>

            <div class="space-y-3">
                <div
                    v-for="check in checks"
                    :key="check.id"
                    class="flex items-start justify-between rounded-lg border border-sidebar-border bg-sidebar-accent p-4"
                >
                    <div class="flex-1">
                        <div class="flex items-center gap-3">
                            <span
                                :class="[
                                    'inline-flex items-center justify-center rounded-full px-3 py-1 text-sm font-medium',
                                    getStatusColor(check.status),
                                ]"
                            >
                                <span class="mr-1">{{
                                    getStatusIcon(check.status)
                                }}</span>
                                {{ formatStatus(check.status) }}
                            </span>
                            <h3 class="font-semibold">
                                {{ formatCheckType(check.check_type) }}
                            </h3>
                        </div>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{ displayMessage(check.message) }}
                        </p>

                        <!-- Team Schedules Metadata -->
                        <div
                            v-if="
                                check.check_type === 'team_schedules' &&
                                check.metadata
                            "
                            class="mt-3 space-y-2"
                        >
                            <div
                                class="flex flex-wrap gap-2 text-xs text-muted-foreground"
                            >
                                <span
                                    v-if="check.metadata.total_teams"
                                    class="rounded bg-white px-2 py-1 dark:bg-sidebar"
                                >
                                    Total Teams:
                                    {{ check.metadata.total_teams }}
                                </span>
                                <span
                                    v-if="check.metadata.teams_with_games"
                                    class="rounded bg-white px-2 py-1 dark:bg-sidebar"
                                >
                                    Teams with Games:
                                    {{ check.metadata.teams_with_games }}
                                </span>
                                <span
                                    v-if="check.metadata.average_games"
                                    class="rounded bg-white px-2 py-1 dark:bg-sidebar"
                                >
                                    Avg Games:
                                    {{
                                        Math.round(
                                            check.metadata.average_games * 10,
                                        ) / 10
                                    }}
                                </span>
                                <span
                                    v-if="check.metadata.std_dev"
                                    class="rounded bg-white px-2 py-1 dark:bg-sidebar"
                                >
                                    Std Dev:
                                    {{
                                        Math.round(
                                            check.metadata.std_dev * 10,
                                        ) / 10
                                    }}
                                </span>
                            </div>

                            <!-- Teams with no games and outliers -->
                            <div
                                v-if="
                                    getTeamScheduleOutliers(check.metadata)
                                        .length > 0
                                "
                                class="mt-2 space-y-2"
                            >
                                <!-- Teams with no games -->
                                <div
                                    v-if="
                                        getNoGameOutliers(check.metadata)
                                            .length > 0
                                    "
                                >
                                    <p
                                        class="text-xs font-medium text-red-600 dark:text-red-400"
                                    >
                                        Teams with no games ({{
                                            getNoGameOutliers(check.metadata)
                                                .length
                                        }}):
                                    </p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span
                                            v-for="team in getNoGameOutliers(
                                                check.metadata,
                                            )"
                                            :key="team.espn_id"
                                            class="rounded bg-red-100 px-2 py-1 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-300"
                                        >
                                            {{ team.team }} (#{{
                                                team.espn_id
                                            }})
                                        </span>
                                    </div>
                                </div>

                                <!-- Schedule outliers (too many/too few games) -->
                                <div
                                    v-if="
                                        getScheduleOutliers(check.metadata)
                                            .length > 0
                                    "
                                >
                                    <p
                                        class="text-xs font-medium text-yellow-600 dark:text-yellow-400"
                                    >
                                        Schedule outliers ({{
                                            getScheduleOutliers(check.metadata)
                                                .length
                                        }}):
                                    </p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span
                                            v-for="outlier in getScheduleOutliers(
                                                check.metadata,
                                            )"
                                            :key="outlier.espn_id"
                                            class="rounded bg-yellow-100 px-2 py-1 text-xs text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300"
                                            :title="`${outlier.type}: ${Math.round((outlier.deviation_from_avg ?? 0) * 10) / 10} deviation from avg`"
                                        >
                                            {{ outlier.team }}
                                            ({{ outlier.games }}
                                            games)
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Standard Metadata -->
                        <div v-else-if="check.metadata" class="mt-3 space-y-2">
                            <div class="flex flex-wrap gap-2 text-xs">
                                <template
                                    v-for="entry in metadataEntries(check)"
                                    :key="entry.key"
                                >
                                    <span
                                        v-if="
                                            entry.key !== 'command_patterns' &&
                                            entry.key !== 'last_failure_error'
                                        "
                                        class="inline-flex items-center gap-1 rounded bg-white px-2 py-1 text-foreground dark:bg-sidebar"
                                    >
                                        <span
                                            class="font-medium text-muted-foreground"
                                            >{{ entry.label }}</span
                                        >
                                        <span>{{ entry.value }}</span>
                                    </span>
                                </template>
                            </div>

                            <template
                                v-for="entry in metadataEntries(check)"
                                :key="`${entry.key}-patterns-wrap`"
                            >
                                <div
                                    v-if="entry.key === 'command_patterns'"
                                    :key="`${entry.key}-patterns`"
                                    class="space-y-1"
                                >
                                    <p
                                        class="text-xs font-medium text-muted-foreground"
                                    >
                                        {{ entry.label }}
                                    </p>
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="pattern in Array.isArray(
                                                entry.raw,
                                            )
                                                ? entry.raw
                                                : [entry.value]"
                                            :key="String(pattern)"
                                            class="rounded border border-sidebar-border bg-white px-2 py-1 font-mono text-[11px] text-foreground dark:bg-sidebar"
                                        >
                                            {{
                                                String(pattern).replace(
                                                    /%+$/g,
                                                    '',
                                                )
                                            }}
                                        </span>
                                    </div>
                                </div>
                            </template>

                            <template
                                v-for="entry in metadataEntries(check)"
                                :key="`${entry.key}-error-wrap`"
                            >
                                <div
                                    v-if="entry.key === 'last_failure_error'"
                                    :key="`${entry.key}-error`"
                                    class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300"
                                >
                                    <p class="mb-1 font-medium">
                                        {{ entry.label }}
                                    </p>
                                    <p class="font-mono leading-relaxed">
                                        {{ entry.value }}
                                    </p>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div class="ml-4 flex flex-col items-end gap-2">
                        <div
                            class="cursor-help text-sm text-muted-foreground"
                            :title="formatAbsoluteDate(check.checked_at)"
                        >
                            {{ formatDate(check.checked_at) }}
                        </div>
                        <button
                            v-if="
                                canSync(check.sport, check.check_type) ||
                                !!check.metadata?.recommended_action
                            "
                            @click="syncData(check.sport, check.check_type)"
                            :disabled="
                                syncingCheck ===
                                `${check.sport}-${check.check_type}`
                            "
                            class="rounded-md bg-primary px-3 py-1 text-xs font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{
                                syncingCheck ===
                                `${check.sport}-${check.check_type}`
                                    ? 'Syncing...'
                                    : getSyncLabel(check.check_type)
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
