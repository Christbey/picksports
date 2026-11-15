<script setup lang="ts">
import DashboardPredictionRow from '@/components/dashboard/DashboardPredictionRow.vue';
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

            <div
                class="hidden overflow-x-auto rounded-lg border border-border bg-card lg:block"
            >
                <table
                    class="w-full text-left text-sm"
                    :aria-label="`${sport.name} predictions`"
                >
                    <thead class="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th
                                v-for="column in [
                                    'Matchup',
                                    'Game status',
                                    'Model winner',
                                    'Spread / Margin',
                                    'Total',
                                ]"
                                :key="column"
                                scope="col"
                                class="px-3 py-2 font-medium"
                            >
                                {{ column }}
                            </th>
                            <th scope="col" class="px-3 py-2">
                                <span class="sr-only">Analysis</span>
                            </th>
                        </tr>
                    </thead>
                    <DashboardPredictionRow
                        v-for="prediction in sport.predictions"
                        :key="`${prediction.sport}-${prediction.game_id}`"
                        :prediction="prediction"
                        :href="getGameUrl(prediction.sport, prediction.game_id)"
                        :sport="prediction.sport"
                        dashboard
                    />
                </table>
            </div>
            <div class="space-y-3 lg:hidden">
                <UnifiedPredictionCard
                    v-for="prediction in sport.predictions"
                    :key="`${prediction.sport}-${prediction.game_id}`"
                    :prediction="prediction"
                    :href="getGameUrl(prediction.sport, prediction.game_id)"
                    :sport="prediction.sport"
                    dashboard
                    compact
                />
            </div>
        </section>
    </div>
</template>
