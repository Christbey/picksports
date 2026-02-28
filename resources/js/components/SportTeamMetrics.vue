<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, computed } from 'vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

export interface SortOption {
    key: string;
    label: string;
    getValue: (metric: any) => number | null;
    lowerIsBetter?: boolean;
}

export interface Column {
    label: string;
    value: (metric: any) => string;
    class?: (metric: any) => string;
}

export interface MetricsConfig {
    sport: string;
    title: string;
    subtitle: string;
    apiEndpoint: string;
    breadcrumbHref: string;
    teamLink: (teamId: number) => string;
    sortOptions: SortOption[];
    defaultSort: string;
    columns: Column[];
    hasMeetsMinimum?: boolean;
}

const props = defineProps<{
    config: MetricsConfig;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: props.config.title,
        href: props.config.breadcrumbHref,
    },
];

const metrics = ref<any[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const searchQuery = ref('');
const sortBy = ref(props.config.defaultSort);
const sortDesc = ref(true);
const tierLimit = ref<number | null>(null);
const tierName = ref<string | null>(null);

const currentSortOption = computed(() => {
    return props.config.sortOptions.find((o) => o.key === sortBy.value);
});

const filteredMetrics = computed(() => {
    if (!searchQuery.value) {
        return metrics.value;
    }
    const query = searchQuery.value.toLowerCase();
    return metrics.value.filter(
        (m) =>
            m.team?.display_name?.toLowerCase().includes(query) ||
            m.team?.abbreviation?.toLowerCase().includes(query) ||
            m.team?.location?.toLowerCase().includes(query),
    );
});

const sortedMetrics = computed(() => {
    const sorted = [...filteredMetrics.value];
    const option = currentSortOption.value;
    if (!option) return sorted;

    sorted.sort((a, b) => {
        const aVal = option.getValue(a);
        const bVal = option.getValue(b);

        if (aVal === null || aVal === undefined) return 1;
        if (bVal === null || bVal === undefined) return -1;

        if (option.lowerIsBetter) {
            return sortDesc.value ? aVal - bVal : bVal - aVal;
        }

        return sortDesc.value ? bVal - aVal : aVal - bVal;
    });
    return sorted;
});

const fetchMetrics = async () => {
    try {
        loading.value = true;
        error.value = null;

        const response = await fetch(props.config.apiEndpoint);
        if (!response.ok) throw new Error('Failed to fetch team metrics');

        const data = await response.json();
        metrics.value = data.data;
        tierLimit.value = data.tier_limit;
        tierName.value = data.tier_name;
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred';
    } finally {
        loading.value = false;
    }
};

const toggleSort = (key: string) => {
    const option = props.config.sortOptions.find((o) => o.key === key);
    if (sortBy.value === key) {
        sortDesc.value = !sortDesc.value;
    } else {
        sortBy.value = key;
        sortDesc.value = option?.lowerIsBetter ? false : true;
    }
};

onMounted(() => {
    fetchMetrics();
});
</script>

<template>
    <Head :title="config.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <RenderErrorBoundary title="Team Metrics Render Error">
            <div class="flex h-full flex-1 flex-col gap-4 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">{{ config.title }}</h1>
                        <p class="text-sm text-muted-foreground">
                            {{ config.subtitle }}
                        </p>
                    </div>
                </div>

                <SubscriptionBanner variant="subtle" :storage-key="`${config.sport}-metrics-banner-dismissed`" />

                <Card>
                    <CardContent class="pt-6">
                        <div class="flex flex-wrap items-end gap-4">
                            <div class="min-w-[200px] flex-1">
                                <Input v-model="searchQuery" placeholder="Search by team name..." class="w-full" />
                            </div>
                            <div class="flex gap-2">
                                <Button
                                    v-for="option in config.sortOptions"
                                    :key="option.key"
                                    :variant="sortBy === option.key ? 'default' : 'outline'"
                                    size="sm"
                                    @click="toggleSort(option.key)"
                                >
                                    {{ option.label }}
                                    {{ sortBy === option.key ? (sortDesc ? '↓' : '↑') : '' }}
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Alert v-if="error" variant="destructive">
                    <AlertDescription>{{ error }}</AlertDescription>
                </Alert>

                <div v-if="loading" class="space-y-2">
                    <Skeleton v-for="i in 10" :key="i" class="h-12 w-full" />
                </div>

                <Card v-else>
                    <CardHeader>
                        <CardTitle>Team Rankings</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left">
                                        <th class="p-2 font-medium">#</th>
                                        <th class="p-2 font-medium">Team</th>
                                        <th v-for="col in config.columns" :key="col.label" class="p-2 text-right font-medium">
                                            {{ col.label }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(metric, index) in sortedMetrics"
                                        :key="metric.id"
                                        class="border-b hover:bg-muted/50"
                                        :class="{ 'opacity-60': config.hasMeetsMinimum && !metric.meets_minimum }"
                                    >
                                        <td class="p-2 text-muted-foreground">{{ index + 1 }}</td>
                                        <td class="p-2 font-medium">
                                            <Link
                                                :href="config.teamLink(metric.team.id)"
                                                class="flex items-center gap-2 transition-colors hover:text-primary"
                                            >
                                                <span>{{ metric.team.display_name }}</span>
                                                <span class="text-xs text-muted-foreground">({{ metric.team.abbreviation }})</span>
                                            </Link>
                                        </td>
                                        <td
                                            v-for="col in config.columns"
                                            :key="col.label"
                                            class="p-2 text-right"
                                            :class="col.class ? col.class(metric) : ''"
                                        >
                                            {{ col.value(metric) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <Alert v-if="tierLimit && metrics.length >= tierLimit" class="mt-4">
                            <AlertDescription>
                                You're viewing the top {{ tierLimit }} teams with your {{ tierName }} plan.
                                <a href="/settings/subscription" class="font-medium underline">Upgrade your plan</a> to see more rankings.
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                </Card>
            </div>
        </RenderErrorBoundary>
    </AppLayout>
</template>
