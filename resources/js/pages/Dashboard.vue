<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import DashboardOverview from '@/components/dashboard/DashboardOverview.vue';
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

    <AppLayout :breadcrumbs="breadcrumbs" picksports>
        <RenderErrorBoundary title="Dashboard Render Error">
            <div
                class="ps-dashboard mx-auto flex w-full max-w-7xl flex-1 flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-8"
            >
                <DashboardOverview
                    :stats="stats"
                    @refresh="reloadDashboardData()"
                />
                <SubscriptionBanner variant="minimal" />
                <DashboardLiveStrip :sports="sports" />

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
