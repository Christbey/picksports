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
import MLBTeamController from '@/actions/App/Http/Controllers/MLB/TeamController';

interface TeamMetric {
    id: number;
    team_id: number;
    season: number;
    offensive_rating: number;
    pitching_rating: number;
    defensive_rating: number;
    runs_per_game: number;
    runs_allowed_per_game: number;
    batting_average: number;
    team_era: number;
    strength_of_schedule: number;
    team: {
        id: number;
        name: string;
        location: string;
        abbreviation: string;
        display_name: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'MLB Team Metrics',
        href: '/mlb-team-metrics',
    },
];

const metrics = ref<TeamMetric[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const searchQuery = ref('');
const sortBy = ref<'offensive_rating' | 'pitching_rating' | 'runs_per_game'>('offensive_rating');
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
            case 'offensive_rating':
                aVal = a.offensive_rating;
                bVal = b.offensive_rating;
                break;
            case 'pitching_rating':
                aVal = a.pitching_rating;
                bVal = b.pitching_rating;
                break;
            case 'runs_per_game':
                aVal = a.runs_per_game;
                bVal = b.runs_per_game;
                break;
        }

        return sortDesc.value ? bVal - aVal : aVal - bVal;
    });
    return sorted;
});

const fetchMetrics = async () => {
    try {
        loading.value = true;
        error.value = null;

        const response = await fetch('/api/v1/mlb/team-metrics');
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
        sortDesc.value = true;
    }
};

const formatNumber = (value: number | string | null, decimals = 1): string => {
    if (value === null || value === undefined) return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return '-';
    return num.toFixed(decimals);
};

const formatBattingAverage = (value: number | string | null): string => {
    if (value === null || value === undefined) return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return '-';
    return num.toFixed(3).replace(/^0/, '');
};

const getRatingClass = (value: number | null, isEra = false): string => {
    if (value === null) return '';
    if (isEra) {
        // For ERA, lower is better
        if (value < 3.5) return 'text-green-600 dark:text-green-400 font-semibold';
        if (value < 4.0) return 'text-green-600 dark:text-green-400';
        if (value > 5.0) return 'text-red-600 dark:text-red-400 font-semibold';
        if (value > 4.5) return 'text-red-600 dark:text-red-400';
    } else {
        // For runs per game, higher is better
        if (value > 5) return 'text-green-600 dark:text-green-400 font-semibold';
        if (value > 4.5) return 'text-green-600 dark:text-green-400';
        if (value < 3.5) return 'text-red-600 dark:text-red-400 font-semibold';
        if (value < 4) return 'text-red-600 dark:text-red-400';
    }
    return '';
};

onMounted(() => {
    fetchMetrics();
});
</script>

<template>
    <Head title="MLB Team Metrics" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">MLB Team Metrics</h1>
                    <p class="text-sm text-muted-foreground">
                        Advanced metrics for MLB teams
                    </p>
                </div>
            </div>

            <SubscriptionBanner variant="subtle" storage-key="mlb-metrics-banner-dismissed" />

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
                                :variant="sortBy === 'offensive_rating' ? 'default' : 'outline'"
                                size="sm"
                                @click="toggleSort('offensive_rating')"
                            >
                                Offense {{ sortBy === 'offensive_rating' ? (sortDesc ? '↓' : '↑') : '' }}
                            </Button>
                            <Button
                                :variant="sortBy === 'pitching_rating' ? 'default' : 'outline'"
                                size="sm"
                                @click="toggleSort('pitching_rating')"
                            >
                                Pitching {{ sortBy === 'pitching_rating' ? (sortDesc ? '↓' : '↑') : '' }}
                            </Button>
                            <Button
                                :variant="sortBy === 'runs_per_game' ? 'default' : 'outline'"
                                size="sm"
                                @click="toggleSort('runs_per_game')"
                            >
                                R/G {{ sortBy === 'runs_per_game' ? (sortDesc ? '↓' : '↑') : '' }}
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
                                    <th class="p-2 font-medium text-right">R/G</th>
                                    <th class="p-2 font-medium text-right">RA/G</th>
                                    <th class="p-2 font-medium text-right">AVG</th>
                                    <th class="p-2 font-medium text-right">ERA</th>
                                    <th class="p-2 font-medium text-right">ORtg</th>
                                    <th class="p-2 font-medium text-right">PRtg</th>
                                    <th class="p-2 font-medium text-right">SOS</th>
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
                                            :href="MLBTeamController(metric.team.id)"
                                            class="flex items-center gap-2 hover:text-primary transition-colors"
                                        >
                                            <span>{{ metric.team.display_name }}</span>
                                            <span class="text-xs text-muted-foreground">({{ metric.team.abbreviation }})</span>
                                        </Link>
                                    </td>
                                    <td class="p-2 text-right" :class="getRatingClass(metric.runs_per_game)">
                                        {{ formatNumber(metric.runs_per_game, 2) }}
                                    </td>
                                    <td class="p-2 text-right" :class="getRatingClass(metric.runs_allowed_per_game, true)">
                                        {{ formatNumber(metric.runs_allowed_per_game, 2) }}
                                    </td>
                                    <td class="p-2 text-right">
                                        {{ formatBattingAverage(metric.batting_average) }}
                                    </td>
                                    <td class="p-2 text-right" :class="getRatingClass(metric.team_era, true)">
                                        {{ formatNumber(metric.team_era, 2) }}
                                    </td>
                                    <td class="p-2 text-right text-muted-foreground">
                                        {{ formatNumber(metric.offensive_rating) }}
                                    </td>
                                    <td class="p-2 text-right text-muted-foreground">
                                        {{ formatNumber(metric.pitching_rating) }}
                                    </td>
                                    <td class="p-2 text-right text-muted-foreground">
                                        {{ formatNumber(metric.strength_of_schedule, 3) }}
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
