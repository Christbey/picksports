<script setup lang="ts">
import GamePageErrorBoundary from '@/components/game-page/GamePageErrorBoundary.vue';
import GamePageShell from '@/components/game-page/GamePageShell.vue';
import LinescoreCard from '@/components/game-page/LinescoreCard.vue';
import MatchupHero from '@/components/game-page/MatchupHero.vue';
import PredictionSummaryCard from '@/components/game-page/PredictionSummaryCard.vue';
import TrendsComparisonCard from '@/components/game-page/TrendsComparisonCard.vue';
import type { BreadcrumbItem, GamePageGame, GamePageHrefLike, GamePageTeam, PredictionSummary, TeamTrendData } from '@/types';

withDefaults(defineProps<{
    title: string;
    breadcrumbs: BreadcrumbItem[];
    loading: boolean;
    error?: string | null;
    awayTeam: GamePageTeam | null;
    homeTeam: GamePageTeam | null;
    game: GamePageGame;
    gameStatus: string;
    formatDate: (dateString: string | null) => string;
    teamLink: (id: number) => GamePageHrefLike;
    gradientClass: string;
    awayRecentForm?: string;
    homeRecentForm?: string;
    venueLabel?: string | null;
    broadcastNetworks?: string[];
    extraInfoItems?: string[];
    showScoreStatuses?: string[];
    badgePulseStatuses?: string[];
    useTeamColorGlow?: boolean;
    showLinescore?: boolean;
    linescoreTitle?: string;
    awayLinescores?: Array<{ period?: number; value: number | string }>;
    homeLinescores?: Array<{ period?: number; value: number | string }>;
    awayScore?: number | null;
    homeScore?: number | null;
    usePeriodNumbers?: boolean;
    periodPrefix?: string;
    showPredictionSummary?: boolean;
    prediction?: PredictionSummary | null;
    awayLabel?: string | null;
    homeLabel?: string | null;
    formatNumber?: (value: number | string | null | undefined, decimals?: number) => string;
    projectedLabel?: string;
    awayBarClass?: string;
    homeBarClass?: string;
    showTrends?: boolean;
    trendsTitle?: string;
    trendsSubtitle?: string;
    trendsLoading?: boolean;
    allTrendCategories?: string[];
    formatCategoryName?: (value: string) => string;
    isLockedCategory?: (category: string) => boolean;
    formatTierName?: (tier: string) => string;
    getRequiredTier?: (category: string) => string;
    awayTrends?: TeamTrendData | null;
    homeTrends?: TeamTrendData | null;
    trendsEmptyText?: string;
}>(), {
    error: null,
    awayRecentForm: undefined,
    homeRecentForm: undefined,
    venueLabel: undefined,
    broadcastNetworks: () => [],
    extraInfoItems: () => [],
    showScoreStatuses: () => ['STATUS_FINAL'],
    badgePulseStatuses: () => [],
    useTeamColorGlow: false,
    showLinescore: false,
    linescoreTitle: 'Linescore',
    awayLinescores: () => [],
    homeLinescores: () => [],
    awayScore: null,
    homeScore: null,
    usePeriodNumbers: true,
    periodPrefix: undefined,
    showPredictionSummary: false,
    prediction: null,
    awayLabel: null,
    homeLabel: null,
    formatNumber: undefined,
    projectedLabel: 'Projected points',
    awayBarClass: 'bg-blue-500 dark:bg-blue-600',
    homeBarClass: 'bg-blue-800 dark:bg-blue-400',
    showTrends: false,
    trendsTitle: 'Team Trends',
    trendsSubtitle: undefined,
    trendsLoading: false,
    allTrendCategories: () => [],
    formatCategoryName: undefined,
    isLockedCategory: undefined,
    formatTierName: undefined,
    getRequiredTier: undefined,
    awayTrends: null,
    homeTrends: null,
    trendsEmptyText: 'No trends available for this matchup',
});
</script>

<template>
    <GamePageErrorBoundary>
        <GamePageShell :title="title" :breadcrumbs="breadcrumbs" :loading="loading" :error="error">
            <MatchupHero
                :away-team="awayTeam"
                :home-team="homeTeam"
                :away-recent-form="awayRecentForm"
                :home-recent-form="homeRecentForm"
                :game="game"
                :game-status="gameStatus"
                :format-date="formatDate"
                :team-link="teamLink"
                :gradient-class="gradientClass"
                :venue-label="venueLabel"
                :broadcast-networks="broadcastNetworks"
                :extra-info-items="extraInfoItems"
                :show-score-statuses="showScoreStatuses"
                :badge-pulse-statuses="badgePulseStatuses"
                :use-team-color-glow="useTeamColorGlow"
            />

            <slot name="afterHero" />

            <LinescoreCard
                v-if="showLinescore"
                :title="linescoreTitle"
                :away-team="awayTeam"
                :home-team="homeTeam"
                :away-linescores="awayLinescores"
                :home-linescores="homeLinescores"
                :away-score="awayScore"
                :home-score="homeScore"
                :use-period-numbers="usePeriodNumbers"
                :period-prefix="periodPrefix"
            />

            <slot name="afterLinescore" />
            <slot name="beforePrediction" />

            <PredictionSummaryCard
                v-if="showPredictionSummary && prediction && formatNumber"
                :away-label="awayLabel"
                :home-label="homeLabel"
                :prediction="prediction"
                :format-number="formatNumber"
                :projected-label="projectedLabel"
                :away-bar-class="awayBarClass"
                :home-bar-class="homeBarClass"
            />

            <slot name="afterPrediction" />
            <slot name="beforeTrends" />

            <TrendsComparisonCard
                v-if="showTrends && formatCategoryName && isLockedCategory && formatTierName && getRequiredTier"
                :title="trendsTitle"
                :subtitle="trendsSubtitle"
                :trends-loading="trendsLoading"
                :all-trend-categories="allTrendCategories"
                :format-category-name="formatCategoryName"
                :is-locked-category="isLockedCategory"
                :format-tier-name="formatTierName"
                :get-required-tier="getRequiredTier"
                :away-label="awayLabel"
                :home-label="homeLabel"
                :away-trends="awayTrends"
                :home-trends="homeTrends"
                :empty-text="trendsEmptyText"
            />

            <slot name="afterTrends" />
            <slot />
        </GamePageShell>
    </GamePageErrorBoundary>
</template>
