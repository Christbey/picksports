<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Activity,
    ArrowUpRight,
    CalendarDays,
    RefreshCw,
    Target,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { DashboardStats } from '@/types';
import { performance } from '@/routes';

const props = defineProps<{ stats: DashboardStats }>();
defineEmits<{ refresh: [] }>();
const passing = computed(() => props.stats.healthcheck_status === 'passing');
</script>

<template>
    <section aria-labelledby="dashboard-title" class="space-y-6">
        <div
            class="flex flex-col justify-between gap-5 xl:flex-row xl:items-end"
        >
            <div class="max-w-xl">
                <p class="ps-eyebrow mb-3">Your PickSports workspace</p>
                <h1
                    id="dashboard-title"
                    class="text-3xl font-semibold tracking-tight sm:text-4xl"
                >
                    Today, at a glance.
                </h1>
                <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                    Follow the slate, explore your predictions, and keep every
                    pick in perspective.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button as-child variant="outline" class="min-h-11"
                    ><Link :href="performance()"
                        >View performance<ArrowUpRight class="size-4" /></Link
                ></Button>
                <Button
                    variant="outline"
                    class="min-h-11"
                    @click="$emit('refresh')"
                    ><RefreshCw class="size-4" />Refresh</Button
                >
            </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            <Card class="gap-0 py-0 shadow-none"
                ><CardContent class="flex items-start justify-between gap-3 p-5"
                    ><div>
                        <p class="text-sm text-muted-foreground">
                            Predictions today
                        </p>
                        <p
                            class="mt-3 text-3xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ stats.total_predictions_today }}
                        </p>
                    </div>
                    <Target class="mt-1 size-5 text-primary" /></CardContent
            ></Card>
            <Card class="gap-0 py-0 shadow-none"
                ><CardContent class="flex items-start justify-between gap-3 p-5"
                    ><div>
                        <p class="text-sm text-muted-foreground">Games today</p>
                        <p
                            class="mt-3 text-3xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ stats.total_games_today }}
                        </p>
                    </div>
                    <CalendarDays
                        class="mt-1 size-5 text-primary" /></CardContent
            ></Card>
            <Card class="gap-0 py-0 shadow-none"
                ><CardContent class="flex items-start justify-between gap-3 p-5"
                    ><div>
                        <p class="text-sm text-muted-foreground">
                            Healthchecks
                        </p>
                        <Badge
                            variant="outline"
                            class="mt-4 px-2.5 py-1 capitalize"
                            :class="
                                passing
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                    : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300'
                            "
                            >{{ stats.healthcheck_status }}</Badge
                        >
                    </div>
                    <Activity class="mt-1 size-5 text-primary" /></CardContent
            ></Card>
        </div>
    </section>
</template>
