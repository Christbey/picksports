<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
withDefaults(defineProps<{ compact?: boolean }>(), { compact: false });
</script>
<template>
    <Link
        href="/"
        aria-label="PickSports home"
        class="inline-flex shrink-0 items-center gap-2.5"
    >
        <span
            aria-hidden="true"
            class="grid size-9 place-items-center rounded-lg bg-gradient-to-br from-orange-500 to-pink-600 text-xs font-bold tracking-tight text-white"
            >PS</span
        >
        <span
            :class="[
                'text-xl font-bold tracking-tight',
                compact ? 'hidden min-[420px]:inline' : '',
            ]"
            >PickSports<span aria-hidden="true" class="text-primary"
                >.</span
            ></span
        >
    </Link>
</template>
