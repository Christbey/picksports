<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';
import type { PlayerPageProps } from '@/types/player-page';
import { usePlayerPage } from '@/composables/usePlayerPage';
import PlayerIdentity from './PlayerIdentity.vue';
import PlayerPropsCard from './PlayerPropsCard.vue';
import PlayerSeasonSummary from './PlayerSeasonSummary.vue';
import PlayerGameLog from './PlayerGameLog.vue';
const props = defineProps<PlayerPageProps>();
const {
    playerData,
    playerProps,
    gameLogs,
    loading,
    error,
    breadcrumbs,
    seasonSummaryValues,
    gameLogColumns,
} = usePlayerPage(props);
</script>

<template>
    <Head :title="playerData?.name || `${config.sportLabel} Player`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-5 p-3 md:p-4">
            <PlayerIdentity :player-data="playerData" :config="config" />

            <slot v-if="playerData" name="afterHeader" :player="playerData" />

            <PlayerPropsCard :player-props="playerProps" />

            <div v-if="loading" class="space-y-4">
                <Skeleton class="h-32 w-full" />
                <Skeleton class="h-64 w-full" />
            </div>

            <template v-else>
                <PlayerSeasonSummary
                    :season-summary-values="seasonSummaryValues"
                    :games-count="gameLogs.length"
                />

                <PlayerGameLog
                    :game-logs="gameLogs"
                    :game-log-columns="gameLogColumns"
                    :player-data="playerData"
                    :error="error"
                    :config="config"
                />
            </template>
        </div>
    </AppLayout>
</template>
