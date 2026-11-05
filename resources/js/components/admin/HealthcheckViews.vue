<script setup lang="ts">
import { useHealthchecksContext } from '@/composables/useHealthchecks';
const { selectedView, filterBySport } = useHealthchecksContext();
</script>
<template>
    <div class="flex gap-2">
        <button
            type="button"
            class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
            :class="
                selectedView === 'heartbeat'
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-sidebar-accent hover:bg-sidebar-accent/80'
            "
            @click="
                selectedView = 'heartbeat';
                filterBySport();
            "
        >
            Heartbeat
        </button>
        <button
            type="button"
            class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
            :class="
                selectedView === 'validation'
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-sidebar-accent hover:bg-sidebar-accent/80'
            "
            @click="
                selectedView = 'validation';
                filterBySport();
            "
        >
            Data Validation
        </button>
    </div>
</template>
