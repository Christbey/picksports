<script setup lang="ts">
import type { UrlMethodPair } from '@inertiajs/core';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import SeasonSelect from '@/components/SeasonSelect.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ApiV2SportSlug, BreadcrumbItem } from '@/types';

type HrefLike = string | UrlMethodPair;

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
    estimated_epa_total?: number;
    estimated_epa_per_opportunity?: number;
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
    minGames?: number;
    defaultSortBy?: string;
    sortOptions?: SortOption[];
    statColumns?: StatColumn[];
    match?: (entry: PlayerLeaderboardEntry) => boolean;
}

interface SportPlayerStatsShellConfig {
    sport: ApiV2SportSlug;
    pageTitle: string;
    heading: string;
    description: string;
    breadcrumb: BreadcrumbItem;
    bannerStorageKey: string;
    showEpaColumns?: boolean;
    sortOptions?: SortOption[];
    statColumns?: StatColumn[];
    statCategoryOptions?: StatCategoryOption[];
    minGames?: number;
    seasonTypeOptions?: Array<{ value: string; label: string }>;
    defaultSeasonType?: string;
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
const selectedSeasonType = ref(props.config.defaultSeasonType ?? '');
const sortBy = ref('points_per_game');
const sortDesc = ref(true);
const selectedCategory = ref<string>('all');
const seasonReady = ref(false);
const availableSeasons = ref<number[]>([]);
const selectedSeason = ref('');
const visibleLimit = ref(100);
const api = useApiV2Client();
let leaderboardAbortController: AbortController | null = null;
let seasonsAbortController: AbortController | null = null;

const fetchAvailableSeasons = async () => {
    seasonsAbortController?.abort();
    const controller = new AbortController();
    seasonsAbortController = controller;
    const payload = await api.leaderboards.playerAvailableSeasons(
        props.config.sport,
        { init: { signal: controller.signal } },
    );
    if (!payload) {
        throw new Error('Failed to fetch available seasons');
    }

    availableSeasons.value = Array.isArray(payload.data)
        ? payload.data
              .map((season) => Number(season))
              .filter((season) => Number.isFinite(season))
        : [];

    if (!selectedSeason.value && availableSeasons.value.length > 0) {
        const currentYear = new Date().getFullYear();
        const preferredSeason = availableSeasons.value.includes(currentYear)
            ? currentYear
            : Math.max(...availableSeasons.value);

        selectedSeason.value = String(preferredSeason);
    }
};

const categoryOptions = computed(() => props.config.statCategoryOptions ?? []);

const activeCategory = computed(
    () =>
        categoryOptions.value.find(
            (category) => category.key === selectedCategory.value,
        ) ?? null,
);

const activeMinGames = computed(
    () => activeCategory.value?.minGames ?? props.config.minGames ?? 1,
);

const minimumGamesForRequest = computed(() => {
    const configuredMinimums = [
        props.config.minGames,
        ...categoryOptions.value.map((category) => category.minGames),
    ].filter(
        (value): value is number =>
            typeof value === 'number' && Number.isFinite(value),
    );

    return configuredMinimums.length > 0
        ? Math.max(1, Math.min(...configuredMinimums.map(Math.trunc)))
        : 1;
});

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
    const eligiblePlayers = players.value.filter(
        (player) => player.games_played >= activeMinGames.value,
    );
    const categoryFilteredPlayers = activeCategory.value?.match
        ? eligiblePlayers.filter(
              (player) => activeCategory.value?.match?.(player) ?? true,
          )
        : eligiblePlayers;

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

const visiblePlayers = computed(() =>
    sortedPlayers.value.slice(0, visibleLimit.value),
);

const fetchPlayers = async () => {
    leaderboardAbortController?.abort();
    const controller = new AbortController();
    leaderboardAbortController = controller;

    try {
        loading.value = true;
        error.value = null;

        const query: Record<string, string | number> = {};
        if (selectedSeason.value) {
            query.season = selectedSeason.value;
        }
        query.min_games = minimumGamesForRequest.value;
        if (selectedSeasonType.value) {
            query.season_type = selectedSeasonType.value;
        }

        const response = await api.leaderboards.players(props.config.sport, {
            query,
            init: { signal: controller.signal },
        });
        if (!response) throw new Error('Failed to fetch player stats');

        players.value = (response.data ??
            []) as unknown as PlayerLeaderboardEntry[];
    } catch (e) {
        if (controller.signal.aborted) return;
        error.value = e instanceof Error ? e.message : 'An error occurred';
    } finally {
        if (leaderboardAbortController === controller) {
            loading.value = false;
        }
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

const activeSortLabel = computed(
    () =>
        sortOptions.value.find((option) => option.key === sortBy.value)
            ?.label ?? sortBy.value,
);

const selectedSeasonTypeLabel = computed(
    () =>
        props.config.seasonTypeOptions?.find(
            (option) => option.value === selectedSeasonType.value,
        )?.label ?? null,
);

const showSeasonTypeHeaderBadge = computed(
    () =>
        selectedSeasonType.value === '1' &&
        selectedSeasonTypeLabel.value !== null,
);

const formatColumnValue = (
    entry: PlayerLeaderboardEntry,
    column: StatColumn,
) => {
    const value = entry[column.key as keyof PlayerLeaderboardEntry];
    const numericValue = typeof value === 'number' ? value : undefined;
    if (column.format) return column.format(numericValue, entry);
    return Number(numericValue ?? 0).toFixed(1);
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

onMounted(() => {
    if (categoryOptions.value.length > 0) {
        selectedCategory.value = categoryOptions.value[0]?.key ?? 'all';
    }

    fetchAvailableSeasons()
        .catch(() => {
            availableSeasons.value = [];
        })
        .then(() => {
            seasonReady.value = true;
            fetchPlayers();
        });
});

watch(selectedSeason, () => {
    if (!seasonReady.value) return;
    fetchPlayers();
});

watch(selectedSeasonType, () => {
    if (!seasonReady.value) return;
    fetchPlayers();
});

watch(activeCategory, (category) => {
    const categorySort = category?.defaultSortBy ?? sortOptions.value[0]?.key;
    if (categorySort) {
        sortBy.value = categorySort;
        sortDesc.value = true;
    }
});

watch([searchQuery, sortBy, sortDesc, activeCategory], () => {
    visibleLimit.value = 100;
});

onBeforeUnmount(() => {
    leaderboardAbortController?.abort();
    seasonsAbortController?.abort();
});
</script>

<template>
    <Head :title="config.pageTitle" />

    <AppLayout :breadcrumbs="[config.breadcrumb]">
        <div class="flex h-full flex-1 flex-col gap-5 p-3 md:p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="ui-kicker">Leaderboard</p>
                    <div class="flex items-center gap-2">
                        <h1 class="text-3xl font-semibold tracking-tight">
                            {{ config.heading }}
                        </h1>
                        <span
                            v-if="showSeasonTypeHeaderBadge"
                            class="rounded-full border border-amber-200 bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold tracking-wide text-amber-800 uppercase dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300"
                        >
                            {{ selectedSeasonTypeLabel }}
                        </span>
                    </div>
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
                        <div class="space-y-2">
                            <p class="ui-kicker">Season</p>
                            <SeasonSelect
                                id="player-stats-season"
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
                                id="player-stats-season-type"
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
                                class="h-9"
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
                    {{ sortedPlayers.length }} players
                </span>
                <span class="ui-chip text-foreground/80">
                    Sort: {{ activeSortLabel }} {{ sortDesc ? '↓' : '↑' }}
                </span>
                <span v-if="selectedSeason" class="ui-chip text-foreground/80">
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
                        >Player Rankings</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <div class="ui-table-wrap">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-muted/35 text-left">
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
                                    v-for="(entry, index) in visiblePlayers"
                                    :key="entry.player_id"
                                    class="border-b transition-colors odd:bg-muted/15 hover:bg-muted/40"
                                >
                                    <td class="p-2 text-muted-foreground">
                                        <span
                                            class="font-medium"
                                            :class="
                                                rankColorClass(
                                                    index,
                                                    sortedPlayers.length,
                                                )
                                            "
                                        >
                                            {{ index + 1 }}
                                        </span>
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
                                                loading="lazy"
                                                decoding="async"
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
                                            <span
                                                v-if="entry.player.position"
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ entry.player.position }}
                                            </span>
                                        </Link>
                                        <div
                                            v-else-if="entry.player"
                                            class="flex items-center gap-2"
                                        >
                                            <img
                                                v-if="entry.player.headshot_url"
                                                :src="entry.player.headshot_url"
                                                :alt="entry.player.full_name"
                                                loading="lazy"
                                                decoding="async"
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
                                            <span
                                                v-if="entry.player.position"
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ entry.player.position }}
                                            </span>
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
                                        :class="[
                                            column.cellClass ?? '',
                                            column.key === sortBy
                                                ? rankColorClass(
                                                      index,
                                                      sortedPlayers.length,
                                                  )
                                                : '',
                                        ]"
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

                    <div
                        v-if="visiblePlayers.length < sortedPlayers.length"
                        class="flex justify-center pt-4"
                    >
                        <Button variant="outline" @click="visibleLimit += 100">
                            Show more players
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
