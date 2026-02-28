<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        :class="className"
        v-bind="$attrs"
    >
        <defs>
            <linearGradient id="ps-gradient" x1="4" y1="4" x2="28" y2="28" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#f97316" />
                <stop offset="1" stop-color="#db2777" />
            </linearGradient>
        </defs>
        <rect x="2" y="2" width="28" height="28" rx="7" fill="url(#ps-gradient)" />
        <text
            x="16"
            y="20"
            text-anchor="middle"
            fill="#ffffff"
            font-size="11"
            font-weight="700"
            font-family="system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif"
        >
            PS
        </text>
    </svg>
</template>
