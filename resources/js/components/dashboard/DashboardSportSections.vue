<script setup lang="ts">
import UnifiedPredictionCard from '@/components/predictions/UnifiedPredictionCard.vue';
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
</script>

<template>
    <div class="space-y-6">
        <div
            v-for="sport in sports"
            :key="sport.name"
            class="ui-surface overflow-hidden"
        >
            <div
                class="flex items-center justify-between bg-gradient-to-r p-4 md:p-5"
                :class="getSportHeaderColor(sport.color)"
            >
                <div>
                    <h2 class="text-xl font-semibold tracking-tight text-white">{{ sport.name }}</h2>
                    <p class="text-sm text-white/85">{{ sport.fullName }}</p>
                </div>
                <div
                    class="rounded-full border border-white/25 bg-white/20 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-white"
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
</template>
