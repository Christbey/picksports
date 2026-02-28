<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { StatTile } from '@/types';

defineProps<{
    seasonStats: any;
    tiles: StatTile[];
    statRankings?: Record<string, number>;
    gridClass?: string;
}>();
</script>

<template>
    <Card v-if="seasonStats">
        <CardHeader>
            <CardTitle>Season Averages ({{ seasonStats.games_played }} games)</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="grid grid-cols-2 gap-4" :class="gridClass || 'md:grid-cols-4 lg:grid-cols-6'">
                <div v-for="tile in tiles" :key="tile.label" class="text-center p-4 bg-muted/50 rounded-lg">
                    <div class="text-sm text-muted-foreground">{{ tile.label }}</div>
                    <div class="text-2xl font-bold" :class="tile.class?.(seasonStats)">
                        {{ tile.value(seasonStats) }}
                        <span v-if="tile.rankingKey && statRankings && statRankings[tile.rankingKey]" class="text-xs font-normal text-muted-foreground">#{{ statRankings[tile.rankingKey] }}</span>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
