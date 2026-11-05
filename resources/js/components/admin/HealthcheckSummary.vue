<script setup lang="ts">
import { useHealthchecksContext } from '@/composables/useHealthchecks';
const { getStatusColor, getStatusIcon, overallStatus, status_counts } =
    useHealthchecksContext();
</script>
<template>
    <div class="grid gap-4 md:grid-cols-4">
        <div
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-muted-foreground">
                        Overall Status
                    </p>
                    <p
                        :class="[
                            'mt-2 text-2xl font-bold capitalize',
                            getStatusColor(overallStatus),
                        ]"
                    >
                        {{ overallStatus }}
                    </p>
                </div>
                <div
                    :class="[
                        'flex h-12 w-12 items-center justify-center rounded-full text-2xl',
                        getStatusColor(overallStatus),
                    ]"
                >
                    {{ getStatusIcon(overallStatus) }}
                </div>
            </div>
        </div>

        <div
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <p class="text-sm font-medium text-muted-foreground">Passing</p>
            <p
                class="mt-2 text-2xl font-bold text-green-600 dark:text-green-400"
            >
                {{ status_counts.passing || 0 }}
            </p>
        </div>

        <div
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <p class="text-sm font-medium text-muted-foreground">Warning</p>
            <p
                class="mt-2 text-2xl font-bold text-yellow-600 dark:text-yellow-400"
            >
                {{ status_counts.warning || 0 }}
            </p>
        </div>

        <div
            class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
        >
            <p class="text-sm font-medium text-muted-foreground">Failing</p>
            <p class="mt-2 text-2xl font-bold text-red-600 dark:text-red-400">
                {{ status_counts.failing || 0 }}
            </p>
        </div>
    </div>
</template>
