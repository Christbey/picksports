<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import UnifiedPredictionCard from '@/components/predictions/UnifiedPredictionCard.vue';
import { usePredictionCardGame } from '@/composables/usePredictionCardGame';
import { usePredictionCardRecommendation } from '@/composables/usePredictionCardRecommendation';
import type { PredictionCardProps } from '@/types/prediction-card';

const props = defineProps<PredictionCardProps>();
const expanded = ref(false);
const game = usePredictionCardGame(props);
const { pregameCalls, dashboardSignalLabel } = usePredictionCardRecommendation(
    props,
    game,
);
</script>

<template>
    <tbody class="border-t border-border">
        <tr class="hover:bg-muted/40">
            <th scope="row" class="px-3 py-3 text-left font-semibold">
                <Link :href="href" class="hover:underline">
                    {{ game.awayTeamLabel() }} @ {{ game.homeTeamLabel() }}
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
            <td class="px-3 py-3">{{ pregameCalls.winner }}</td>
            <td class="px-3 py-3">{{ pregameCalls.spread }}</td>
            <td class="px-3 py-3">{{ pregameCalls.total }}</td>
            <td class="px-3 py-3 text-muted-foreground">
                {{ dashboardSignalLabel() ?? '—' }}
            </td>
            <td class="px-3 py-3 text-right">
                <button
                    type="button"
                    :aria-expanded="expanded"
                    :aria-label="`${expanded ? 'Hide' : 'Show'} analysis for ${game.awayTeamLabel()} at ${game.homeTeamLabel()}`"
                    class="rounded px-2 py-1 font-medium text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                    @click="expanded = !expanded"
                >
                    {{ expanded ? 'Hide' : 'Analysis' }}
                </button>
            </td>
        </tr>
        <tr v-if="expanded" class="bg-muted/20">
            <td colspan="7" class="px-3 pb-3">
                <UnifiedPredictionCard v-bind="props" />
            </td>
        </tr>
    </tbody>
</template>
