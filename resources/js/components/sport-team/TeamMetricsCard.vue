<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { MetricTile } from '@/types';

defineProps<{
    title?: string;
    tiles: MetricTile[];
    metrics: any;
    metricRankings?: Record<string, number>;
    rankingTotalTeams?: number;
    rankingSampleCounts?: Record<string, number>;
    gridClass?: string;
}>();
</script>

<template>
    <Card v-if="metrics">
        <CardHeader>
            <CardTitle
                >{{ title || 'Stored Season Metrics'
                }}<span
                    v-if="
                        metrics.season !== null && metrics.season !== undefined
                    "
                >
                    · {{ metrics.season }}</span
                ></CardTitle
            >
        </CardHeader>
        <CardContent>
            <div
                class="grid grid-cols-2 gap-4"
                :class="gridClass || 'md:grid-cols-5'"
            >
                <div
                    v-for="tile in tiles"
                    :key="tile.label"
                    class="rounded-lg bg-muted/50 p-4 text-center"
                >
                    <div class="text-sm text-muted-foreground">
                        {{ tile.label }}
                    </div>
                    <div
                        class="text-2xl font-bold"
                        :class="tile.class?.(metrics)"
                    >
                        {{ tile.value(metrics) }}
                    </div>
                    <div
                        v-if="
                            tile.rankingKey &&
                            metricRankings &&
                            metricRankings[tile.rankingKey] &&
                            rankingTotalTeams
                        "
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Rank {{ metricRankings[tile.rankingKey] }} /
                        {{
                            rankingSampleCounts?.[tile.rankingKey] ??
                            rankingTotalTeams
                        }}
                        observed
                        <span
                            v-if="
                                rankingSampleCounts?.[tile.rankingKey] !==
                                    undefined &&
                                rankingSampleCounts[tile.rankingKey] <
                                    rankingTotalTeams
                            "
                            >({{ rankingTotalTeams }} teams in scope)</span
                        >
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
