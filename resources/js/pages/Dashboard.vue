<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import DashboardEmptyState from '@/components/dashboard/DashboardEmptyState.vue';
import DashboardLiveStrip from '@/components/dashboard/DashboardLiveStrip.vue';
import DashboardSportSections from '@/components/dashboard/DashboardSportSections.vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { useDashboardPolling } from '@/composables/useDashboardView';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    type BreadcrumbItem,
    type DashboardSport,
    type DashboardStats,
} from '@/types';
import { dashboard } from '@/routes';

const props = defineProps<{
    sports: DashboardSport[];
    stats: DashboardStats;
}>();

const sports = computed(() => props.sports);
const stats = computed(() => props.stats);
const healthStatusClass = computed(() =>
    stats.value.healthcheck_status === 'passing'
        ? 'text-emerald-700 bg-emerald-100 dark:text-emerald-300 dark:bg-emerald-900/30'
        : 'text-red-700 bg-red-100 dark:text-red-300 dark:bg-red-900/30',
);
const { reloadDashboardData } = useDashboardPolling({
    sports,
    reloadOnly: ['sports', 'stats'],
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <RenderErrorBoundary title="Dashboard Render Error">
            <div
                class="flex h-full flex-1 flex-col gap-5 rounded-[1.25rem] p-3 md:p-4"
            >
                <!-- Subscription Banner -->
                <SubscriptionBanner variant="gradient" />

                <DashboardLiveStrip :sports="sports" />

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="ui-surface p-4">
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Predictions Today
                        </p>
                        <p class="mt-1 text-3xl font-semibold tracking-tight">
                            {{ stats.total_predictions_today }}
                        </p>
                    </div>
                    <div class="ui-surface p-4">
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Games Today
                        </p>
                        <p class="mt-1 text-3xl font-semibold tracking-tight">
                            {{ stats.total_games_today }}
                        </p>
                    </div>
                    <div class="ui-surface p-4">
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Healthchecks
                        </p>
                        <p
                            class="mt-2 inline-flex rounded-full px-2.5 py-1 text-sm font-semibold capitalize"
                            :class="healthStatusClass"
                        >
                            {{ stats.healthcheck_status }}
                        </p>
                    </div>
                </div>

                <!-- No Predictions Message -->
                <DashboardEmptyState
                    v-if="sports.length === 0"
                    @refresh="reloadDashboardData()"
                />
                <DashboardSportSections v-else :sports="sports" />
            </div>
        </RenderErrorBoundary>
    </AppLayout>
</template>
