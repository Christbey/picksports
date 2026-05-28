<script setup lang="ts">
import { computed } from 'vue';
import BasketballGameInsights from '@/components/game-page/BasketballGameInsights.vue';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import ContextLayerCard from '@/components/game-page/ContextLayerCard.vue';
import DepthChartCard from '@/components/game-page/DepthChartCard.vue';
import GamePlayerPropsCard from '@/components/game-page/GamePlayerPropsCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import LiveBettingAnalysisCard from '@/components/game-page/LiveBettingAnalysisCard.vue';
import PredictionSummaryCard from '@/components/game-page/PredictionSummaryCard.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useGameDepthCharts } from '@/composables/useGameDepthCharts';
import { useBasketballDetailedGamePage } from '@/composables/useBasketballDetailedGamePage';
import { type TopPerformer } from '@/types';
import NBATeamController from '@/actions/App/Http/Controllers/NBA/TeamController';

const props = defineProps<{
    gameId: number;
}>();

const { pageProps, insightsProps } = useBasketballDetailedGamePage({
    sport: 'nba',
    gameId: props.gameId,
    sortTopPerformers: (players: TopPerformer[]) => players.slice(0, 10),
    teamLink: (id: number) => NBATeamController(id),
});
const { depthCharts } = useGameDepthCharts('nba', props.gameId);

const awayInjuries = computed(
    () => pageProps.value.awayTeam?.active_injuries ?? [],
);
const homeInjuries = computed(
    () => pageProps.value.homeTeam?.active_injuries ?? [],
);
const isFinal = computed(() => pageProps.value.game?.status === 'STATUS_FINAL');
const isPregame = computed(() =>
    ['STATUS_SCHEDULED', 'STATUS_PRE_GAME'].includes(
        pageProps.value.game?.status ?? '',
    ),
);
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps" :show-prediction-summary="false">
        <template #afterHero>
            <section
                v-if="pageProps.prediction"
                class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.65fr)]"
            >
                <PredictionSummaryCard
                    title="Pregame Projection"
                    :away-label="pageProps.awayLabel"
                    :home-label="pageProps.homeLabel"
                    :prediction="pageProps.prediction"
                    :format-number="pageProps.formatNumber"
                    :projected-label="pageProps.projectedLabel"
                    :away-bar-class="pageProps.awayBarClass"
                    :home-bar-class="pageProps.homeBarClass"
                />

                <div class="space-y-5">
                    <LiveBettingAnalysisCard
                        :has-live-prediction="false"
                        :betting-value="pageProps.prediction?.betting_value"
                        :prediction-analysis="
                            pageProps.prediction?.prediction_analysis
                        "
                        :winner-correct="
                            pageProps.prediction?.winner_correct ?? null
                        "
                        :actual-total="
                            pageProps.prediction?.actual_total ?? null
                        "
                        sportsbook-label="Vegas"
                    />
                    <BettingPlanCard
                        :betting-plan="
                            pageProps.prediction?.narrative?.betting_plan
                        "
                    />
                    <ContextLayerCard
                        :context-layer="
                            pageProps.prediction?.narrative?.context_layer
                        "
                    />
                </div>
            </section>

            <GamePlayerPropsCard
                v-if="isPregame"
                sport-slug="nba"
                :game-id="props.gameId"
                title="Best Props For This Game"
            />
        </template>

        <template #afterPrediction>
            <BasketballGameInsights
                v-bind="insightsProps"
                box-score-layout="grid"
                :show-recap="isFinal"
            />
            <InjuryReportCard
                :away-team-abbr="pageProps.awayTeam?.abbreviation"
                :home-team-abbr="pageProps.homeTeam?.abbreviation"
                :away-injuries="awayInjuries"
                :home-injuries="homeInjuries"
                :depth-chart-context="pageProps.prediction?.depth_chart_context"
            />
            <DepthChartCard
                v-if="depthCharts"
                :away-team="depthCharts.away_team"
                :home-team="depthCharts.home_team"
            />
        </template>
    </SportDetailedGamePage>
</template>
