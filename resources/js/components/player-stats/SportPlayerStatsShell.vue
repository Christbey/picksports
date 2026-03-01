<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type HrefLike = string | Record<string, unknown>;

interface PlayerLeaderboardEntry {
    player_id: number;
    player: {
        id: number;
        full_name: string;
        headshot_url: string | null;
        position: string | null;
        jersey_number: string | null;
        team: {
            id: number;
            name: string;
            display_name: string;
            abbreviation: string;
        } | null;
    } | null;
    games_played: number;
    points_per_game: number;
    rebounds_per_game: number;
    assists_per_game: number;
    steals_per_game: number;
    blocks_per_game: number;
    minutes_per_game: number;
    field_goal_percentage: number;
    three_point_percentage: number;
    free_throw_percentage: number;
    estimated_epa_per_game?: number;
    estimated_epa_per_36?: number;
}

interface SportPlayerStatsShellConfig {
    pageTitle: string;
    heading: string;
    description: string;
    breadcrumb: BreadcrumbItem;
    bannerStorageKey: string;
    leaderboardEndpoint: string;
    showEpaColumns?: boolean;
    playerLink?: (id: number) => HrefLike;
    teamLink?: (id: number) => HrefLike;
}

const props = defineProps<{
    config: SportPlayerStatsShellConfig;
}>();

const players = ref<PlayerLeaderboardEntry[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const searchQuery = ref('');
const sortBy = ref('points_per_game');
const sortDesc = ref(true);

const sortOptions = computed(() => {
    const options = [
        { key: 'points_per_game', label: 'PPG' },
        { key: 'rebounds_per_game', label: 'RPG' },
        { key: 'assists_per_game', label: 'APG' },
    ];

    if (props.config.showEpaColumns) {
        options.push(
            { key: 'estimated_epa_per_game', label: 'EPA/G' },
            { key: 'estimated_epa_per_36', label: 'EPA/36' },
        );
    }

    return options;
});

const filteredPlayers = computed(() => {
    if (!searchQuery.value) {
        return players.value;
    }

    const query = searchQuery.value.toLowerCase();
    return players.value.filter(
        (p) =>
            p.player?.full_name?.toLowerCase().includes(query) ||
            p.player?.team?.display_name?.toLowerCase().includes(query) ||
            p.player?.team?.abbreviation?.toLowerCase().includes(query),
    );
});

const sortedPlayers = computed(() => {
    const sorted = [...filteredPlayers.value];
    sorted.sort((a, b) => {
        const aVal = (a as any)[sortBy.value] ?? 0;
        const bVal = (b as any)[sortBy.value] ?? 0;
        return sortDesc.value ? bVal - aVal : aVal - bVal;
    });
    return sorted;
});

const fetchPlayers = async () => {
    try {
        loading.value = true;
        error.value = null;

        const response = await fetch(props.config.leaderboardEndpoint);
        if (!response.ok) {
            throw new Error('Failed to fetch player stats');
        }

        const data = await response.json();
        players.value = data.data;
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred';
    } finally {
        loading.value = false;
    }
};

const toggleSort = (key: string) => {
    if (sortBy.value === key) {
        sortDesc.value = !sortDesc.value;
        return;
    }

    sortBy.value = key;
    sortDesc.value = true;
};

const formatEpa = (value: number | undefined) => {
    return Number(value ?? 0).toFixed(2);
};

onMounted(() => {
    fetchPlayers();
});
</script>

<template>
    <Head :title="config.pageTitle" />

    <AppLayout :breadcrumbs="[config.breadcrumb]">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">{{ config.heading }}</h1>
                    <p class="text-sm text-muted-foreground">{{ config.description }}</p>
                </div>
            </div>

            <SubscriptionBanner variant="subtle" :storage-key="config.bannerStorageKey" />

            <Card>
                <CardContent class="pt-6">
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="min-w-[200px] flex-1">
                            <Input v-model="searchQuery" placeholder="Search by player or team name..." class="w-full" />
                        </div>
                        <div class="flex gap-2">
                            <Button
                                v-for="option in sortOptions"
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
                    <CardTitle>Player Rankings</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left">
                                    <th class="p-2 font-medium">#</th>
                                    <th class="p-2 font-medium">Player</th>
                                    <th class="p-2 text-right font-medium">Team</th>
                                    <th class="p-2 text-right font-medium">GP</th>
                                    <th class="p-2 text-right font-medium">PPG</th>
                                    <th class="p-2 text-right font-medium">RPG</th>
                                    <th class="p-2 text-right font-medium">APG</th>
                                    <th class="hidden p-2 text-right font-medium md:table-cell">SPG</th>
                                    <th class="hidden p-2 text-right font-medium md:table-cell">BPG</th>
                                    <th class="hidden p-2 text-right font-medium lg:table-cell">FG%</th>
                                    <th class="hidden p-2 text-right font-medium lg:table-cell">3P%</th>
                                    <th class="hidden p-2 text-right font-medium lg:table-cell">FT%</th>
                                    <th class="hidden p-2 text-right font-medium lg:table-cell">MPG</th>
                                    <th
                                        v-if="config.showEpaColumns"
                                        class="hidden p-2 text-right font-medium lg:table-cell"
                                    >
                                        EPA/G
                                    </th>
                                    <th
                                        v-if="config.showEpaColumns"
                                        class="hidden p-2 text-right font-medium lg:table-cell"
                                    >
                                        EPA/36
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(entry, index) in sortedPlayers"
                                    :key="entry.player_id"
                                    class="border-b hover:bg-muted/50"
                                >
                                    <td class="p-2 text-muted-foreground">{{ index + 1 }}</td>
                                    <td class="p-2 font-medium">
                                        <Link
                                            v-if="entry.player && config.playerLink"
                                            :href="config.playerLink(entry.player.id)"
                                            class="flex items-center gap-2 transition-colors hover:text-primary"
                                        >
                                            <img
                                                v-if="entry.player.headshot_url"
                                                :src="entry.player.headshot_url"
                                                :alt="entry.player.full_name"
                                                class="h-8 w-8 rounded-full object-cover"
                                            />
                                            <div
                                                v-else
                                                class="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-xs font-bold text-muted-foreground"
                                            >
                                                {{ entry.player.full_name?.charAt(0) }}
                                            </div>
                                            <span>{{ entry.player.full_name }}</span>
                                        </Link>
                                        <div v-else-if="entry.player" class="flex items-center gap-2">
                                            <img
                                                v-if="entry.player.headshot_url"
                                                :src="entry.player.headshot_url"
                                                :alt="entry.player.full_name"
                                                class="h-8 w-8 rounded-full object-cover"
                                            />
                                            <div
                                                v-else
                                                class="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-xs font-bold text-muted-foreground"
                                            >
                                                {{ entry.player.full_name?.charAt(0) }}
                                            </div>
                                            <span>{{ entry.player.full_name }}</span>
                                        </div>
                                    </td>
                                    <td class="p-2 text-right text-muted-foreground">
                                        <Link
                                            v-if="entry.player?.team && config.teamLink"
                                            :href="config.teamLink(entry.player.team.id)"
                                            class="transition-colors hover:text-primary"
                                        >
                                            {{ entry.player.team.abbreviation }}
                                        </Link>
                                        <span v-else>{{ entry.player?.team?.abbreviation ?? '-' }}</span>
                                    </td>
                                    <td class="p-2 text-right text-muted-foreground">{{ entry.games_played }}</td>
                                    <td class="p-2 text-right font-medium">{{ entry.points_per_game }}</td>
                                    <td class="p-2 text-right">{{ entry.rebounds_per_game }}</td>
                                    <td class="p-2 text-right">{{ entry.assists_per_game }}</td>
                                    <td class="hidden p-2 text-right md:table-cell">{{ entry.steals_per_game }}</td>
                                    <td class="hidden p-2 text-right md:table-cell">{{ entry.blocks_per_game }}</td>
                                    <td class="hidden p-2 text-right lg:table-cell">{{ entry.field_goal_percentage }}%</td>
                                    <td class="hidden p-2 text-right lg:table-cell">{{ entry.three_point_percentage }}%</td>
                                    <td class="hidden p-2 text-right lg:table-cell">{{ entry.free_throw_percentage }}%</td>
                                    <td class="hidden p-2 text-right text-muted-foreground lg:table-cell">{{ entry.minutes_per_game }}</td>
                                    <td
                                        v-if="config.showEpaColumns"
                                        class="hidden p-2 text-right font-medium lg:table-cell"
                                    >
                                        {{ formatEpa(entry.estimated_epa_per_game) }}
                                    </td>
                                    <td
                                        v-if="config.showEpaColumns"
                                        class="hidden p-2 text-right lg:table-cell"
                                    >
                                        {{ formatEpa(entry.estimated_epa_per_36) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="sortedPlayers.length === 0 && !loading" class="py-8 text-center text-muted-foreground">
                        <p v-if="searchQuery">No players found matching "{{ searchQuery }}"</p>
                        <p v-else>No player stats available.</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
