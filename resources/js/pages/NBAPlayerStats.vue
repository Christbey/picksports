<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, computed } from 'vue';
import NBAPlayerController from '@/actions/App/Http/Controllers/NBA/PlayerController';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

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
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NBA Player Stats', href: '/nba-player-stats' },
];

const players = ref<PlayerLeaderboardEntry[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const searchQuery = ref('');
const sortBy = ref('points_per_game');
const sortDesc = ref(true);

const sortOptions = [
    { key: 'points_per_game', label: 'PPG' },
    { key: 'rebounds_per_game', label: 'RPG' },
    { key: 'assists_per_game', label: 'APG' },
];

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

        const response = await fetch('/api/v1/nba/player-stats/leaderboard');
        if (!response.ok) throw new Error('Failed to fetch player stats');

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
    } else {
        sortBy.value = key;
        sortDesc.value = true;
    }
};

onMounted(() => {
    fetchPlayers();
});
</script>

<template>
    <Head title="NBA Player Stats" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">NBA Player Stats</h1>
                    <p class="text-sm text-muted-foreground">Season averages leaderboard for NBA players</p>
                </div>
            </div>

            <SubscriptionBanner variant="subtle" storage-key="nba-player-stats-banner-dismissed" />

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
                                            v-if="entry.player"
                                            :href="NBAPlayerController(entry.player.id)"
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
                                    </td>
                                    <td class="p-2 text-right text-muted-foreground">
                                        {{ entry.player?.team?.abbreviation ?? '-' }}
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
