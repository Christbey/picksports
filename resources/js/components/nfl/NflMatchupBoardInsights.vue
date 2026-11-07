<script setup lang="ts">
import NflSignalsPanel from '@/components/nfl/NflSignalsPanel.vue';
import { useNflMatchupBoardContext } from '@/composables/useNflMatchupBoard';
const { selectedSeason, showInsights } = useNflMatchupBoardContext();
</script>
<template>
    <section class="rounded-xl border bg-card">
        <button
            type="button"
            class="flex min-h-11 w-full items-center justify-between p-3 text-left text-sm font-medium"
            :aria-expanded="showInsights"
            aria-controls="nfl-more-insights"
            @click="showInsights = !showInsights"
        >
            More insights
            <span aria-hidden="true">{{ showInsights ? '−' : '+' }}</span>
        </button>
        <div v-if="showInsights" id="nfl-more-insights" class="border-t p-3">
            <p class="mb-3 text-xs text-muted-foreground">
                Season-wide context; not limited to the selected game date.
            </p>
            <NflSignalsPanel :key="selectedSeason" :season="selectedSeason" />
        </div>
    </section>
</template>
