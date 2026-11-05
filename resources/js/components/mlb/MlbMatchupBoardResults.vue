<script setup lang="ts">
import MlbMatchupCard from '@/components/mlb/MlbMatchupCard.vue';
import { useMlbMatchupBoardContext } from '@/composables/useMlbMatchupBoard';
const {
    visibleBoardStats,
    candidatesByGameId,
    filteredPredictions,
    quietEmptyState,
    candidateForPrediction,
    selectedFilter,
    numberValue,
    filtersLoading,
    error,
    selectMatchup,
} = useMlbMatchupBoardContext();
</script>
<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold tracking-normal">Matchups</h2>
                <p class="text-sm text-muted-foreground">
                    One card per game. Open a matchup for model, market, reason,
                    and risk detail.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span
                    v-for="stat in visibleBoardStats"
                    :key="stat.label"
                    class="rounded-full border bg-card px-3 py-1 text-xs font-semibold text-muted-foreground"
                >
                    {{ stat.label }} {{ stat.value }}
                </span>
            </div>
        </div>

        <div
            v-if="!error && !filtersLoading && filteredPredictions.length === 0"
            class="rounded-2xl border border-dashed bg-card/70 p-6"
        >
            <div class="font-semibold">{{ quietEmptyState.title }}</div>
            <p class="mt-1 text-sm leading-6 text-muted-foreground">
                {{ quietEmptyState.body }}
            </p>
        </div>

        <div
            v-else-if="filteredPredictions.length > 0"
            class="grid gap-3 xl:grid-cols-2"
        >
            <MlbMatchupCard
                v-for="prediction in filteredPredictions"
                :key="prediction.id"
                :prediction="prediction"
                :candidate="candidateForPrediction(prediction, selectedFilter)"
                :candidate-count="
                    candidatesByGameId.get(
                        numberValue(
                            prediction.game_id ?? prediction.game?.id,
                        ) ?? -1,
                    )?.length ?? 0
                "
                @select="selectMatchup"
            />
        </div>
    </section>
</template>
