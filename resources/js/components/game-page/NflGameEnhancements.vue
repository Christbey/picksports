<script setup lang="ts">
import type { LivePredictionData } from '@/components/BettingAnalysisCard.vue';
import LiveBettingAnalysisCard from '@/components/game-page/LiveBettingAnalysisCard.vue';
import NFLBoxScoreCard from '@/components/game-page/NFLBoxScoreCard.vue';
import NFLPredictionModelCard from '@/components/game-page/NFLPredictionModelCard.vue';
import TeamRecentGamesSection from '@/components/game-page/TeamRecentGamesSection.vue';
import type { NflPagePrediction, NflTeamStats, RecentGameListItem } from '@/types';

const props = withDefaults(defineProps<{
    section: 'prediction' | 'analysis' | 'recent';
    prediction?: NflPagePrediction | null;
    awayLabel?: string | null;
    homeLabel?: string | null;
    formatNumber?: (value: number | string | null | undefined, decimals?: number) => string;
    formatSpread?: (spread: number | string) => string;
    homeTeamStats?: NflTeamStats | null;
    awayTeamStats?: NflTeamStats | null;
    getBetterValue?: (homeValue: number, awayValue: number, lowerIsBetter?: boolean) => 'home' | 'away' | null;
    calculatePercentage?: (made: number, attempted: number) => string;
    hasLivePrediction?: boolean;
    livePredictionData?: LivePredictionData;
    awayRecord?: string;
    homeRecord?: string;
    awayRecentGames?: RecentGameListItem[];
    homeRecentGames?: RecentGameListItem[];
    awayTeamId?: number;
    homeTeamId?: number;
    gameHrefPrefix?: string;
}>(), {
    formatNumber: (value: number | string | null | undefined, decimals = 1) => {
        if (value === null || value === undefined) return '-';
        const parsed = Number(value);
        if (Number.isNaN(parsed)) return '-';
        return parsed.toFixed(decimals);
    },
    formatSpread: (spread: number | string) => {
        const parsed = Number(spread);
        if (Number.isNaN(parsed)) return '-';
        return parsed > 0 ? `+${parsed.toFixed(1)}` : parsed.toFixed(1);
    },
    hasLivePrediction: false,
    getBetterValue: (homeValue, awayValue, lowerIsBetter = false) => {
        if (lowerIsBetter) {
            if (homeValue < awayValue) return 'home';
            if (awayValue < homeValue) return 'away';
            return null;
        }
        if (homeValue > awayValue) return 'home';
        if (awayValue > homeValue) return 'away';
        return null;
    },
    calculatePercentage: (made, attempted) => {
        if (!attempted) return '0.0';
        return ((made / attempted) * 100).toFixed(1);
    },
    awayRecord: '0-0',
    homeRecord: '0-0',
    awayRecentGames: () => [],
    homeRecentGames: () => [],
    gameHrefPrefix: '/nfl/games',
});
</script>

<template>
    <NFLPredictionModelCard
        v-if="section === 'prediction' && prediction"
        :prediction="prediction"
        :away-label="awayLabel"
        :home-label="homeLabel"
        :format-number="props.formatNumber"
        :format-spread="props.formatSpread"
    />

    <template v-else-if="section === 'analysis'">
        <NFLBoxScoreCard
            v-if="homeTeamStats && awayTeamStats"
            :away-label="awayLabel"
            :home-label="homeLabel"
            :away-team-stats="awayTeamStats"
            :home-team-stats="homeTeamStats"
            :get-better-value="props.getBetterValue"
            :calculate-percentage="props.calculatePercentage"
        />

        <LiveBettingAnalysisCard
            :has-live-prediction="hasLivePrediction"
            :betting-value="prediction?.betting_value"
            :live-prediction="livePredictionData"
            sportsbook-label="DraftKings"
        />
    </template>

    <TeamRecentGamesSection
        v-else-if="section === 'recent' && awayRecentGames && homeRecentGames && awayTeamId !== undefined && homeTeamId !== undefined"
        :away-label="awayLabel"
        :home-label="homeLabel"
            :away-record="awayRecord"
            :home-record="homeRecord"
            :away-recent-games="awayRecentGames"
            :home-recent-games="homeRecentGames"
            :away-team-id="awayTeamId"
            :home-team-id="homeTeamId"
            :game-href-prefix="gameHrefPrefix"
    />
</template>
