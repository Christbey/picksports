<script setup lang="ts">
import { useHealthchecksContext } from '@/composables/useHealthchecks';
const { selectedView, isRunning, runHealthChecks } = useHealthchecksContext();
</script>
<template>
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
            class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
        >
            {{
                isRunning
                    ? 'Running Checks...'
                    : `Run ${selectedView === 'validation' ? 'Validation' : 'Heartbeat'} Checks`
            }}
        </button>
    </div>
</template>
