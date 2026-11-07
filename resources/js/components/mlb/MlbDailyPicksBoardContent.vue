<script setup lang="ts">
import MlbDailyPicksBoardResults from '@/components/mlb/MlbDailyPicksBoardResults.vue';
import MlbDailyPicksBoardHealth from '@/components/mlb/MlbDailyPicksBoardHealth.vue';
import MlbDailyPicksBoardPerformance from '@/components/mlb/MlbDailyPicksBoardPerformance.vue';
import MlbDailyPicksBoardSignals from '@/components/mlb/MlbDailyPicksBoardSignals.vue';
import MlbDailyPicksBoardMode from '@/components/mlb/MlbDailyPicksBoardMode.vue';
</script>
<template>
    <section
        class="grid gap-5 md:grid-cols-[minmax(0,1fr)_300px] 2xl:grid-cols-[minmax(0,1fr)_340px]"
    >
        <MlbDailyPicksBoardResults />

        <aside class="space-y-4">
            <MlbDailyPicksBoardHealth />

            <MlbDailyPicksBoardPerformance />

            <MlbDailyPicksBoardSignals />

            <MlbDailyPicksBoardMode />
        </aside>
    </section>
</template>
