<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

import { useNflForecastContext } from '@/composables/useNflForecast';
const { visibleForecasts, formatPct, formatAmericanOdds, formatEdge, isAdmin } =
    useNflForecastContext();
</script>
<template>
    <Card>
        <CardHeader>
            <div class="ui-kicker">Team Forecasts</div>
            <CardTitle>Playoff and Super Bowl Outlook</CardTitle>
        </CardHeader>
        <CardContent>
            <div
                role="region"
                tabindex="0"
                aria-label="NFL forecast Outlook"
                class="ui-table-wrap max-w-full min-w-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <table class="w-full min-w-[920px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="px-3 py-3 font-medium">
                                {{ ' Team ' }}
                            </th>
                            <th class="px-3 py-3 text-right font-medium">
                                {{ ' Wins ' }}
                            </th>
                            <th class="px-3 py-3 text-right font-medium">
                                {{ ' Seed if qualified ' }}
                            </th>
                            <th class="px-3 py-3 text-right font-medium">
                                {{ ' Division ' }}
                            </th>
                            <th class="px-3 py-3 text-right font-medium">
                                {{ ' Playoffs ' }}
                            </th>
                            <th class="px-3 py-3 text-right font-medium">
                                {{ ' Conference ' }}
                            </th>
                            <th class="px-3 py-3 text-right font-medium">
                                {{ ' Super Bowl ' }}
                            </th>
                            <th class="px-3 py-3 text-right font-medium">
                                {{ ' Market ' }}
                            </th>
                            <th class="px-3 py-3 text-right font-medium">
                                {{ ' Difference ' }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="forecast in visibleForecasts"
                            :key="forecast.team_id"
                            class="border-b border-border/60 last:border-0"
                        >
                            <td class="px-3 py-3">
                                <p class="font-semibold">
                                    {{ forecast.team_name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ forecast.conference }}
                                    {{ forecast.division }}
                                </p>
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums">
                                {{ forecast.projected_wins.toFixed(1) }}
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums">
                                {{ forecast.projected_seed?.toFixed(1) ?? '-' }}
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums">
                                {{
                                    formatPct(
                                        forecast.division_winner_probability,
                                    )
                                }}
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums">
                                {{
                                    formatPct(
                                        forecast.make_playoffs_probability,
                                    )
                                }}
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums">
                                {{
                                    formatPct(
                                        forecast.conference_champion_probability,
                                    )
                                }}
                            </td>
                            <td
                                class="px-3 py-3 text-right font-semibold tabular-nums"
                            >
                                {{
                                    formatPct(
                                        forecast.super_bowl_champion_probability,
                                    )
                                }}
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums">
                                {{
                                    formatAmericanOdds(
                                        forecast.market_odds?.price,
                                    )
                                }}
                                <p
                                    v-if="forecast.market_odds"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    {{ forecast.market_odds.bookmaker }}
                                    ·
                                    {{
                                        isAdmin && forecast.market_odds.stale
                                            ? 'Stale'
                                            : 'Last recorded'
                                    }}
                                </p>
                                <p
                                    v-if="forecast.market_odds?.fetched_at"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{
                                        new Date(
                                            forecast.market_odds.fetched_at,
                                        ).toLocaleString()
                                    }}
                                </p>
                            </td>
                            <td
                                class="px-3 py-3 text-right font-medium tabular-nums"
                                :class="
                                    (forecast.market_edge?.edge_probability ??
                                        0) > 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{
                                    formatEdge(
                                        forecast.market_edge?.edge_probability,
                                    )
                                }}
                            </td>
                        </tr>
                        <tr v-if="visibleForecasts.length === 0">
                            <td
                                colspan="9"
                                class="px-3 py-10 text-center text-muted-foreground"
                            >
                                No forecasts match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>
