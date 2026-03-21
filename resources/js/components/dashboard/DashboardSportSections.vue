<script setup lang="ts">
import UnifiedPredictionCard from '@/components/predictions/UnifiedPredictionCard.vue';
import { useDashboardPresentation } from '@/composables/useDashboardView';
import { isMlbSpringTrainingType } from '@/lib/mlbSeasonType';
import type { DashboardSport } from '@/types';

defineProps<{
    sports: DashboardSport[];
}>();

const { getSportHeaderColor, getGameUrl } = useDashboardPresentation();

const showSpringTrainingBadge = (sport: DashboardSport): boolean =>
    sport.name === 'MLB' &&
    sport.predictions.some((prediction) =>
        isMlbSpringTrainingType(prediction.season_type),
    );
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
                    <div class="flex items-center gap-2">
                        <h2
                            class="text-xl font-semibold tracking-tight text-white"
                        >
                            {{ sport.name }}
                        </h2>
                        <span
                            v-if="showSpringTrainingBadge(sport)"
                            class="rounded-full border border-white/30 bg-white/20 px-2.5 py-0.5 text-[11px] font-semibold tracking-wide text-white uppercase"
                        >
                            Spring Training
                        </span>
                    </div>
                    <p class="text-sm text-white/85">{{ sport.fullName }}</p>
                </div>
                <div
                    class="rounded-full border border-white/25 bg-white/20 px-3 py-1 text-xs font-semibold tracking-wide text-white uppercase"
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
