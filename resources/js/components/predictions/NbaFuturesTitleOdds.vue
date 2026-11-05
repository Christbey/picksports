<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { useNbaFuturesContext } from '@/composables/useNbaFutures';
const {
    formatPct,
    formatAmericanOdds,
    toPct,
    formatTeam,
    topByTitle,
    statusTag,
    statusTagClass,
} = useNbaFuturesContext();
</script>
<template>
    <div class="grid gap-4 lg:grid-cols-3">
        <Card v-for="row in topByTitle.slice(0, 12)" :key="`title-${row.id}`">
            <CardContent class="pt-4">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-semibold tracking-tight">
                            {{ formatTeam(row.team, row.team_id) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ row.conference ?? '-' }} · Seed
                            {{ row.projected_seed ?? '-' }}
                        </p>
                    </div>
                    <span
                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="statusTagClass(row)"
                        >{{ statusTag(row) }}</span
                    >
                </div>
                <div class="mt-4 space-y-2">
                    <div>
                        <div
                            class="mb-1 flex items-center justify-between text-xs"
                        >
                            <span class="text-muted-foreground"
                                >Win Division</span
                            >
                            <span class="font-medium">{{
                                formatPct(row.division_win_probability, 2)
                            }}</span>
                        </div>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-amber-500/20"
                        >
                            <div
                                class="h-full rounded-full bg-amber-500"
                                :style="{
                                    width: `${toPct(row.division_win_probability)}%`,
                                }"
                            />
                        </div>
                    </div>
                    <div>
                        <div
                            class="mb-1 flex items-center justify-between text-xs"
                        >
                            <span class="text-muted-foreground"
                                >Conference Finals</span
                            >
                            <span class="font-medium">{{
                                formatPct(row.conference_finals_probability, 2)
                            }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full bg-sky-500"
                                :style="{
                                    width: `${toPct(row.conference_finals_probability)}%`,
                                }"
                            />
                        </div>
                    </div>
                    <div>
                        <div
                            class="mb-1 flex items-center justify-between text-xs"
                        >
                            <span class="text-muted-foreground"
                                >NBA Finals</span
                            >
                            <span class="font-medium">{{
                                formatPct(row.nba_finals_probability, 2)
                            }}</span>
                        </div>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-violet-500/20"
                        >
                            <div
                                class="h-full rounded-full bg-violet-500"
                                :style="{
                                    width: `${toPct(row.nba_finals_probability)}%`,
                                }"
                            />
                        </div>
                    </div>
                    <div>
                        <div
                            class="mb-1 flex items-center justify-between text-xs"
                        >
                            <span class="text-muted-foreground">Champion</span>
                            <span class="font-semibold">{{
                                formatPct(row.champion_probability, 2)
                            }}</span>
                        </div>
                        <div
                            class="h-2.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-emerald-500"
                                :style="{
                                    width: `${toPct(row.champion_probability)}%`,
                                }"
                            />
                        </div>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            Market
                            {{ formatAmericanOdds(row.market_odds?.price) }}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
