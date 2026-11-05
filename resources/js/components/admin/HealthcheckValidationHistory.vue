<script setup lang="ts">
import { useHealthchecksContext } from '@/composables/useHealthchecks';
const {
    selectedView,
    syncingCheck,
    selectValidationRun,
    syncData,
    getSyncLabel,
    getStatusColor,
    formatStatus,
    formatCheckType,
    formatDate,
    formatAbsoluteDate,
    formatMetadataLabel,
    formatMetadataValue,
    recent_validation_runs,
    selected_validation_run,
    validation_trend,
} = useHealthchecksContext();
</script>
<template>
    <div
        v-if="
            selectedView === 'validation' && recent_validation_runs.length > 0
        "
        class="grid gap-6 xl:grid-cols-[320px_minmax(0,1fr)]"
    >
        <div
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <h2 class="text-lg font-semibold">Recent Validation Runs</h2>
            <div class="mt-4 space-y-3">
                <button
                    v-for="run in recent_validation_runs"
                    :key="run.id"
                    type="button"
                    @click="selectValidationRun(run.id)"
                    class="w-full rounded-lg border px-4 py-3 text-left transition-colors"
                    :class="
                        selected_validation_run?.id === run.id
                            ? 'border-primary bg-primary/5'
                            : 'border-sidebar-border bg-sidebar-accent hover:bg-sidebar-accent/80'
                    "
                >
                    <div class="flex items-center justify-between gap-3">
                        <span
                            :class="[
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                getStatusColor(run.status),
                            ]"
                        >
                            {{ formatStatus(run.status) }}
                        </span>
                        <span
                            v-if="run.completed_at"
                            class="text-xs text-muted-foreground"
                        >
                            {{ formatDate(run.completed_at) }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm font-medium">
                        {{
                            run.scope === 'all_sports'
                                ? 'All sports'
                                : run.scope
                                      ?.replace('sport:', '')
                                      ?.toUpperCase()
                        }}
                    </p>
                    <p
                        class="mt-1 text-xs text-muted-foreground"
                        v-if="run.summary"
                    >
                        {{ run.summary.failing ?? 0 }} failing,
                        {{ run.summary.warning ?? 0 }} warning,
                        {{ run.summary.passing ?? 0 }} passing
                    </p>
                </button>
            </div>
        </div>

        <div
            v-if="selected_validation_run"
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <div
                class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between"
            >
                <div>
                    <h2 class="text-lg font-semibold">
                        Validation Run Details
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            selected_validation_run.scope === 'all_sports'
                                ? 'All sports'
                                : selected_validation_run.scope
                                      ?.replace('sport:', '')
                                      ?.toUpperCase()
                        }}
                    </p>
                </div>
                <div
                    class="text-sm text-muted-foreground"
                    v-if="selected_validation_run.completed_at"
                >
                    {{
                        formatAbsoluteDate(selected_validation_run.completed_at)
                    }}
                </div>
            </div>

            <div
                v-if="selected_validation_run.findings.length > 0"
                class="mt-4 space-y-3"
            >
                <div
                    v-for="finding in selected_validation_run.findings"
                    :key="finding.id"
                    class="rounded-lg border border-sidebar-border bg-sidebar-accent p-4"
                >
                    <div
                        class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between"
                    >
                        <div>
                            <div class="flex items-center gap-3">
                                <span
                                    :class="[
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                        getStatusColor(finding.status),
                                    ]"
                                >
                                    {{ formatStatus(finding.status) }}
                                </span>
                                <span class="font-medium">
                                    {{ formatCheckType(finding.check_type) }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm text-muted-foreground">
                                {{ finding.message }}
                            </p>
                        </div>
                        <button
                            v-if="finding.recommended_action"
                            @click="syncData(finding.sport, finding.check_type)"
                            :disabled="
                                syncingCheck ===
                                `${finding.sport}-${finding.check_type}`
                            "
                            class="rounded-md bg-primary px-3 py-1 text-xs font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{
                                syncingCheck ===
                                `${finding.sport}-${finding.check_type}`
                                    ? 'Running...'
                                    : getSyncLabel(finding.check_type)
                            }}
                        </button>
                    </div>

                    <div
                        class="mt-3 flex flex-wrap gap-2 text-xs"
                        v-if="finding.facts"
                    >
                        <span
                            v-for="(value, key) in finding.facts"
                            :key="`${finding.id}-${key}`"
                            class="inline-flex items-center gap-1 rounded bg-white px-2 py-1 text-foreground dark:bg-sidebar"
                        >
                            <span class="font-medium text-muted-foreground">
                                {{ formatMetadataLabel(String(key)) }}
                            </span>
                            <span>{{
                                formatMetadataValue(String(key), value)
                            }}</span>
                        </span>
                    </div>
                </div>
            </div>

            <div
                v-if="validation_trend && validation_trend.points.length > 0"
                class="mt-6 rounded-xl border border-sidebar-border bg-sidebar-accent p-4"
            >
                <p class="text-sm font-medium">Recent Trend</p>
                <div class="mt-3 space-y-2">
                    <div
                        v-for="point in validation_trend.points"
                        :key="point.id"
                        class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm dark:bg-sidebar"
                    >
                        <span class="text-muted-foreground">
                            {{
                                point.completed_at
                                    ? formatDate(point.completed_at)
                                    : `Run #${point.id}`
                            }}
                        </span>
                        <span class="font-medium">
                            {{ point.failing }} failing /
                            {{ point.warning }} warning /
                            {{ point.passing }} passing
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
