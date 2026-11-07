<script setup lang="ts">
import { provide } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import DataRecovery from '@/components/DataRecovery.vue';
import MlbPickDetailDrawer from '@/components/mlb/MlbPickDetailDrawer.vue';
import {
    useMlbDailyPicksBoard,
    mlbDailyPicksBoardKey,
} from '@/composables/useMlbDailyPicksBoard';
import MlbDailyPicksBoardHeader from '@/components/mlb/MlbDailyPicksBoardHeader.vue';
import MlbDailyPicksBoardContent from '@/components/mlb/MlbDailyPicksBoardContent.vue';
const props = defineProps<{ season?: string | number | null }>();
const state = useMlbDailyPicksBoard(props);
provide(mlbDailyPicksBoardKey, state);
const { payload, loading, loadPicks, selectedPick, detailOpen, error } = state;
</script>
<template>
    <section class="space-y-5">
        <div v-if="loading && !payload" class="space-y-4">
            <Skeleton class="h-28 w-full rounded-lg" />
            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                <Skeleton class="h-80 w-full rounded-lg" />
                <Skeleton class="h-80 w-full rounded-lg" />
            </div>
        </div>

        <DataRecovery
            v-if="error"
            :message="error"
            :busy="loading"
            :stale="!!payload"
            @retry="loadPicks"
        />

        <template v-if="payload">
            <MlbDailyPicksBoardHeader />

            <MlbDailyPicksBoardContent />

            <MlbPickDetailDrawer
                v-model:open="detailOpen"
                :candidate="selectedPick"
            />
        </template>
    </section>
</template>
