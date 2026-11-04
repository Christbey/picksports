<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useMlbSignals } from '@/composables/useMlbSignals';
import MlbSignalsBoard from './MlbSignalsBoard.vue';
const props = defineProps<{ season?: string | number | null }>();
const { loading, error, payload } = useMlbSignals(props);
</script>

<template>
    <section class="space-y-3">
        <div v-if="loading" class="rounded-lg border bg-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-2">
                    <Skeleton class="h-5 w-36" />
                    <Skeleton class="h-3 w-72 max-w-full" />
                </div>
                <Skeleton class="h-8 w-32" />
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <Skeleton v-for="i in 3" :key="i" class="h-24 w-full" />
            </div>
        </div>

        <Card v-else-if="error" class="border-destructive/40">
            <CardContent class="py-4 text-sm text-destructive">
                {{ error }}
            </CardContent>
        </Card>

        <template v-else>
            <MlbSignalsBoard :payload="payload" />
        </template>
    </section>
</template>
