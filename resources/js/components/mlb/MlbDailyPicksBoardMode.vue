<script setup lang="ts">
import { CircleDot } from 'lucide-vue-next';
</script>
<template>
    <section class="rounded-lg border bg-card p-4 shadow-sm">
        <div class="mb-2 flex items-center gap-2 font-semibold">
            <CircleDot class="h-4 w-4 text-amber-500" />
            Mode
        </div>
        <p class="text-sm leading-6 text-muted-foreground">
            Tracking mode is active. Candidates are evaluated before public pick
            labels are enabled.
        </p>
    </section>
</template>
