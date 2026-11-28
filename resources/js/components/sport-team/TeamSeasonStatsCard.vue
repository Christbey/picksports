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
            <CardTitle
                >Season Averages ·
                {{ seasonStats.season ?? 'Scope unavailable' }}
                <span v-if="seasonStats.season_type !== undefined">
                    · phase {{ seasonStats.season_type }}</span
                >
                ({{ seasonStats.games_played }} completed games)</CardTitle
            >
        </CardHeader>
        <CardContent>
            <div
                class="grid grid-cols-2 gap-4"
                :class="gridClass || 'md:grid-cols-4 lg:grid-cols-6'"
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
                        :class="tile.class?.(seasonStats)"
                    >
                        {{ tile.value(seasonStats) }}
                        <span
                            v-if="
                                tile.rankingKey &&
                                statRankings &&
                                statRankings[tile.rankingKey]
                            "
                            class="text-xs font-normal text-muted-foreground"
                            >#{{ statRankings[tile.rankingKey] }}</span
                        >
                    </div>
                    <div
                        v-if="
                            tile.sampleKey &&
                            seasonStats.sample_sizes?.[tile.sampleKey] !==
                                undefined
                        "
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ seasonStats.sample_sizes[tile.sampleKey] }} observed
                        games
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
