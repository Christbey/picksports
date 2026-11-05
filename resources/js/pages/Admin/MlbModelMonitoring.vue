<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { provide } from 'vue';
import {
    useMlbMonitoring,
    mlbMonitoringKey,
    type MlbMonitoringProps,
} from '@/composables/useMlbMonitoring';
import MlbMonitoringHeader from '@/components/admin/MlbMonitoringHeader.vue';
import MlbMonitoringArtifact from '@/components/admin/MlbMonitoringArtifact.vue';
import MlbMonitoringHealth from '@/components/admin/MlbMonitoringHealth.vue';
import MlbMonitoringSummary from '@/components/admin/MlbMonitoringSummary.vue';
import MlbMonitoringLive from '@/components/admin/MlbMonitoringLive.vue';
import MlbMonitoringWeekly from '@/components/admin/MlbMonitoringWeekly.vue';
import MlbMonitoringMarkets from '@/components/admin/MlbMonitoringMarkets.vue';
import MlbMonitoringFailures from '@/components/admin/MlbMonitoringFailures.vue';
import MlbMonitoringLineage from '@/components/admin/MlbMonitoringLineage.vue';
const props = defineProps<MlbMonitoringProps>();
const state = useMlbMonitoring(props);
provide(mlbMonitoringKey, state);
const { breadcrumbs } = state;
</script>
<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="MLB Model Monitor" />

        <SettingsLayout full-width>
            <div class="min-w-0 space-y-6">
                <MlbMonitoringHeader />

                <MlbMonitoringArtifact />

                <MlbMonitoringHealth />

                <MlbMonitoringSummary />

                <Tabs default-value="live" class="space-y-4">
                    <TabsList class="grid h-auto w-full grid-cols-5">
                        <TabsTrigger value="live">Live</TabsTrigger>
                        <TabsTrigger value="weekly">Weekly</TabsTrigger>
                        <TabsTrigger value="markets">Markets</TabsTrigger>
                        <TabsTrigger value="failures">Failures</TabsTrigger>
                        <TabsTrigger value="lineage">Lineage</TabsTrigger>
                    </TabsList>

                    <MlbMonitoringLive />

                    <MlbMonitoringWeekly />

                    <MlbMonitoringMarkets />

                    <MlbMonitoringFailures />

                    <MlbMonitoringLineage />
                </Tabs>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
