<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TeamGamesCard from '@/components/sport-team/TeamGamesCard.vue';
import TeamHeader from '@/components/sport-team/TeamHeader.vue';
import TeamMetricsCard from '@/components/sport-team/TeamMetricsCard.vue';
import TeamPowerRecentCards from '@/components/sport-team/TeamPowerRecentCards.vue';
import TeamRosterCard from '@/components/sport-team/TeamRosterCard.vue';
import TeamSeasonStatsCard from '@/components/sport-team/TeamSeasonStatsCard.vue';
import TeamTrendsLinear from '@/components/sport-team/TeamTrendsLinear.vue';
import TeamTrendsTabbed from '@/components/sport-team/TeamTrendsTabbed.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useSportTeamData } from '@/composables/useSportTeamData';
import AppLayout from '@/layouts/AppLayout.vue';
import type { TeamPageConfig } from '@/types';

const props = defineProps<{
    config: TeamPageConfig;
    team?: any;
    teamId?: number;
    preloadedMetrics?: any;
    preloadedSeasonStats?: any;
    preloadedRecentGames?: any[];
    preloadedUpcomingGames?: any[];
}>();

const {
    teamData,
    teamMetrics,
    seasonStats,
    recentGames,
    powerRanking,
    statRankings,
    rosterPlayers,
    trendsData,
    lockedTrends,
    loading,
    error,
    teamId,
    breadcrumbs,
    formatDate,
    getOpponent,
    getGameResult,
    recentForm,
    recentRecord,
    headerInfoItems,
    trendLabel,
    allTrendCategories,
    displayRecentGames,
    displayUpcomingGames,
    overviewSeasonStatTiles,
} = useSportTeamData(props);
</script>

<template>
    <Head :title="teamData ? config.headTitle(teamData) : 'Team'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div v-if="!teamData && loading" class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-start gap-4">
                <div class="w-20 h-20 bg-muted animate-pulse rounded" />
                <div class="flex-1 space-y-2">
                    <div class="h-8 w-64 bg-muted animate-pulse rounded" />
                    <div class="h-4 w-48 bg-muted animate-pulse rounded" />
                </div>
            </div>
            <div class="h-48 bg-muted animate-pulse rounded" />
        </div>

        <div v-else-if="!teamData && error" class="flex h-full flex-1 flex-col gap-4 p-4">
            <Card>
                <CardContent class="p-6">
                    <p class="text-destructive">{{ error }}</p>
                </CardContent>
            </Card>
        </div>

        <div v-else-if="teamData" class="flex h-full flex-1 flex-col gap-4 p-4">
            <TeamHeader :config="config" :team-data="teamData" :header-info-items="headerInfoItems" />

            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <div v-if="loading" class="space-y-4">
                <Skeleton class="h-32 w-full" />
                <Skeleton class="h-64 w-full" />
                <Skeleton class="h-64 w-full" />
            </div>

            <template v-else>
                <TeamPowerRecentCards
                    :show-power-ranking="config.showPowerRanking"
                    :show-recent-form="config.showRecentForm"
                    :power-ranking="powerRanking"
                    :team-metrics="teamMetrics"
                    :recent-record="recentRecord"
                    :recent-form="recentForm"
                />

                <template v-if="config.useTabs">
                    <Tabs default-value="overview" class="w-full">
                        <TabsList class="w-full">
                            <TabsTrigger value="overview">Overview</TabsTrigger>
                            <TabsTrigger v-if="config.seasonStatTiles" value="stats">Advanced Stats</TabsTrigger>
                            <TabsTrigger v-if="config.showTrends" value="trends">Trends & Insights</TabsTrigger>
                            <TabsTrigger v-if="config.showRoster" value="roster">Roster</TabsTrigger>
                            <TabsTrigger value="schedule">Schedule</TabsTrigger>
                        </TabsList>

                        <TabsContent value="overview">
                            <div class="space-y-4">
                                <TeamMetricsCard
                                    :tiles="config.metricTiles"
                                    :metrics="teamMetrics"
                                    :grid-class="config.metricsGridCols || 'md:grid-cols-5'"
                                />

                                <TeamSeasonStatsCard
                                    v-if="seasonStats"
                                    :season-stats="seasonStats"
                                    :tiles="overviewSeasonStatTiles"
                                    :stat-rankings="statRankings"
                                    :grid-class="config.seasonStatsGridCols || 'md:grid-cols-4 lg:grid-cols-6'"
                                />

                                <TeamGamesCard
                                    title="Recent Games"
                                    :games="recentGames.slice(0, 5)"
                                    :team-id="teamId"
                                    :game-link="config.gameLink"
                                    :get-game-result="getGameResult"
                                    :get-opponent="getOpponent"
                                    :format-date="formatDate"
                                    :show-score="true"
                                />

                                <div v-if="!teamMetrics && !seasonStats && recentGames.length === 0" class="text-center py-8 text-muted-foreground">
                                    <p>No overview data available for this team yet.</p>
                                </div>
                            </div>
                        </TabsContent>

                        <TabsContent v-if="config.seasonStatTiles" value="stats">
                            <TeamSeasonStatsCard
                                v-if="seasonStats"
                                :season-stats="seasonStats"
                                :tiles="config.seasonStatTiles"
                                :stat-rankings="statRankings"
                                :grid-class="config.seasonStatsGridCols || 'md:grid-cols-4 lg:grid-cols-6'"
                            />
                        </TabsContent>

                        <TabsContent v-if="config.showTrends" value="trends">
                            <TeamTrendsTabbed
                                :trends-data="trendsData"
                                :locked-trends="lockedTrends"
                                :trend-label="trendLabel"
                            />
                        </TabsContent>

                        <TabsContent v-if="config.showRoster" value="roster">
                            <TeamRosterCard :roster-players="rosterPlayers" :player-link="config.playerLink" />
                        </TabsContent>

                        <TabsContent value="schedule">
                            <div class="space-y-4">
                                <TeamGamesCard
                                    title="Recent Games"
                                    :games="displayRecentGames"
                                    :team-id="teamId"
                                    :game-link="config.gameLink"
                                    :get-game-result="getGameResult"
                                    :get-opponent="getOpponent"
                                    :format-date="formatDate"
                                    :show-score="true"
                                />

                                <TeamGamesCard
                                    title="Upcoming Games"
                                    :games="displayUpcomingGames"
                                    :team-id="teamId"
                                    :game-link="config.gameLink"
                                    :get-opponent="getOpponent"
                                    :format-date="formatDate"
                                    :show-score="false"
                                />
                            </div>
                        </TabsContent>
                    </Tabs>
                </template>

                <template v-else>
                    <TeamMetricsCard
                        :tiles="config.metricTiles"
                        :metrics="teamMetrics"
                        :grid-class="config.metricsGridCols || 'md:grid-cols-5'"
                    />

                    <TeamSeasonStatsCard
                        v-if="seasonStats && config.seasonStatTiles"
                        :season-stats="seasonStats"
                        :tiles="config.seasonStatTiles"
                        :stat-rankings="statRankings"
                        :grid-class="config.seasonStatsGridCols || 'md:grid-cols-4 lg:grid-cols-6'"
                    />

                    <div :class="config.gamesLayout === 'side-by-side' ? 'grid grid-cols-1 lg:grid-cols-2 gap-4' : 'space-y-4'">
                        <TeamGamesCard
                            title="Recent Games"
                            :games="displayRecentGames"
                            :team-id="teamId"
                            :game-link="config.gameLink"
                            :get-game-result="getGameResult"
                            :get-opponent="getOpponent"
                            :format-date="formatDate"
                            :show-score="true"
                        />

                        <TeamGamesCard
                            title="Upcoming Games"
                            :games="displayUpcomingGames"
                            :team-id="teamId"
                            :game-link="config.gameLink"
                            :get-opponent="getOpponent"
                            :format-date="formatDate"
                            :show-score="false"
                        />
                    </div>

                    <TeamTrendsLinear
                        v-if="config.showTrends"
                        :loading="loading"
                        :all-trend-categories="allTrendCategories"
                        :trends-data="trendsData"
                        :locked-trends="lockedTrends"
                        :trend-label="trendLabel"
                    />
                </template>
            </template>
        </div>
    </AppLayout>
</template>
