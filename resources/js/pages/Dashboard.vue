<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import DashboardEmptyState from '@/components/dashboard/DashboardEmptyState.vue';
import DashboardSportSections from '@/components/dashboard/DashboardSportSections.vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import {
    useDashboardPolling,
} from '@/composables/useDashboardView';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import {
    type BreadcrumbItem,
    type DashboardSport,
    type DashboardStats,
} from '@/types';

const props = defineProps<{
    sports: DashboardSport[];
    stats: DashboardStats;
}>();

const sports = computed(() => props.sports);
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
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <!-- Subscription Banner -->
                <SubscriptionBanner variant="gradient" />

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
