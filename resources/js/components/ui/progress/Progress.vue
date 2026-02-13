<script setup lang="ts">
import { computed, type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        value?: number;
        max?: number;
        class?: HTMLAttributes['class'];
    }>(),
    {
        value: 0,
        max: 100,
    }
);

const percentage = computed(() => {
    if (props.max === 0) return 0;
    return Math.min(Math.max((props.value / props.max) * 100, 0), 100);
});
</script>

<template>
    <div
        role="progressbar"
        :aria-valuenow="value"
        :aria-valuemin="0"
        :aria-valuemax="max"
        :class="
            cn(
                'relative h-2 w-full overflow-hidden rounded-full bg-sidebar-border/50',
                props.class
            )
        "
    >
        <div
            class="h-full bg-blue-600 transition-all duration-300 ease-in-out"
            :style="{ width: `${percentage}%` }"
        />
    </div>
</template>
