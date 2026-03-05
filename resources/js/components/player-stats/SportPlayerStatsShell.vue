<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import SeasonSelect from '@/components/SeasonSelect.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useSeasonFilter } from '@/composables/useSeasonFilter';
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
    passing_yards_per_game?: number;
    passing_touchdowns_per_game?: number;
    completion_percentage?: number;
    interceptions_thrown_per_game?: number;
    rushing_yards_per_game?: number;
    rushing_touchdowns_per_game?: number;
    yards_per_carry?: number;
    receptions_per_game?: number;
    receiving_yards_per_game?: number;
    tackles_per_game?: number;
    sacks_per_game?: number;
    def_interceptions_per_game?: number;
    passes_defended_per_game?: number;
    fumbles_recovered_per_game?: number;
    field_goals_made_per_game?: number;
    extra_points_made_per_game?: number;
    field_goal_percentage_special?: number;
    extra_point_percentage?: number;
}

interface SortOption {
    key: string;
    label: string;
}

interface StatColumn {
    key: string;
    label: string;
    cellClass?: string;
    format?: (
        value: number | undefined,
        entry: PlayerLeaderboardEntry,
    ) => string;
}

interface StatCategoryOption {
    key: string;
    label: string;
    defaultSortBy?: string;
    sortOptions?: SortOption[];
    statColumns?: StatColumn[];
    match?: (entry: PlayerLeaderboardEntry) => boolean;
}

interface SportPlayerStatsShellConfig {
    pageTitle: string;
    heading: string;
    description: string;
    breadcrumb: BreadcrumbItem;
    bannerStorageKey: string;
    leaderboardEndpoint: string;
    availableSeasonsEndpoint?: string;
    showEpaColumns?: boolean;
    sortOptions?: SortOption[];
    statColumns?: StatColumn[];
    statCategoryOptions?: StatCategoryOption[];
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
const selectedCategory = ref<string>('all');
const { availableSeasons, selectedSeason, fetchAvailableSeasons } =
    useSeasonFilter(() => {
        return (
            props.config.availableSeasonsEndpoint ??
            props.config.leaderboardEndpoint.replace(
                /\/leaderboard$/,
                '/available-seasons',
            )
        );
    });

const categoryOptions = computed(() => props.config.statCategoryOptions ?? []);

const activeCategory = computed(
    () =>
        categoryOptions.value.find(
            (category) => category.key === selectedCategory.value,
        ) ?? null,
);

const sortOptions = computed(() => {
    const options = activeCategory.value?.sortOptions ??
        props.config.sortOptions ?? [
            { key: 'points_per_game', label: 'PPG' },
            { key: 'rebounds_per_game', label: 'RPG' },
            { key: 'assists_per_game', label: 'APG' },
        ];

    if (
        !activeCategory.value?.sortOptions &&
        !props.config.sortOptions &&
        props.config.showEpaColumns
    ) {
        options.push(
            { key: 'estimated_epa_per_game', label: 'EPA/G' },
            { key: 'estimated_epa_per_36', label: 'EPA/36' },
        );
    }

    return options;
});

const filteredPlayers = computed(() => {
    const categoryFilteredPlayers = activeCategory.value?.match
        ? players.value.filter(
              (player) => activeCategory.value?.match?.(player) ?? true,
          )
        : players.value;

    if (!searchQuery.value) {
        return categoryFilteredPlayers;
    }

    const query = searchQuery.value.toLowerCase();
    return categoryFilteredPlayers.filter(
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

        const seasonQuery = selectedSeason.value
            ? `?season=${encodeURIComponent(selectedSeason.value)}`
            : '';
        const response = await fetch(
            `${props.config.leaderboardEndpoint}${seasonQuery}`,
        );
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

const defaultStatColumns = computed(() => {
    const columns: NonNullable<SportPlayerStatsShellConfig['statColumns']> = [
        {
            key: 'points_per_game',
            label: 'PPG',
            cellClass: 'p-2 text-right font-medium',
        },
        { key: 'rebounds_per_game', label: 'RPG', cellClass: 'p-2 text-right' },
        { key: 'assists_per_game', label: 'APG', cellClass: 'p-2 text-right' },
        {
            key: 'steals_per_game',
            label: 'SPG',
            cellClass: 'hidden p-2 text-right md:table-cell',
        },
        {
            key: 'blocks_per_game',
            label: 'BPG',
            cellClass: 'hidden p-2 text-right md:table-cell',
        },
        {
            key: 'field_goal_percentage',
            label: 'FG%',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (value) => `${Number(value ?? 0).toFixed(1)}%`,
        },
        {
            key: 'three_point_percentage',
            label: '3P%',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (value) => `${Number(value ?? 0).toFixed(1)}%`,
        },
        {
            key: 'free_throw_percentage',
            label: 'FT%',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (value) => `${Number(value ?? 0).toFixed(1)}%`,
        },
        {
            key: 'minutes_per_game',
            label: 'MPG',
            cellClass:
                'hidden p-2 text-right text-muted-foreground lg:table-cell',
        },
    ];

    if (props.config.showEpaColumns) {
        columns.push(
            {
                key: 'estimated_epa_per_game',
                label: 'EPA/G',
                cellClass: 'hidden p-2 text-right font-medium lg:table-cell',
                format: (value) => formatEpa(value),
            },
            {
                key: 'estimated_epa_per_36',
                label: 'EPA/36',
                cellClass: 'hidden p-2 text-right lg:table-cell',
                format: (value) => formatEpa(value),
            },
        );
    }

    return columns;
});

const statColumns = computed(
    () =>
        activeCategory.value?.statColumns ??
        props.config.statColumns ??
        defaultStatColumns.value,
);

const formatColumnValue = (
    entry: PlayerLeaderboardEntry,
    column: StatColumn,
) => {
    const value = (entry as Record<string, number | undefined>)[column.key];
    if (column.format) return column.format(value, entry);
    return Number(value ?? 0).toFixed(1);
};

onMounted(() => {
    if (categoryOptions.value.length > 0) {
        selectedCategory.value = categoryOptions.value[0]?.key ?? 'all';
    }

    fetchAvailableSeasons()
        .catch(() => {
            availableSeasons.value = [];
        })
        .then(() => {
            if (!selectedSeason.value) {
                fetchPlayers();
            }
        });
});

watch(selectedSeason, () => {
    fetchPlayers();
});

watch(activeCategory, (category) => {
    const categorySort = category?.defaultSortBy ?? sortOptions.value[0]?.key;
    if (categorySort) {
        sortBy.value = categorySort;
        sortDesc.value = true;
    }
});
</script>

<template>
    <Head :title="config.pageTitle" />

    <AppLayout :breadcrumbs="[config.breadcrumb]">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">{{ config.heading }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ config.description }}
                    </p>
                </div>
            </div>

            <SubscriptionBanner
                variant="subtle"
                :storage-key="config.bannerStorageKey"
            />

            <Card>
                <CardContent class="pt-6">
                    <div class="flex flex-wrap items-end gap-4">
                        <SeasonSelect
                            id="player-stats-season"
                            v-model="selectedSeason"
                            :options="availableSeasons"
                        />
                        <div class="min-w-[200px] flex-1">
                            <Input
                                v-model="searchQuery"
                                placeholder="Search by player or team name..."
                                class="w-full"
                            />
                        </div>
                        <div
                            v-if="categoryOptions.length > 0"
                            class="flex flex-wrap gap-2"
                        >
                            <Button
                                v-for="category in categoryOptions"
                                :key="category.key"
                                :variant="
                                    selectedCategory === category.key
                                        ? 'default'
                                        : 'outline'
                                "
                                size="sm"
                                @click="selectedCategory = category.key"
                            >
                                {{ category.label }}
                            </Button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="option in sortOptions"
                                :key="option.key"
                                :variant="
                                    sortBy === option.key
                                        ? 'default'
                                        : 'outline'
                                "
                                size="sm"
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
                                    <th class="p-2 text-right font-medium">
                                        Team
                                    </th>
                                    <th class="p-2 text-right font-medium">
                                        GP
                                    </th>
                                    <th
                                        v-for="column in statColumns"
                                        :key="column.key as string"
                                        class="p-2 text-right font-medium"
                                        :class="
                                            column.cellClass?.replace('p-2', '')
                                        "
                                    >
                                        {{ column.label }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(entry, index) in sortedPlayers"
                                    :key="entry.player_id"
                                    class="border-b hover:bg-muted/50"
                                >
                                    <td class="p-2 text-muted-foreground">
                                        {{ index + 1 }}
                                    </td>
                                    <td class="p-2 font-medium">
                                        <Link
                                            v-if="
                                                entry.player &&
                                                config.playerLink
                                            "
                                            :href="
                                                config.playerLink(
                                                    entry.player.id,
                                                )
                                            "
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
                                                {{
                                                    entry.player.full_name?.charAt(
                                                        0,
                                                    )
                                                }}
                                            </div>
                                            <span>{{
                                                entry.player.full_name
                                            }}</span>
                                        </Link>
                                        <div
                                            v-else-if="entry.player"
                                            class="flex items-center gap-2"
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
                                                {{
                                                    entry.player.full_name?.charAt(
                                                        0,
                                                    )
                                                }}
                                            </div>
                                            <span>{{
                                                entry.player.full_name
                                            }}</span>
                                        </div>
                                    </td>
                                    <td
                                        class="p-2 text-right text-muted-foreground"
                                    >
                                        <Link
                                            v-if="
                                                entry.player?.team?.id !=
                                                    null && config.teamLink
                                            "
                                            :href="
                                                config.teamLink(
                                                    entry.player.team.id,
                                                )
                                            "
                                            class="transition-colors hover:text-primary"
                                        >
                                            {{ entry.player.team.abbreviation }}
                                        </Link>
                                        <span v-else>{{
                                            entry.player?.team?.abbreviation ??
                                            '-'
                                        }}</span>
                                    </td>
                                    <td
                                        class="p-2 text-right text-muted-foreground"
                                    >
                                        {{ entry.games_played }}
                                    </td>
                                    <td
                                        v-for="column in statColumns"
                                        :key="column.key as string"
                                        class="p-2 text-right"
                                        :class="column.cellClass ?? ''"
                                    >
                                        {{ formatColumnValue(entry, column) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="sortedPlayers.length === 0 && !loading"
                        class="py-8 text-center text-muted-foreground"
                    >
                        <p v-if="searchQuery">
                            No players found matching "{{ searchQuery }}"
                        </p>
                        <p v-else>No player stats available.</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
