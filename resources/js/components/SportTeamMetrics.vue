<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, computed, watch } from 'vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import SeasonSelect from '@/components/SeasonSelect.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useSeasonFilter } from '@/composables/useSeasonFilter';
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
    availableSeasonsEndpoint?: string;
    seasonTypeOptions?: Array<{ value: string; label: string }>;
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
const selectedSeasonType = ref('');
const sortBy = ref(props.config.defaultSort);
const sortDesc = ref(true);
const tierLimit = ref<number | null>(null);
const tierName = ref<string | null>(null);
const { availableSeasons, selectedSeason, fetchAvailableSeasons } =
    useSeasonFilter(() => {
        return (
            props.config.availableSeasonsEndpoint ??
            `${props.config.apiEndpoint}/available-seasons`
        );
    });

const currentSortOption = computed(() => {
    return props.config.sortOptions.find((o) => o.key === sortBy.value);
});

const activeSortLabel = computed(
    () => currentSortOption.value?.label ?? sortBy.value,
);

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

        const seasonQuery = selectedSeason.value
            ? `?season=${encodeURIComponent(selectedSeason.value)}`
            : '';
        const params = new URLSearchParams(seasonQuery.replace(/^\?/, ''));
        params.set('per_page', '100');
        if (selectedSeasonType.value) {
            params.set('season_type', selectedSeasonType.value);
        }
        const response = await fetch(
            `${props.config.apiEndpoint}?${params.toString()}`,
        );
        if (!response.ok) throw new Error('Failed to fetch team metrics');

        const data = await response.json();
        metrics.value = data.data;
        tierLimit.value = data.meta?.tier?.limit ?? data.tier_limit ?? null;
        tierName.value = data.meta?.tier?.name ?? data.tier_name ?? null;
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

const rankColorClass = (rankIndex: number, total: number): string => {
    if (total <= 2) return '';

    const topCutoff = Math.max(1, Math.floor(total * 0.2));
    const bottomCutoffStart = Math.ceil(total * 0.8);
    const oneBasedRank = rankIndex + 1;

    if (oneBasedRank <= topCutoff) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (oneBasedRank >= bottomCutoffStart) {
        return 'text-rose-600 dark:text-rose-400';
    }

    return '';
};

watch(selectedSeason, () => {
    fetchMetrics();
});

watch(selectedSeasonType, () => {
    fetchMetrics();
});

onMounted(async () => {
    try {
        await fetchAvailableSeasons();
    } catch {
        availableSeasons.value = [];
    }

    if (!selectedSeason.value) {
        await fetchMetrics();
    }
});
</script>

<template>
    <Head :title="config.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <RenderErrorBoundary title="Team Metrics Render Error">
            <div class="flex h-full flex-1 flex-col gap-5 p-3 md:p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="ui-kicker">Rankings</p>
                        <h1 class="text-3xl font-semibold tracking-tight">
                            {{ config.title }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            {{ config.subtitle }}
                        </p>
                    </div>
                </div>

                <SubscriptionBanner
                    variant="subtle"
                    :storage-key="`${config.sport}-metrics-banner-dismissed`"
                />

                <Card>
                    <CardContent class="pt-6">
                        <div class="flex flex-wrap items-end gap-4">
                            <div class="space-y-2">
                                <p class="ui-kicker">Season</p>
                                <SeasonSelect
                                    id="metrics-season"
                                    v-model="selectedSeason"
                                    :options="availableSeasons"
                                />
                            </div>
                            <div
                                v-if="config.seasonTypeOptions?.length"
                                class="space-y-2"
                            >
                                <p class="ui-kicker">Season Type</p>
                                <select
                                    id="metrics-season-type"
                                    v-model="selectedSeasonType"
                                    class="flex h-10 min-w-[180px] rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none"
                                >
                                    <option value="">All</option>
                                    <option
                                        v-for="option in config.seasonTypeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="min-w-[200px] flex-1">
                                <Input
                                    v-model="searchQuery"
                                    placeholder="Search by team name..."
                                    class="w-full"
                                />
                            </div>
                            <div class="flex gap-2">
                                <Button
                                    v-for="option in config.sortOptions"
                                    :key="option.key"
                                    :variant="
                                        sortBy === option.key
                                            ? 'default'
                                            : 'outline'
                                    "
                                    size="sm"
                                    class="h-9"
                                    @click="toggleSort(option.key)"
                                >
                                    {{ option.label }}
                                    {{
                                        sortBy === option.key
                                            ? sortDesc
                                                ? '↓'
                                                : '↑'
                                            : ''
                                    }}
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div
                    class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                >
                    <span class="ui-chip text-foreground/80">
                        {{ sortedMetrics.length }} teams
                    </span>
                    <span class="ui-chip text-foreground/80">
                        Sort: {{ activeSortLabel }} {{ sortDesc ? '↓' : '↑' }}
                    </span>
                    <span
                        v-if="selectedSeason"
                        class="ui-chip text-foreground/80"
                    >
                        Season: {{ selectedSeason }}
                    </span>
                    <span
                        v-if="selectedSeasonType"
                        class="ui-chip text-foreground/80"
                    >
                        Season Type:
                        {{
                            config.seasonTypeOptions?.find(
                                (option) => option.value === selectedSeasonType,
                            )?.label ?? selectedSeasonType
                        }}
                    </span>
                    <span v-if="searchQuery" class="ui-chip text-foreground/80">
                        Search: "{{ searchQuery }}"
                    </span>
                </div>

                <Alert v-if="error" variant="destructive">
                    <AlertDescription>{{ error }}</AlertDescription>
                </Alert>

                <div v-if="loading" class="space-y-2">
                    <Skeleton v-for="i in 10" :key="i" class="h-12 w-full" />
                </div>

                <Card v-else>
                    <CardHeader>
                        <div class="ui-kicker">Standings</div>
                        <CardTitle class="tracking-tight"
                            >Team Rankings</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <div class="ui-table-wrap">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/35 text-left">
                                        <th class="p-2 font-medium">#</th>
                                        <th class="p-2 font-medium">Team</th>
                                        <th
                                            v-for="col in config.columns"
                                            :key="col.label"
                                            class="p-2 text-right font-medium"
                                        >
                                            {{ col.label }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(metric, index) in sortedMetrics"
                                        :key="metric.id"
                                        class="border-b transition-colors odd:bg-muted/15 hover:bg-muted/40"
                                        :class="{
                                            'opacity-60':
                                                config.hasMeetsMinimum &&
                                                !metric.meets_minimum,
                                        }"
                                    >
                                        <td class="p-2 text-muted-foreground">
                                            <span
                                                class="font-medium"
                                                :class="
                                                    rankColorClass(
                                                        index,
                                                        sortedMetrics.length,
                                                    )
                                                "
                                            >
                                                {{ index + 1 }}
                                            </span>
                                        </td>
                                        <td
                                            class="p-2 font-medium"
                                            :class="
                                                rankColorClass(
                                                    index,
                                                    sortedMetrics.length,
                                                )
                                            "
                                        >
                                            <Link
                                                v-if="metric.team?.id != null"
                                                :href="
                                                    config.teamLink(
                                                        metric.team.id,
                                                    )
                                                "
                                                class="flex items-center gap-2 transition-colors hover:text-primary"
                                            >
                                                <span>{{
                                                    metric.team.display_name ??
                                                    metric.team.name
                                                }}</span>
                                                <span
                                                    class="text-xs text-muted-foreground"
                                                    >({{
                                                        metric.team
                                                            .abbreviation ??
                                                        '-'
                                                    }})</span
                                                >
                                            </Link>
                                            <span v-else>-</span>
                                        </td>
                                        <td
                                            v-for="col in config.columns"
                                            :key="col.label"
                                            class="p-2 text-right"
                                            :class="
                                                col.class
                                                    ? col.class(metric)
                                                    : ''
                                            "
                                        >
                                            {{ col.value(metric) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <Alert
                            v-if="tierLimit && metrics.length >= tierLimit"
                            class="mt-4"
                        >
                            <AlertDescription>
                                You're viewing the top {{ tierLimit }} teams
                                with your {{ tierName }} plan.
                                <a
                                    href="/settings/subscription"
                                    class="font-medium underline"
                                    >Upgrade your plan</a
                                >
                                to see more rankings.
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                </Card>
            </div>
        </RenderErrorBoundary>
    </AppLayout>
</template>
