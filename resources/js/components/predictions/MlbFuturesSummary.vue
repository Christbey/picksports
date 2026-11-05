<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { useMlbFuturesContext } from '@/composables/useMlbFutures';
const {
    formatPct,
    formatAmericanOdds,
    formatTeam,
    bubbleWatch,
    projectedInCount,
    avgTitleOdds,
    topChampion,
} = useMlbFuturesContext();
</script>
<template>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Card>
            <CardContent class="pt-5">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Projected Playoff Teams
                </p>
                <p class="mt-2 text-3xl font-bold">
                    {{ projectedInCount }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Teams with playoff make probability 50%+
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
                    {{ bubbleWatch.length }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Teams in 25-75% range
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-5">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Top Champion
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
                    Avg Title Odds
                </p>
                <p class="mt-2 text-3xl font-bold">
                    {{ formatPct(avgTitleOdds, 2) }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Across all tracked teams
                </p>
            </CardContent>
        </Card>
    </div>
</template>
