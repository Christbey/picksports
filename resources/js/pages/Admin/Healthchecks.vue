<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { provide } from 'vue';
import {
    useHealthchecks,
    healthchecksKey,
    type HealthchecksProps,
} from '@/composables/useHealthchecks';
import HealthcheckHeader from '@/components/admin/HealthcheckHeader.vue';
import HealthcheckViews from '@/components/admin/HealthcheckViews.vue';
import HealthcheckSummary from '@/components/admin/HealthcheckSummary.vue';
import HealthcheckFilters from '@/components/admin/HealthcheckFilters.vue';
import HealthcheckValidationSummary from '@/components/admin/HealthcheckValidationSummary.vue';
import HealthcheckPublishing from '@/components/admin/HealthcheckPublishing.vue';
import HealthcheckPublishingTrend from '@/components/admin/HealthcheckPublishingTrend.vue';
import HealthcheckValidationTrend from '@/components/admin/HealthcheckValidationTrend.vue';
import HealthcheckValidationHistory from '@/components/admin/HealthcheckValidationHistory.vue';
import HealthcheckChecks from '@/components/admin/HealthcheckChecks.vue';
const props = defineProps<HealthchecksProps>();
const state = useHealthchecks(props);
provide(healthchecksKey, state);
const { breadcrumbs, selectedView } = state;
</script>
<template>
    <Head title="Health Checks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :full-width="true">
            <RenderErrorBoundary title="Health Checks Render Error">
                <div
                    class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
                >
                    <HealthcheckHeader />

                    <HealthcheckViews />

                    <!-- Status Summary -->
                    <HealthcheckSummary />

                    <!-- Filter -->
                    <HealthcheckFilters />

                    <HealthcheckValidationSummary />

                    <HealthcheckPublishing />

                    <HealthcheckPublishingTrend />

                    <HealthcheckValidationTrend />

                    <HealthcheckValidationHistory />

                    <!-- Health Checks by Sport -->
                    <div
                        v-if="Object.keys(checks_by_sport).length === 0"
                        class="rounded-xl border border-sidebar-border bg-white p-8 text-center dark:bg-sidebar"
                    >
                        <p class="text-muted-foreground">
                            No health checks found. Run
                            <code class="rounded bg-sidebar-accent px-2 py-1"
                                >php artisan healthcheck:run</code
                            >
                            to generate checks.
                        </p>
                    </div>

                    <HealthcheckChecks v-else />

                    <!-- Info Box -->
                    <div
                        class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950"
                    >
                        <div class="flex gap-3">
                            <svg
                                class="h-5 w-5 flex-shrink-0 text-blue-600 dark:text-blue-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                            <div
                                class="text-sm text-blue-900 dark:text-blue-100"
                            >
                                <p class="font-medium">
                                    How Health Checks Work
                                </p>
                                <p
                                    v-if="selectedView === 'heartbeat'"
                                    class="mt-1"
                                >
                                    Heartbeat checks verify sync, live
                                    scoreboard, prediction, model, and odds
                                    commands are executing on time.
                                </p>
                                <p v-else class="mt-1">
                                    Data validation checks audit freshness,
                                    schedule coverage, predictions, Elo, team
                                    metrics, and live-update integrity.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </RenderErrorBoundary>
        </SettingsLayout>
    </AppLayout>
</template>
