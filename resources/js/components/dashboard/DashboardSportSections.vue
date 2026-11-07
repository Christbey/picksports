<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import UnifiedPredictionCard from '@/components/predictions/UnifiedPredictionCard.vue';
import { useDashboardPresentation } from '@/composables/useDashboardView';
import { isMlbSpringTrainingType } from '@/lib/mlbSeasonType';
import type { DashboardSport } from '@/types';

defineProps<{
    sports: DashboardSport[];
}>();

const { getGameUrl } = useDashboardPresentation();

const showSpringTrainingBadge = (sport: DashboardSport): boolean =>
    sport.name === 'MLB' &&
    sport.predictions.some((prediction) =>
        isMlbSpringTrainingType(prediction.season_type),
    );
</script>

<template>
    <div class="space-y-8">
        <section
            v-for="sport in sports"
            :key="sport.name"
            :aria-label="sport.fullName"
            class="space-y-4"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4"
            >
                <div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 class="text-xl font-semibold tracking-tight">
                            {{ sport.name }}
                        </h2>
                        <Badge variant="secondary"
                            >{{ sport.predictions.length }}
                            {{
                                sport.predictions.length === 1
                                    ? 'game'
                                    : 'games'
                            }}</Badge
                        >
                        <Badge
                            v-if="showSpringTrainingBadge(sport)"
                            variant="outline"
                            >Spring Training</Badge
                        >
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ sport.fullName }}
                    </p>
                </div>
                <span class="ps-eyebrow">Today's predictions</span>
            </div>

            <div class="space-y-3">
                <UnifiedPredictionCard
                    v-for="prediction in sport.predictions"
                    :key="`${prediction.sport}-${prediction.game_id}`"
                    :prediction="prediction"
                    :href="getGameUrl(prediction.sport, prediction.game_id)"
                    :sport="prediction.sport"
                />
            </div>
        </section>
    </div>
</template>
