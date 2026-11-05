<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { PlayerSummaryValue } from '@/types/player-page';
defineProps<{
    seasonSummaryValues: PlayerSummaryValue[] | null;
    gamesCount: number;
}>();
</script>

<template>
    <Card v-if="seasonSummaryValues">
        <CardHeader>
            <CardTitle class="tracking-tight"
                >Season Averages ({{ gamesCount }} games)</CardTitle
            >
        </CardHeader>
        <CardContent>
            <div class="grid grid-cols-3 gap-4 md:grid-cols-5 lg:grid-cols-9">
                <div
                    v-for="card in seasonSummaryValues"
                    :key="card.label"
                    class="ui-surface-subtle p-3 text-center"
                >
                    <div class="text-sm text-muted-foreground">
                        {{ card.label }}
                    </div>
                    <div class="text-2xl font-bold">
                        {{ card.value }}
                    </div>
                    <div
                        v-if="card.rank"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Rank {{ card.rank.rank }} /
                        {{ card.rank.total }} eligible
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
