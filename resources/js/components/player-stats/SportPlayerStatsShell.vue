<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import DataRecovery from '@/components/DataRecovery.vue';
import { provide } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { PlayerStatsProps } from '@/types/player-stats';
import { usePlayerStats, playerStatsKey } from '@/composables/usePlayerStats';
import PlayerStatsFilters from './PlayerStatsFilters.vue';
import PlayerStatsResults from './PlayerStatsResults.vue';
const props = defineProps<PlayerStatsProps>();
const board = usePlayerStats(props);
provide(playerStatsKey, board);
const {
    error,
    loading,
    players,
    fetchPlayers,
    searchQuery,
    selectedSeasonType,
    sortDesc,
    selectedSeason,
    sortedPlayers,
    activeSortLabel,
    selectedSeasonTypeLabel,
    showSeasonTypeHeaderBadge,
} = board;
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

            <PlayerStatsFilters />

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

            <DataRecovery
                v-if="error"
                :message="error"
                :busy="loading"
                :stale="players.length > 0"
                @retry="fetchPlayers"
            />

            <PlayerStatsResults v-if="!error || players.length > 0" />
        </div>
    </AppLayout>
</template>
