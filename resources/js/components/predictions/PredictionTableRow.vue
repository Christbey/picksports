<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { predictionHomeMargin } from '@/lib/predictionSpread';
import UnifiedPredictionCard from '@/components/predictions/UnifiedPredictionCard.vue';
import { usePredictionCardGame } from '@/composables/usePredictionCardGame';
import { usePredictionCardRecommendation } from '@/composables/usePredictionCardRecommendation';
import type { PredictionCardProps } from '@/types/prediction-card';

const props = defineProps<PredictionCardProps>();
const expanded = ref(false);
const game = usePredictionCardGame(props);
const { pregameCalls, dashboardWinnerGrade } = usePredictionCardRecommendation(
    props,
    game,
);
const projectedSpread = computed(() => {
    const margin = predictionHomeMargin(props.prediction);
    if (margin === null) return '—';
    if (margin === 0) return 'Pick’em';

    const team = margin > 0 ? game.homeTeamLabel() : game.awayTeamLabel();
    return `${team} −${Math.abs(margin)}`;
});
</script>

<template>
    <tbody class="border-t border-border">
        <tr :class="dashboardWinnerGrade?.rowClass || 'hover:bg-muted/40'">
            <th scope="row" class="px-3 py-3 text-left font-semibold">
                <Link
                    :href="href"
                    class="inline-flex flex-wrap items-center gap-1.5 underline-offset-4 hover:underline"
                >
                    <img
                        v-if="!dashboard && game.showAwayLogo()"
                        :src="game.awayLogo()!"
                        alt=""
                        class="size-5 object-contain"
                        :loading="deferTeamLogos ? 'lazy' : undefined"
                        @error="game.handleAwayLogoError"
                    />
                    {{ game.awayTeamLabel() }} @
                    <img
                        v-if="!dashboard && game.showHomeLogo()"
                        :src="game.homeLogo()!"
                        alt=""
                        class="size-5 object-contain"
                        :loading="deferTeamLogos ? 'lazy' : undefined"
                        @error="game.handleHomeLogoError"
                    />
                    {{ game.homeTeamLabel() }}
                </Link>
            </th>
            <td class="px-3 py-3 text-muted-foreground">
                <p>
                    {{
                        game.isLive()
                            ? game.liveGameStateLabel()
                            : game.statusBadgeLabel()
                    }}
                </p>
                <p v-if="game.showGameScore()" class="tabular-nums">
                    {{ game.awayScore() }} – {{ game.homeScore() }}
                </p>
                <p v-else-if="!game.isLive() && !game.isFinal()">
                    {{ game.gameDateTimeLabel() }}
                </p>
            </td>
            <td class="px-3 py-3">
                {{ pregameCalls.winner }}
                <p
                    v-if="dashboardWinnerGrade"
                    class="text-xs text-muted-foreground"
                >
                    {{ dashboardWinnerGrade.label }}
                </p>
            </td>
            <td class="px-3 py-3">
                {{ dashboard ? pregameCalls.spread : projectedSpread }}
                <p
                    v-if="pregameCalls.spreadGrade"
                    class="block text-xs text-muted-foreground"
                >
                    {{ pregameCalls.spreadGrade }}
                </p>
            </td>
            <td class="px-3 py-3">
                {{
                    dashboard
                        ? pregameCalls.total
                        : (prediction.predicted_total ?? '—')
                }}
                <p
                    v-if="pregameCalls.totalGrade"
                    class="block text-xs text-muted-foreground"
                >
                    {{ pregameCalls.totalGrade }}
                </p>
            </td>
            <td class="px-3 py-3 text-right">
                <button
                    type="button"
                    :aria-expanded="expanded"
                    :aria-label="`${expanded ? 'Hide' : 'Show'} analysis for ${game.awayTeamLabel()} at ${game.homeTeamLabel()}`"
                    class="inline-flex items-center gap-1 rounded px-2 py-1 font-medium text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                    @click="expanded = !expanded"
                >
                    Analysis
                    <ChevronDown
                        aria-hidden="true"
                        class="size-4 transition-transform"
                        :class="{ 'rotate-180': expanded }"
                    />
                </button>
            </td>
        </tr>
        <tr v-if="expanded" class="border-t border-border bg-muted/25">
            <td colspan="6" class="px-4 py-4">
                <UnifiedPredictionCard v-bind="props" details-only />
            </td>
        </tr>
    </tbody>
</template>
