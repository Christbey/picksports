<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { provide } from 'vue';
import { Activity, Database, RefreshCw } from 'lucide-vue-next';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { labelize } from '@/lib/nflMonitoringPresentation';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import NflEvidenceLeaderboard from '@/components/nfl/NflEvidenceLeaderboard.vue';
import type { BreadcrumbItem } from '@/types';
import type { NflModelMonitoringProps } from '@/types/nfl-model-monitoring';
import {
    useNflModelMonitoring,
    nflMonitoringKey,
} from '@/composables/useNflModelMonitoring';
import NflMonitoringOverview from '@/components/admin/NflMonitoringOverview.vue';
import NflMonitoringObservations from '@/components/admin/NflMonitoringObservations.vue';
import NflMonitoringEvaluation from '@/components/admin/NflMonitoringEvaluation.vue';
import NflMonitoringSignals from '@/components/admin/NflMonitoringSignals.vue';
import NflMonitoringLineage from '@/components/admin/NflMonitoringLineage.vue';
const props = defineProps<NflModelMonitoringProps>();
const context = useNflModelMonitoring(props);
provide(nflMonitoringKey, context);
const {
    selectedArtifact,
    refreshing,
    loadSignalGrades,
    changeArtifact,
    refresh,
} = context;
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/settings/admin' },
    { title: 'NFL Model Monitor', href: '/admin/nfl-model-monitoring' },
];
</script>

<template>
    <Head title="NFL Model Monitor" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout full-width>
            <div class="space-y-6">
                <header
                    class="flex flex-col gap-4 border-b pb-5 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <Activity class="h-5 w-5 text-sky-600" />
                            <h1 class="text-2xl font-bold">
                                NFL Model Monitor
                            </h1>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Shadow inference, decision, and settlement ledger
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="sr-only" for="artifact-selector">
                            Model artifact
                        </label>
                        <select
                            id="artifact-selector"
                            v-model="selectedArtifact"
                            class="h-9 min-w-0 rounded-md border border-input bg-background px-3 text-sm sm:min-w-80"
                            @change="changeArtifact"
                        >
                            <option
                                v-for="option in artifacts"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.model_version }} ·
                                {{ labelize(option.status) }}
                            </option>
                        </select>

                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border bg-background transition-colors hover:bg-muted disabled:opacity-50"
                                        aria-label="Refresh model monitor"
                                        :disabled="refreshing"
                                        @click="refresh"
                                    >
                                        <RefreshCw
                                            class="h-4 w-4"
                                            :class="{
                                                'animate-spin': refreshing,
                                            }"
                                        />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>Refresh</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </header>

                <div
                    v-if="report_status"
                    class="rounded-md border p-4 text-sm"
                    role="status"
                >
                    <p>{{ report_status.message }}</p>
                    <p v-if="report_status.generated_at">
                        Report generated: {{ report_status.generated_at
                        }}<span v-if="report_status.state === 'stale'">
                            · Refresh delayed; showing saved results.</span
                        >
                    </p>
                </div>
                <NflEvidenceLeaderboard
                    v-if="evidence_leaderboard"
                    :report="evidence_leaderboard"
                    @scope="
                        (data) =>
                            router.reload({
                                data,
                                only: ['evidence_leaderboard', 'report_status'],
                            })
                    "
                />

                <div
                    v-if="!artifact"
                    class="rounded-md border border-dashed px-6 py-14 text-center"
                >
                    <Database class="mx-auto h-8 w-8 text-muted-foreground" />
                    <p class="mt-3 font-semibold">
                        {{
                            report_status?.state === 'pending'
                                ? 'Report is being prepared; model availability has not been checked yet.'
                                : 'No NFL model artifacts registered'
                        }}
                    </p>
                </div>

                <template v-else>
                    <NflMonitoringOverview />

                    <Tabs
                        default-value="observations"
                        class="space-y-4"
                        @update:model-value="loadSignalGrades"
                    >
                        <TabsList
                            class="grid h-auto w-full grid-cols-4 sm:w-auto"
                        >
                            <TabsTrigger value="observations">
                                Observations
                            </TabsTrigger>
                            <TabsTrigger value="evaluation">
                                Evaluation
                            </TabsTrigger>
                            <TabsTrigger value="signals">
                                Signal Grades
                            </TabsTrigger>
                            <TabsTrigger value="lineage">Lineage</TabsTrigger>
                        </TabsList>

                        <NflMonitoringObservations />

                        <NflMonitoringEvaluation />

                        <NflMonitoringSignals />

                        <NflMonitoringLineage />
                    </Tabs>
                </template>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
