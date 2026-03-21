<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import DashboardEmptyState from '@/components/dashboard/DashboardEmptyState.vue';
import DashboardLiveStrip from '@/components/dashboard/DashboardLiveStrip.vue';
import DashboardSportSections from '@/components/dashboard/DashboardSportSections.vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useDashboardPolling } from '@/composables/useDashboardView';
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

                <Alert
                    class="overflow-hidden border-primary/30 bg-gradient-to-r from-primary/16 via-primary/8 to-transparent shadow-[0_20px_45px_-30px_hsl(var(--primary)/0.45)]"
                >
                    <AlertDescription
                        class="flex flex-col gap-4 p-1 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p
                                class="text-xs font-semibold tracking-[0.24em] text-primary/80 uppercase"
                            >
                                March Madness
                            </p>
                            <p
                                class="mt-2 text-lg leading-tight font-semibold text-foreground sm:text-xl"
                            >
                                Click here to complete your 2026 March Madness
                                Bracket.
                            </p>
                        </div>
                        <Link
                            href="/march-madness-bracket"
                            class="inline-flex items-center justify-center rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                        >
                            Open bracket
                        </Link>
                    </AlertDescription>
                </Alert>

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
