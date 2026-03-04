<script setup lang="ts">
import UnifiedPredictionCard from '@/components/predictions/UnifiedPredictionCard.vue';
import UpgradeCard from '@/components/UpgradeCard.vue';
import {
    useDashboardPresentation,
} from '@/composables/useDashboardView';
import type { DashboardSport } from '@/types';

defineProps<{
    sports: DashboardSport[];
}>();

const {
    getSportHeaderColor,
    getGameUrl,
} = useDashboardPresentation();

const upgradeFeatures = [
    'Unlimited daily predictions',
    'All sports coverage',
    'Advanced betting analytics',
    'Priority support',
];
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[1fr_300px]">
        <div class="space-y-6">
            <div
                v-for="sport in sports"
                :key="sport.name"
                class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-sidebar"
            >
                <div
                    class="flex items-center justify-between bg-gradient-to-r p-4"
                    :class="getSportHeaderColor(sport.color)"
                >
                    <div>
                        <h2 class="text-xl font-bold text-white">{{ sport.name }}</h2>
                        <p class="text-sm text-white/80">{{ sport.fullName }}</p>
                    </div>
                    <div
                        class="rounded-full bg-white/20 px-3 py-1 text-sm font-medium text-white"
                    >
                        {{ sport.predictions.length }}
                        {{ sport.predictions.length === 1 ? 'game' : 'games' }}
                    </div>
                </div>

                <div class="space-y-3 p-4">
                    <UnifiedPredictionCard
                        v-for="prediction in sport.predictions"
                        :key="`${prediction.sport}-${prediction.game_id}`"
                        :prediction="prediction"
                        :href="getGameUrl(prediction.sport, prediction.game_id)"
                        :sport="prediction.sport"
                    />
                </div>
            </div>
        </div>

        <div class="hidden lg:block">
            <div class="sticky top-4">
                <UpgradeCard :features="upgradeFeatures" />
            </div>
        </div>
    </div>

    <div class="lg:hidden">
        <UpgradeCard variant="compact" :features="upgradeFeatures" />
    </div>
</template>
