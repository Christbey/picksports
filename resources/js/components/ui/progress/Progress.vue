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

const maximum = computed(() =>
    Number.isFinite(props.max) && props.max > 0 ? props.max : 100,
);
const current = computed(() =>
    Number.isFinite(props.max) && props.max > 0 && Number.isFinite(props.value)
        ? Math.min(Math.max(props.value, 0), maximum.value)
        : undefined,
);
const percentage = computed(() =>
    current.value === undefined ? 0 : (current.value / maximum.value) * 100,
);
</script>

<template>
    <div
        role="progressbar"
        :aria-valuenow="current"
        :aria-valuemin="0"
        :aria-valuemax="maximum"
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
