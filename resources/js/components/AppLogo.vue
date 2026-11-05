<script setup lang="ts">
import { usePickSportsTheme } from '@/composables/usePickSportsTheme';
const picksports = usePickSportsTheme();
</script>

<template>
    <div class="flex items-center gap-2">
        <div
            class="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-orange-500 to-pink-600"
        >
            <span class="text-sm font-bold text-white">PS</span>
        </div>
        <component
            :is="picksports ? 'span' : 'h1'"
            class="text-xl font-bold tracking-tight group-data-[collapsible=icon]:hidden"
            :class="
                picksports ? 'text-foreground' : 'text-gray-900 dark:text-white'
            "
        >
            PickSports<span v-if="picksports" class="text-primary">.</span>
        </component>
    </div>
</template>
