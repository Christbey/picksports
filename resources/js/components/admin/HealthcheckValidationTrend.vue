<script setup lang="ts">
import { useHealthchecksContext } from '@/composables/useHealthchecks';
const { selectedView, formatDelta, validation_trend } =
    useHealthchecksContext();
</script>
<template>
    <div
        v-if="selectedView === 'validation' && validation_trend"
        class="grid gap-4 md:grid-cols-4"
    >
        <div
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <p class="text-sm font-medium text-muted-foreground">Trend</p>
            <p class="mt-2 text-2xl font-bold capitalize">
                {{ validation_trend.direction }}
            </p>
        </div>
        <div
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <p class="text-sm font-medium text-muted-foreground">
                Failing Delta
            </p>
            <p class="mt-2 text-2xl font-bold">
                {{ formatDelta(validation_trend.delta.failing) }}
            </p>
        </div>
        <div
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <p class="text-sm font-medium text-muted-foreground">
                Warning Delta
            </p>
            <p class="mt-2 text-2xl font-bold">
                {{ formatDelta(validation_trend.delta.warning) }}
            </p>
        </div>
        <div
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <p class="text-sm font-medium text-muted-foreground">
                Passing Delta
            </p>
            <p class="mt-2 text-2xl font-bold">
                {{ formatDelta(validation_trend.delta.passing) }}
            </p>
        </div>
    </div>
</template>
