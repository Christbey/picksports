<script setup lang="ts">
import { useHealthchecksContext } from '@/composables/useHealthchecks';
const { labelize, percent, widthPercent, countWidth, ai_publishing_trend } =
    useHealthchecksContext();
</script>
<template>
    <div
        class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
    >
        <div
            class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
        >
            <div>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-muted-foreground uppercase"
                >
                    Guardrail Trend
                </p>
                <h2 class="mt-2 text-xl font-semibold">
                    {{ percent(ai_publishing_trend.changed_rate) }}
                    would change over
                    {{ ai_publishing_trend.days }} days
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ ai_publishing_trend.changed_count }} of
                    {{ ai_publishing_trend.total }} reviewed picks differed from
                    saved classification.
                </p>
            </div>
            <div
                v-if="Object.keys(ai_publishing_trend.decisions).length > 0"
                class="flex flex-wrap gap-2"
            >
                <span
                    v-for="(count, decision) in ai_publishing_trend.decisions"
                    :key="decision"
                    class="rounded-full border border-sidebar-border px-3 py-1 text-xs font-medium"
                >
                    {{ labelize(String(decision)) }}:
                    {{ count }}
                </span>
            </div>
        </div>

        <p
            v-if="ai_publishing_trend.total === 0"
            class="mt-4 rounded-lg border border-sidebar-border bg-sidebar-accent px-4 py-3 text-sm text-muted-foreground"
        >
            No guardrail trend data found for this sport in the last
            {{ ai_publishing_trend.days }} days.
        </p>
        <div v-else class="mt-5 grid gap-4 md:grid-cols-2">
            <div>
                <div
                    class="mb-2 flex items-center justify-between text-xs font-medium text-muted-foreground"
                >
                    <span>Classification Change Rate</span>
                    <span>
                        {{ percent(ai_publishing_trend.changed_rate) }}
                    </span>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-sidebar-accent">
                    <div
                        class="h-full rounded-full bg-yellow-500"
                        :style="{
                            width: widthPercent(
                                ai_publishing_trend.changed_rate,
                            ),
                        }"
                    />
                </div>
            </div>

            <div
                v-if="Object.keys(ai_publishing_trend.decisions).length > 0"
                class="space-y-2"
            >
                <div
                    v-for="(count, decision) in ai_publishing_trend.decisions"
                    :key="`bar-${decision}`"
                >
                    <div
                        class="mb-1 flex items-center justify-between text-xs font-medium text-muted-foreground"
                    >
                        <span>{{ labelize(String(decision)) }}</span>
                        <span>{{ count }}</span>
                    </div>
                    <div
                        class="h-2 overflow-hidden rounded-full bg-sidebar-accent"
                    >
                        <div
                            class="h-full rounded-full bg-primary"
                            :style="{
                                width: countWidth(
                                    Number(count),
                                    ai_publishing_trend.total,
                                ),
                            }"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="
                ai_publishing_trend.total > 0 &&
                ai_publishing_trend.changed_rows.length > 0
            "
            class="mt-4 overflow-hidden rounded-lg border border-sidebar-border"
        >
            <div
                class="grid grid-cols-6 gap-3 bg-sidebar-accent px-4 py-2 text-xs font-semibold text-muted-foreground"
            >
                <span>Date</span>
                <span>Sport</span>
                <span class="col-span-2">Matchup</span>
                <span>Saved</span>
                <span>Guardrail</span>
            </div>
            <div
                v-for="row in ai_publishing_trend.changed_rows"
                :key="`${row.date}-${row.sport}-${row.matchup}-${row.decision}`"
                class="grid grid-cols-6 gap-3 border-t border-sidebar-border px-4 py-3 text-sm"
            >
                <span class="text-muted-foreground">
                    {{ row.date }}
                </span>
                <span>{{ row.sport }}</span>
                <span class="col-span-2">
                    {{ row.matchup }}
                </span>
                <span>
                    {{ labelize(row.saved_classification) }}
                </span>
                <span>
                    {{ labelize(row.guardrail_classification) }}
                </span>
            </div>
        </div>
        <p
            v-else-if="ai_publishing_trend.total > 0"
            class="mt-4 rounded-lg bg-sidebar-accent px-4 py-3 text-sm text-muted-foreground"
        >
            No guardrail classification changes found for this filter in the
            last
            {{ ai_publishing_trend.days }} days.
        </p>
    </div>
</template>
