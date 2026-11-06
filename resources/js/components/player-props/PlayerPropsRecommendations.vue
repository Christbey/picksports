<script setup lang="ts">
import DataRecovery from '@/components/DataRecovery.vue';
import { BarChart3 } from 'lucide-vue-next';

import { Button } from '@/components/ui/button';

import { ref, onMounted, onBeforeUnmount } from 'vue';
import NflPlayerPropCard from './NflPlayerPropCard.vue';
import PlayerPropCard from './PlayerPropCard.vue';
import { usePlayerPropsBoardContext } from '@/composables/usePlayerPropsBoardContext';
import { usePlayerPropPresentation } from '@/composables/usePlayerPropPresentation';
import { useViewerAccess } from '@/composables/useViewerAccess';
const props = defineProps<{ sport: string }>();
const { isAdmin } = useViewerAccess();
const board = usePlayerPropsBoardContext();
const nowMs = ref(Date.now());
const presentation = usePlayerPropPresentation(props, nowMs);
let freshnessTimer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    freshnessTimer = setInterval(() => {
        nowMs.value = Date.now();
    }, 60_000);
});
onBeforeUnmount(() => clearInterval(freshnessTimer));
const expandedModelDetails = ref<number[]>([]);

const isModelDetailsOpen = (id: number) =>
    expandedModelDetails.value.includes(id);

const toggleModelDetails = (id: number) => {
    if (isModelDetailsOpen(id)) {
        expandedModelDetails.value = expandedModelDetails.value.filter(
            (item) => item !== id,
        );
        return;
    }

    expandedModelDetails.value = [...expandedModelDetails.value, id];
};
</script>

<template>
    <section v-if="board.selectedView === 'recommendations'" class="space-y-6">
        <h3
            v-if="board.error && !board.recommendations.length"
            class="text-xl font-semibold tracking-tight"
        >
            Unable To Load Player Props
        </h3>
        <DataRecovery
            v-if="board.error"
            :message="board.error"
            :busy="board.loading"
            :stale="board.recommendations.length > 0"
            @retry="board.loadBoard"
        />
        <div
            v-if="
                board.selectedView === 'recommendations' &&
                board.loading &&
                board.recommendations.length === 0
            "
            role="status"
            aria-label="Loading player props"
            aria-busy="true"
            class="py-16 text-center"
        >
            <p class="text-muted-foreground">Loading player props...</p>
        </div>

        <div
            v-else-if="
                board.selectedView === 'recommendations' &&
                !board.error &&
                board.recommendations.length === 0
            "
            class="py-16 text-center"
        >
            <BarChart3 class="mx-auto mb-4 h-16 w-16 text-muted-foreground" />
            <h3 class="mb-2 text-xl font-semibold tracking-tight">
                {{ board.emptyStateTitle }}
            </h3>
            <p class="text-muted-foreground">
                {{ board.emptyStateMessage }}
            </p>
        </div>

        <template v-else-if="board.recommendations.length > 0">
            <p
                v-if="board.searchedRecommendations.length === 0"
                class="py-12 text-center text-muted-foreground"
            >
                No players match your search.
            </p>
            <div
                v-if="sport === 'NFL'"
                class="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3"
            >
                <NflPlayerPropCard
                    v-for="rec in board.visibleRecommendations"
                    :key="rec.id"
                    :rec="rec"
                    :now-ms="nowMs"
                />
            </div>
            <div v-else class="columns-1 gap-5 md:columns-2 lg:columns-3">
                <PlayerPropCard
                    v-for="rec in board.visibleRecommendations"
                    :key="rec.id"
                    :rec="rec"
                    :sport="sport"
                    :is-admin="isAdmin"
                    :presentation="presentation"
                    :expanded="isModelDetailsOpen(rec.id)"
                    @toggle="toggleModelDetails(rec.id)"
                />
            </div>
            <div
                v-if="
                    board.visibleRecommendations.length <
                    board.searchedRecommendations.length
                "
                class="flex justify-center"
            >
                <Button variant="outline" @click="board.visibleLimit += 18">
                    Show more props
                </Button>
            </div>
        </template>
    </section>
</template>
