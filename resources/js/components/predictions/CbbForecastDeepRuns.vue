<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

import { useCbbForecastContext } from '@/composables/useCbbForecast';
const {
    formatPct,
    formatAmericanOdds,
    formatTeam,
    deepRunContenders,
    plusEvTitleEdges,
} = useCbbForecastContext();
</script>
<template>
    <div class="grid gap-4 xl:grid-cols-2">
        <Card>
            <CardHeader>
                <CardTitle>Best Deep-Run Profiles</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <div
                    v-for="row in deepRunContenders"
                    :key="`deep-${row.id}`"
                    class="flex items-center justify-between gap-3 rounded-lg border border-border/60 bg-muted/20 px-3 py-2"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">
                            {{ formatTeam(row.team, row.team_id) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ row.actual_region ?? 'TBD' }} · No.
                            {{ row.actual_seed ?? '-' }}
                        </p>
                    </div>
                    <div class="text-right text-sm">
                        <p>
                            FF
                            {{ formatPct(row.final_four_probability, 2) }}
                        </p>
                        <p class="text-muted-foreground">
                            Title
                            {{ formatPct(row.champion_probability, 2) }}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Best Title Edges</CardTitle>
            </CardHeader>
            <CardContent>
                <div v-if="plusEvTitleEdges.length > 0" class="space-y-3">
                    <div
                        v-for="row in plusEvTitleEdges"
                        :key="`edge-${row.id}`"
                        class="flex items-center justify-between gap-3 rounded-lg border border-emerald-200/60 bg-emerald-50/50 px-3 py-2 dark:border-emerald-900/40 dark:bg-emerald-950/20"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">
                                {{ formatTeam(row.team, row.team_id) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatAmericanOdds(row.market_odds?.price) }}
                                market ·
                                {{ row.actual_region ?? 'TBD' }} No.
                                {{ row.actual_seed ?? '-' }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p
                                class="text-sm font-semibold text-emerald-700 dark:text-emerald-300"
                            >
                                +{{
                                    (
                                        row.market_edge?.edge_percent_points ??
                                        0
                                    ).toFixed(1)
                                }}pp
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatPct(row.champion_probability, 2) }}
                                model
                            </p>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No positive title edges are currently available in the
                    loaded market data.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
