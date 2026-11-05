<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

import { useWcbbForecastContext } from '@/composables/useWcbbForecast';
const {
    formatPct,
    formatAmericanOdds,
    formatTeam,
    projectedInCount,
    bubbleCount,
    avgBidThiefRisk,
    topChampion,
} = useWcbbForecastContext();
</script>
<template>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Card>
            <CardContent class="pt-5">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Projected In
                </p>
                <p class="mt-2 text-3xl font-bold">
                    {{ projectedInCount }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Teams with make probability 50%+
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-5">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Bubble Watch
                </p>
                <p class="mt-2 text-3xl font-bold">
                    {{ bubbleCount }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Teams in 35-75% make range
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-5">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Top Title Odds
                </p>
                <p class="mt-2 text-lg font-bold">
                    {{
                        topChampion
                            ? formatTeam(topChampion.team, topChampion.team_id)
                            : '-'
                    }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        topChampion
                            ? formatPct(topChampion.champion_probability, 2)
                            : '-'
                    }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Market
                    {{
                        topChampion
                            ? formatAmericanOdds(topChampion.market_odds?.price)
                            : '-'
                    }}
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-5">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Avg Bid-Thief Risk
                </p>
                <p class="mt-2 text-3xl font-bold">
                    {{ formatPct(avgBidThiefRisk, 2) }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Across all forecasted teams
                </p>
            </CardContent>
        </Card>
    </div>
</template>
