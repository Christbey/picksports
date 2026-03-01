<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

const emit = defineEmits<{
    refresh: [];
}>();
</script>

<template>
    <div
        class="relative min-h-svh flex-1 rounded-xl border border-sidebar-border/70 bg-white p-6 md:min-h-min dark:border-sidebar-border dark:bg-sidebar"
    >
        <h2 class="mb-4 text-xl font-semibold">Today's Predictions</h2>
        <div class="py-12 text-center">
            <p class="text-muted-foreground">
                No predictions are available yet. Check back as game slates and model runs update.
            </p>
            <div class="mt-4 flex flex-wrap justify-center gap-2">
                <Link
                    href="/performance"
                    class="rounded-md border border-sidebar-border bg-sidebar-accent/40 px-3 py-2 text-sm font-medium hover:bg-sidebar-accent/60"
                >
                    View Performance
                </Link>
                <button
                    type="button"
                    class="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    @click="emit('refresh')"
                >
                    Refresh
                </button>
            </div>
        </div>
    </div>
</template>
