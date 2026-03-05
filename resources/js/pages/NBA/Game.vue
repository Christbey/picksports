<script setup lang="ts">
import { computed } from 'vue';
import NBATeamController from '@/actions/App/Http/Controllers/NBA/TeamController';
import BasketballGameInsights from '@/components/game-page/BasketballGameInsights.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import LiveBettingAnalysisCard from '@/components/game-page/LiveBettingAnalysisCard.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useBasketballDetailedGamePage } from '@/composables/useBasketballDetailedGamePage';
import { type Game, type TopPerformer } from '@/types';

const props = defineProps<{
    game: Game;
}>();

const { pageProps, insightsProps } = useBasketballDetailedGamePage({
    sport: 'nba',
    game: props.game,
    sortTopPerformers: (players: TopPerformer[]) => players.slice(0, 10),
    teamLink: (id: number) => NBATeamController(id),
});

const awayInjuries = computed(() => pageProps.value.awayTeam?.active_injuries ?? []);
const homeInjuries = computed(() => pageProps.value.homeTeam?.active_injuries ?? []);
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps">
        <template #afterPrediction>
            <LiveBettingAnalysisCard
                :has-live-prediction="false"
                :betting-value="pageProps.prediction?.betting_value"
                :winner-correct="pageProps.prediction?.winner_correct ?? null"
                :actual-total="pageProps.prediction?.actual_total ?? null"
                sportsbook-label="Vegas"
            />
            <div
                v-if="pageProps.prediction?.narrative?.betting_plan"
                class="rounded-2xl border bg-white/95 p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/90"
            >
                <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    Betting Plan
                </h3>
                <p class="mt-3 text-zinc-700 dark:text-zinc-200">
                    <span class="font-medium">Bet:</span>
                    {{ pageProps.prediction.narrative.betting_plan.bet_pick }}
                </p>
                <p class="mt-1 text-zinc-700 dark:text-zinc-200">
                    <span class="font-medium">Why:</span>
                    {{ pageProps.prediction.narrative.betting_plan.reasoning }}
                </p>
            </div>
            <div
                v-else
                class="rounded-2xl border bg-white/95 p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/90"
            >
                <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    Betting Plan
                </h3>
                <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-200">
                    Betting plan unavailable for the current access tier.
                </p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Requires prediction data access to spread, win probability, and confidence score.
                </p>
            </div>
            <BasketballGameInsights
                v-bind="insightsProps"
                box-score-layout="grid"
            />
            <InjuryReportCard
                :away-team-abbr="pageProps.awayTeam?.abbreviation"
                :home-team-abbr="pageProps.homeTeam?.abbreviation"
                :away-injuries="awayInjuries"
                :home-injuries="homeInjuries"
            />
        </template>
    </SportDetailedGamePage>
</template>
