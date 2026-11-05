<script setup lang="ts">
import { useTeamMappingsContext } from '@/composables/useTeamMappings';
const { entityTitle, mappingPercentage, mappings, stats } =
    useTeamMappingsContext();
</script>
<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <div class="rounded-lg border p-4">
            <div class="text-2xl font-bold">{{ stats.total }}</div>
            <div class="text-sm text-muted-foreground">
                Total {{ entityTitle }}s
            </div>
        </div>
        <div class="rounded-lg border bg-green-50 p-4 dark:bg-green-950">
            <div class="text-2xl font-bold text-green-700 dark:text-green-300">
                {{ stats.mapped }}
            </div>
            <div class="text-sm text-green-600 dark:text-green-400">
                Mapped ({{ mappingPercentage }}%)
            </div>
        </div>
        <div class="rounded-lg border bg-yellow-50 p-4 dark:bg-yellow-950">
            <div
                class="text-2xl font-bold text-yellow-700 dark:text-yellow-300"
            >
                {{ stats.unmapped }}
            </div>
            <div class="text-sm text-yellow-600 dark:text-yellow-400">
                Unmapped
            </div>
        </div>
        <div class="rounded-lg border p-4">
            <div class="text-2xl font-bold">
                {{ mappings.total }}
            </div>
            <div class="text-sm text-muted-foreground">Showing</div>
        </div>
    </div>
</template>
