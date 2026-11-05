<script setup lang="ts">
import { useHealthchecksContext } from '@/composables/useHealthchecks';
const { selectedView, formatDate, labelize, latest_validation_run } =
    useHealthchecksContext();
</script>
<template>
    <div
        v-if="
            selectedView === 'validation' && latest_validation_run?.ai_summary
        "
        class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
    >
        <div
            class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
        >
            <div>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-muted-foreground uppercase"
                >
                    Validation Summary
                </p>
                <h2 class="mt-2 text-xl font-semibold">
                    {{ latest_validation_run.ai_summary.headline }}
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ latest_validation_run.ai_summary.intro }}
                </p>
                <p
                    v-if="latest_validation_run.ai_summary.latest_data_fresh_at"
                    class="mt-3 text-sm font-medium"
                >
                    {{ latest_validation_run.ai_summary.latest_data_fresh_at }}
                </p>
                <p
                    v-if="latest_validation_run.ai_summary.operational_status"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{
                        labelize(
                            latest_validation_run.ai_summary.operational_status,
                        )
                    }}
                    <span
                        v-if="
                            latest_validation_run.ai_summary.trust_score !==
                            undefined
                        "
                    >
                        ·
                        {{ latest_validation_run.ai_summary.trust_score }}/100
                        trust
                    </span>
                </p>
            </div>
            <div
                class="text-sm text-muted-foreground"
                v-if="latest_validation_run.completed_at"
            >
                {{ formatDate(latest_validation_run.completed_at) }}
            </div>
        </div>

        <div
            class="mt-4 grid gap-4 md:grid-cols-2"
            v-if="
                latest_validation_run.ai_summary.highlights.length > 0 ||
                (latest_validation_run.ai_summary.data_schedule_today?.length ??
                    0) > 0 ||
                (latest_validation_run.ai_summary.tweak_recommendations
                    ?.length ?? 0) > 0 ||
                (latest_validation_run.ai_summary.blocked_outputs?.length ??
                    0) > 0 ||
                (latest_validation_run.ai_summary.safe_adjustments?.length ??
                    0) > 0 ||
                (latest_validation_run.ai_summary.data_quality_notes?.length ??
                    0) > 0 ||
                (latest_validation_run.ai_summary.recommended_actions?.length ??
                    0) > 0
            "
        >
            <div v-if="latest_validation_run.ai_summary.highlights.length > 0">
                <p class="text-sm font-medium">Highlights</p>
                <ul class="mt-2 space-y-2 text-sm text-muted-foreground">
                    <li
                        v-for="highlight in latest_validation_run.ai_summary
                            .highlights"
                        :key="highlight"
                        class="rounded-lg bg-sidebar-accent px-3 py-2"
                    >
                        {{ highlight }}
                    </li>
                </ul>
            </div>

            <div
                v-if="
                    (latest_validation_run.ai_summary.recommended_actions
                        ?.length ?? 0) > 0
                "
            >
                <p class="text-sm font-medium">Recommended Actions</p>
                <ul class="mt-2 space-y-2 text-sm text-muted-foreground">
                    <li
                        v-for="action in latest_validation_run.ai_summary
                            .recommended_actions ?? []"
                        :key="action"
                        class="rounded-lg border border-sidebar-border bg-white px-3 py-2 font-mono dark:bg-sidebar"
                    >
                        {{ action }}
                    </li>
                </ul>
            </div>

            <div
                v-if="
                    (latest_validation_run.ai_summary.blocked_outputs?.length ??
                        0) > 0
                "
            >
                <p class="text-sm font-medium">Blocked Outputs</p>
                <ul class="mt-2 space-y-2 text-sm text-muted-foreground">
                    <li
                        v-for="output in latest_validation_run.ai_summary
                            .blocked_outputs ?? []"
                        :key="output"
                        class="rounded-lg border border-sidebar-border bg-white px-3 py-2 dark:bg-sidebar"
                    >
                        {{ output }}
                    </li>
                </ul>
            </div>

            <div
                v-if="
                    (latest_validation_run.ai_summary.safe_adjustments
                        ?.length ?? 0) > 0
                "
            >
                <p class="text-sm font-medium">Safe Adjustments</p>
                <ul class="mt-2 space-y-2 text-sm text-muted-foreground">
                    <li
                        v-for="adjustment in latest_validation_run.ai_summary
                            .safe_adjustments ?? []"
                        :key="adjustment"
                        class="rounded-lg border border-sidebar-border bg-white px-3 py-2 font-mono dark:bg-sidebar"
                    >
                        {{ adjustment }}
                    </li>
                </ul>
            </div>

            <div
                v-if="
                    (latest_validation_run.ai_summary.data_schedule_today
                        ?.length ?? 0) > 0
                "
            >
                <p class="text-sm font-medium">Today's Data Schedule</p>
                <ul class="mt-2 space-y-2 text-sm text-muted-foreground">
                    <li
                        v-for="scheduleItem in latest_validation_run.ai_summary
                            .data_schedule_today ?? []"
                        :key="scheduleItem"
                        class="rounded-lg bg-sidebar-accent px-3 py-2"
                    >
                        {{ scheduleItem }}
                    </li>
                </ul>
            </div>

            <div
                v-if="
                    (latest_validation_run.ai_summary.tweak_recommendations
                        ?.length ?? 0) > 0
                "
            >
                <p class="text-sm font-medium">Tweak Recommendations</p>
                <ul class="mt-2 space-y-2 text-sm text-muted-foreground">
                    <li
                        v-for="recommendation in latest_validation_run
                            .ai_summary.tweak_recommendations ?? []"
                        :key="recommendation"
                        class="rounded-lg border border-sidebar-border bg-white px-3 py-2 dark:bg-sidebar"
                    >
                        {{ recommendation }}
                    </li>
                </ul>
            </div>

            <div
                v-if="
                    (latest_validation_run.ai_summary.data_quality_notes
                        ?.length ?? 0) > 0
                "
            >
                <p class="text-sm font-medium">Data Quality Notes</p>
                <ul class="mt-2 space-y-2 text-sm text-muted-foreground">
                    <li
                        v-for="note in latest_validation_run.ai_summary
                            .data_quality_notes ?? []"
                        :key="note"
                        class="rounded-lg bg-sidebar-accent px-3 py-2"
                    >
                        {{ note }}
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
