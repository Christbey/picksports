<script setup lang="ts">
import { formatNumber, ratingClass } from '@/components/sport-team-metrics-helpers';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    showPowerRanking?: boolean;
    showRecentForm?: boolean;
    powerRanking: { rank: number; total_teams: number } | null;
    teamMetrics: any;
    recentRecord: { wins: number; losses: number; games: number };
    recentForm: unknown[];
}>();
</script>

<template>
    <div v-if="showPowerRanking || showRecentForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Card v-if="showPowerRanking && powerRanking">
            <CardHeader>
                <CardTitle>Power Ranking</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-4xl font-bold">#{{ powerRanking.rank }}</div>
                        <div class="text-sm text-muted-foreground mt-1">of {{ powerRanking.total_teams }} teams</div>
                    </div>
                    <div v-if="teamMetrics" class="text-right">
                        <div class="text-2xl font-semibold" :class="ratingClass(teamMetrics.net_rating)">
                            {{ teamMetrics.net_rating > 0 ? '+' : '' }}{{ formatNumber(teamMetrics.net_rating) }}
                        </div>
                        <div class="text-xs text-muted-foreground mt-1">Net Rating</div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card v-if="showRecentForm && recentRecord.games > 0">
            <CardHeader>
                <CardTitle>Recent Form</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-4xl font-bold">
                            {{ recentRecord.wins }}-{{ recentRecord.losses }}
                        </div>
                        <div class="text-sm text-muted-foreground mt-1">Last {{ recentRecord.games }} Games</div>
                    </div>
                    <div class="text-right">
                        <div class="flex gap-1 justify-end">
                            <span
                                v-for="(result, idx) in recentForm"
                                :key="idx"
                                :class="[
                                    'inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold',
                                    result === 'W' ? 'bg-green-600 text-white' : 'bg-red-600 text-white',
                                ]"
                            >
                                {{ result }}
                            </span>
                        </div>
                        <div class="text-xs text-muted-foreground mt-1">Recent Results</div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
