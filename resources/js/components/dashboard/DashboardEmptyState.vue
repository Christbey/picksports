<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

const emit = defineEmits<{
    refresh: [];
}>();
</script>

<template>
    <Card class="gap-0 p-6 shadow-none sm:p-10">
        <div class="mx-auto max-w-md py-6 text-center">
            <p class="ps-eyebrow mb-3">The next slate starts here</p>
            <h2 class="text-xl font-semibold tracking-tight">
                Today's Predictions
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                No predictions are available yet. Check back as game slates and
                model runs update.
            </p>
            <div class="mt-6 flex flex-wrap justify-center gap-2">
                <Button as-child variant="outline" class="min-h-11"
                    ><Link href="/performance">View Performance</Link></Button
                >
                <Button class="min-h-11" @click="emit('refresh')"
                    >Refresh</Button
                >
            </div>
        </div>
    </Card>
</template>
