<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { type BreadcrumbItem } from '@/types';
import WNBATeamController from '@/actions/App/Http/Controllers/WNBA/TeamController';

interface TeamMetric {
    id: number;
    team_id: number;
    season: number;
    offensive_rating: number;
    defensive_rating: number;
    net_rating: number;
    pace: number;
    effective_field_goal_percentage: number;
    turnover_percentage: number;
    offensive_rebound_percentage: number;
    free_throw_rate: number;
    true_shooting_percentage: number;
    team: {
        id: number;
        name: string;
        location: string;
        abbreviation: string;
        display_name: string;
    };
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'WNBA Team Metrics',
        href: '/wnba-team-metrics',
    },
];

const metrics = ref<TeamMetric[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const searchQuery = ref('');
const sortBy = ref<'net_rating' | 'offensive_rating' | 'defensive_rating' | 'true_shooting_percentage'>('net_rating');
const sortDesc = ref(true);
const tierLimit = ref<number | null>(null);
const tierName = ref<string | null>(null);

const filteredMetrics = computed(() => {
    if (!searchQuery.value) {
        return metrics.value;
    }
    const query = searchQuery.value.toLowerCase();
    return metrics.value.filter(
        (m) =>
            m.team.display_name.toLowerCase().includes(query) ||
            m.team.abbreviation.toLowerCase().includes(query) ||
            m.team.location.toLowerCase().includes(query)
    );
});

const sortedMetrics = computed(() => {
    const sorted = [...filteredMetrics.value];
    sorted.sort((a, b) => {
        let aVal: number = 0;
        let bVal: number = 0;

        switch (sortBy.value) {
            case 'net_rating':
                aVal = a.net_rating;
                bVal = b.net_rating;
                break;
            case 'offensive_rating':
                aVal = a.offensive_rating;
                bVal = b.offensive_rating;
                break;
            case 'defensive_rating':
                aVal = a.defensive_rating;
                bVal = b.defensive_rating;
                break;
            case 'true_shooting_percentage':
                aVal = a.true_shooting_percentage;
                bVal = b.true_shooting_percentage;
                break;
        }

        // For defensive rating, lower is better
        if (sortBy.value === 'defensive_rating') {
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

        const response = await fetch('/api/v1/wnba/team-metrics');
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

const toggleSort = (column: typeof sortBy.value) => {
    if (sortBy.value === column) {
        sortDesc.value = !sortDesc.value;
    } else {
        sortBy.value = column;
        sortDesc.value = column === 'defensive_rating' ? false : true;
    }
};

const formatNumber = (value: number | string | null, decimals = 1): string => {
    if (value === null || value === undefined) return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return '-';
    return num.toFixed(decimals);
};

const formatPercent = (value: number | string | null): string => {
    if (value === null || value === undefined) return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return '-';
    return `${num.toFixed(1)}%`;
};

const getRatingClass = (value: number | null): string => {
    if (value === null) return '';
    if (value > 5) return 'text-green-600 dark:text-green-400 font-semibold';
    if (value > 0) return 'text-green-600 dark:text-green-400';
    if (value < -5) return 'text-red-600 dark:text-red-400 font-semibold';
    if (value < 0) return 'text-red-600 dark:text-red-400';
    return '';
};

onMounted(() => {
    fetchMetrics();
});
</script>

<template>
    <Head title="WNBA Team Metrics" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">WNBA Team Metrics</h1>
                    <p class="text-sm text-muted-foreground">
                        Advanced efficiency metrics for WNBA teams
                    </p>
                </div>
            </div>

            <SubscriptionBanner variant="subtle" storage-key="wnba-metrics-banner-dismissed" />

            <Card>
                <CardContent class="pt-6">
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="flex-1 min-w-[200px]">
                            <Input
                                v-model="searchQuery"
                                placeholder="Search by team name..."
                                class="w-full"
                            />
                        </div>
                        <div class="flex gap-2">
                            <Button
                                :variant="sortBy === 'net_rating' ? 'default' : 'outline'"
                                size="sm"
                                @click="toggleSort('net_rating')"
                            >
                                Net Rating {{ sortBy === 'net_rating' ? (sortDesc ? '↓' : '↑') : '' }}
                            </Button>
                            <Button
                                :variant="sortBy === 'offensive_rating' ? 'default' : 'outline'"
                                size="sm"
                                @click="toggleSort('offensive_rating')"
                            >
                                Offense {{ sortBy === 'offensive_rating' ? (sortDesc ? '↓' : '↑') : '' }}
                            </Button>
                            <Button
                                :variant="sortBy === 'defensive_rating' ? 'default' : 'outline'"
                                size="sm"
                                @click="toggleSort('defensive_rating')"
                            >
                                Defense {{ sortBy === 'defensive_rating' ? (sortDesc ? '↓' : '↑') : '' }}
                            </Button>
                            <Button
                                :variant="sortBy === 'true_shooting_percentage' ? 'default' : 'outline'"
                                size="sm"
                                @click="toggleSort('true_shooting_percentage')"
                            >
                                TS% {{ sortBy === 'true_shooting_percentage' ? (sortDesc ? '↓' : '↑') : '' }}
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
                                    <th class="p-2 font-medium text-right">ORtg</th>
                                    <th class="p-2 font-medium text-right">DRtg</th>
                                    <th class="p-2 font-medium text-right">Net</th>
                                    <th class="p-2 font-medium text-right">Pace</th>
                                    <th class="p-2 font-medium text-right">eFG%</th>
                                    <th class="p-2 font-medium text-right">TO%</th>
                                    <th class="p-2 font-medium text-right">OREB%</th>
                                    <th class="p-2 font-medium text-right">FTR</th>
                                    <th class="p-2 font-medium text-right">TS%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(metric, index) in sortedMetrics"
                                    :key="metric.id"
                                    class="border-b hover:bg-muted/50"
                                >
                                    <td class="p-2 text-muted-foreground">{{ index + 1 }}</td>
                                    <td class="p-2 font-medium">
                                        <Link
                                            :href="WNBATeamController(metric.team.id)"
                                            class="flex items-center gap-2 hover:text-primary transition-colors"
                                        >
                                            <span>{{ metric.team.display_name }}</span>
                                            <span class="text-xs text-muted-foreground">({{ metric.team.abbreviation }})</span>
                                        </Link>
                                    </td>
                                    <td class="p-2 text-right">
                                        {{ formatNumber(metric.offensive_rating) }}
                                    </td>
                                    <td class="p-2 text-right">
                                        {{ formatNumber(metric.defensive_rating) }}
                                    </td>
                                    <td class="p-2 text-right" :class="getRatingClass(metric.net_rating)">
                                        {{ formatNumber(metric.net_rating) }}
                                    </td>
                                    <td class="p-2 text-right text-muted-foreground">
                                        {{ formatNumber(metric.pace) }}
                                    </td>
                                    <td class="p-2 text-right">
                                        {{ formatPercent(metric.effective_field_goal_percentage) }}
                                    </td>
                                    <td class="p-2 text-right">
                                        {{ formatPercent(metric.turnover_percentage) }}
                                    </td>
                                    <td class="p-2 text-right">
                                        {{ formatPercent(metric.offensive_rebound_percentage) }}
                                    </td>
                                    <td class="p-2 text-right">
                                        {{ formatPercent(metric.free_throw_rate) }}
                                    </td>
                                    <td class="p-2 text-right font-medium">
                                        {{ formatPercent(metric.true_shooting_percentage) }}
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
    </AppLayout>
</template>
