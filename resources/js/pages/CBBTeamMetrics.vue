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
import CBBTeamController from '@/actions/App/Http/Controllers/CBB/TeamController';

interface TeamMetric {
    id: number;
    team_id: number;
    season: number;
    offensive_efficiency: number;
    defensive_efficiency: number;
    net_rating: number;
    tempo: number;
    strength_of_schedule: number;
    games_played: number;
    meets_minimum: boolean;
    adj_offensive_efficiency: number | null;
    adj_defensive_efficiency: number | null;
    adj_net_rating: number | null;
    adj_tempo: number | null;
    rolling_offensive_efficiency: number | null;
    rolling_defensive_efficiency: number | null;
    rolling_net_rating: number | null;
    rolling_tempo: number | null;
    rolling_games_count: number | null;
    home_offensive_efficiency: number | null;
    home_defensive_efficiency: number | null;
    away_offensive_efficiency: number | null;
    away_defensive_efficiency: number | null;
    home_games: number | null;
    away_games: number | null;
    team: {
        id: number;
        display_name: string;
        name: string;
        abbreviation: string;
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
        title: 'CBB Team Metrics',
        href: '/cbb-team-metrics',
    },
];

const metrics = ref<TeamMetric[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const searchQuery = ref('');
const sortBy = ref<'net_rating' | 'adj_net_rating' | 'offensive_efficiency' | 'defensive_efficiency'>('adj_net_rating');
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
            m.team.abbreviation.toLowerCase().includes(query)
    );
});

const sortedMetrics = computed(() => {
    const sorted = [...filteredMetrics.value];
    sorted.sort((a, b) => {
        let aVal: number | null = null;
        let bVal: number | null = null;

        switch (sortBy.value) {
            case 'net_rating':
                aVal = a.net_rating;
                bVal = b.net_rating;
                break;
            case 'adj_net_rating':
                aVal = a.adj_net_rating ?? a.net_rating;
                bVal = b.adj_net_rating ?? b.net_rating;
                break;
            case 'offensive_efficiency':
                aVal = a.adj_offensive_efficiency ?? a.offensive_efficiency;
                bVal = b.adj_offensive_efficiency ?? b.offensive_efficiency;
                break;
            case 'defensive_efficiency':
                aVal = a.adj_defensive_efficiency ?? a.defensive_efficiency;
                bVal = b.adj_defensive_efficiency ?? b.defensive_efficiency;
                break;
        }

        if (aVal === null) return 1;
        if (bVal === null) return -1;

        // For defensive efficiency, lower is better
        if (sortBy.value === 'defensive_efficiency') {
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

        const response = await fetch('/api/v1/cbb/team-metrics');
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
        sortDesc.value = column === 'defensive_efficiency' ? false : true;
    }
};

const formatNumber = (value: number | string | null, decimals = 1): string => {
    if (value === null || value === undefined) return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return '-';
    return num.toFixed(decimals);
};

const getRatingClass = (value: number | null): string => {
    if (value === null) return '';
    if (value > 10) return 'text-green-600 dark:text-green-400 font-semibold';
    if (value > 0) return 'text-green-600 dark:text-green-400';
    if (value < -10) return 'text-red-600 dark:text-red-400 font-semibold';
    if (value < 0) return 'text-red-600 dark:text-red-400';
    return '';
};

onMounted(() => {
    fetchMetrics();
});
</script>

<template>
    <Head title="CBB Team Metrics" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">CBB Team Metrics</h1>
                    <p class="text-sm text-muted-foreground">
                        Advanced efficiency metrics for college basketball teams
                    </p>
                </div>
            </div>

            <SubscriptionBanner variant="subtle" storage-key="cbb-metrics-banner-dismissed" />

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
                                :variant="sortBy === 'adj_net_rating' ? 'default' : 'outline'"
                                size="sm"
                                @click="toggleSort('adj_net_rating')"
                            >
                                Net Rating {{ sortBy === 'adj_net_rating' ? (sortDesc ? '↓' : '↑') : '' }}
                            </Button>
                            <Button
                                :variant="sortBy === 'offensive_efficiency' ? 'default' : 'outline'"
                                size="sm"
                                @click="toggleSort('offensive_efficiency')"
                            >
                                Offense {{ sortBy === 'offensive_efficiency' ? (sortDesc ? '↓' : '↑') : '' }}
                            </Button>
                            <Button
                                :variant="sortBy === 'defensive_efficiency' ? 'default' : 'outline'"
                                size="sm"
                                @click="toggleSort('defensive_efficiency')"
                            >
                                Defense {{ sortBy === 'defensive_efficiency' ? (sortDesc ? '↓' : '↑') : '' }}
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
                                    <th class="p-2 font-medium text-right">GP</th>
                                    <th class="p-2 font-medium text-right">AdjO</th>
                                    <th class="p-2 font-medium text-right">AdjD</th>
                                    <th class="p-2 font-medium text-right">AdjNet</th>
                                    <th class="p-2 font-medium text-right">Tempo</th>
                                    <th class="p-2 font-medium text-right">SOS</th>
                                    <th class="p-2 font-medium text-right">L10 Net</th>
                                    <th class="p-2 font-medium text-right">Home</th>
                                    <th class="p-2 font-medium text-right">Away</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(metric, index) in sortedMetrics"
                                    :key="metric.id"
                                    class="border-b hover:bg-muted/50"
                                    :class="{ 'opacity-60': !metric.meets_minimum }"
                                >
                                    <td class="p-2 text-muted-foreground">{{ index + 1 }}</td>
                                    <td class="p-2 font-medium">
                                        <Link
                                            :href="CBBTeamController(metric.team.id)"
                                            class="flex items-center gap-2 hover:text-primary transition-colors"
                                        >
                                            <span>{{ metric.team.display_name }}</span>
                                            <span class="text-xs text-muted-foreground">({{ metric.team.abbreviation }})</span>
                                        </Link>
                                    </td>
                                    <td class="p-2 text-right text-muted-foreground">{{ metric.games_played }}</td>
                                    <td class="p-2 text-right">
                                        {{ formatNumber(metric.adj_offensive_efficiency ?? metric.offensive_efficiency) }}
                                    </td>
                                    <td class="p-2 text-right">
                                        {{ formatNumber(metric.adj_defensive_efficiency ?? metric.defensive_efficiency) }}
                                    </td>
                                    <td class="p-2 text-right" :class="getRatingClass(metric.adj_net_rating ?? metric.net_rating)">
                                        {{ formatNumber(metric.adj_net_rating ?? metric.net_rating) }}
                                    </td>
                                    <td class="p-2 text-right text-muted-foreground">
                                        {{ formatNumber(metric.adj_tempo ?? metric.tempo) }}
                                    </td>
                                    <td class="p-2 text-right text-muted-foreground">
                                        {{ formatNumber(metric.strength_of_schedule, 3) }}
                                    </td>
                                    <td class="p-2 text-right" :class="getRatingClass(metric.rolling_net_rating)">
                                        {{ formatNumber(metric.rolling_net_rating) }}
                                        <span v-if="metric.rolling_games_count" class="text-xs text-muted-foreground">
                                            ({{ metric.rolling_games_count }})
                                        </span>
                                    </td>
                                    <td class="p-2 text-right">
                                        <span v-if="metric.home_offensive_efficiency && metric.home_defensive_efficiency">
                                            {{ formatNumber((metric.home_offensive_efficiency - metric.home_defensive_efficiency)) }}
                                        </span>
                                        <span v-else>-</span>
                                        <span v-if="metric.home_games" class="text-xs text-muted-foreground">
                                            ({{ metric.home_games }})
                                        </span>
                                    </td>
                                    <td class="p-2 text-right">
                                        <span v-if="metric.away_offensive_efficiency && metric.away_defensive_efficiency">
                                            {{ formatNumber((metric.away_offensive_efficiency - metric.away_defensive_efficiency)) }}
                                        </span>
                                        <span v-else>-</span>
                                        <span v-if="metric.away_games" class="text-xs text-muted-foreground">
                                            ({{ metric.away_games }})
                                        </span>
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
