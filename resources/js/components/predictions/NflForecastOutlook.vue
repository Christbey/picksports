<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useNflForecastContext } from '@/composables/useNflForecast';

const { visibleForecasts, formatPct, formatAmericanOdds, formatEdge, isAdmin } =
    useNflForecastContext();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle id="nfl-forecast-outlook">
                Playoff and Super Bowl Outlook
            </CardTitle>
        </CardHeader>
        <CardContent class="px-3 sm:px-6">
            <table
                aria-labelledby="nfl-forecast-outlook"
                class="w-full text-xs sm:text-sm"
            >
                <thead class="border-b bg-muted/40 text-muted-foreground">
                    <tr class="*:px-1.5 *:py-2 *:font-medium sm:*:px-3">
                        <th scope="col" class="text-left">Team</th>
                        <th scope="col" class="text-right">Playoffs</th>
                        <th scope="col" class="text-right">Super Bowl</th>
                        <th scope="col" class="text-right">Super Bowl odds</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    <tr
                        v-for="forecast in visibleForecasts"
                        :key="forecast.team_id"
                        class="align-top *:px-1.5 *:py-3 hover:bg-muted/30 sm:*:px-3"
                    >
                        <th scope="row" class="text-left font-normal">
                            <div class="font-semibold">
                                {{ forecast.team_name }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ forecast.conference }}
                                {{ forecast.division }}
                            </div>
                            <div
                                class="mt-1 text-xs text-muted-foreground tabular-nums"
                            >
                                <span
                                    >{{
                                        forecast.projected_wins.toFixed(1)
                                    }}
                                    wins</span
                                >
                                <span
                                    v-if="forecast.projected_seed != null"
                                    class="block"
                                >
                                    Avg. seed
                                    {{ forecast.projected_seed.toFixed(1) }}
                                </span>
                            </div>
                        </th>
                        <td class="text-right tabular-nums">
                            <div class="font-semibold">
                                {{
                                    formatPct(
                                        forecast.make_playoffs_probability,
                                    )
                                }}
                            </div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                <div>
                                    Division
                                    {{
                                        formatPct(
                                            forecast.division_winner_probability,
                                        )
                                    }}
                                </div>
                                <div>
                                    Wild card
                                    {{
                                        formatPct(
                                            forecast.wild_card_probability,
                                        )
                                    }}
                                </div>
                            </div>
                        </td>
                        <td class="text-right tabular-nums">
                            <div class="font-semibold">
                                {{
                                    formatPct(
                                        forecast.super_bowl_champion_probability,
                                    )
                                }}
                            </div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                Conference
                                {{
                                    formatPct(
                                        forecast.conference_champion_probability,
                                    )
                                }}
                            </div>
                        </td>
                        <td class="text-right tabular-nums">
                            <div class="font-semibold">
                                {{
                                    formatAmericanOdds(
                                        forecast.market_odds?.price,
                                    )
                                }}
                                <span
                                    v-if="
                                        isAdmin && forecast.market_odds?.stale
                                    "
                                    class="block text-xs font-medium text-amber-700 sm:ml-1 sm:inline dark:text-amber-400"
                                >
                                    Stale
                                </span>
                            </div>
                            <div
                                v-if="forecast.market_odds"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                <div class="wrap-anywhere">
                                    {{ forecast.market_odds.bookmaker }}
                                </div>
                                <time
                                    v-if="forecast.market_odds.fetched_at"
                                    :datetime="forecast.market_odds.fetched_at"
                                    :title="
                                        new Date(
                                            forecast.market_odds.fetched_at,
                                        ).toLocaleString()
                                    "
                                >
                                    {{
                                        new Date(
                                            forecast.market_odds.fetched_at,
                                        ).toLocaleDateString(undefined, {
                                            month: 'short',
                                            day: 'numeric',
                                        })
                                    }}
                                </time>
                            </div>
                            <div
                                v-if="
                                    forecast.market_edge?.edge_probability !=
                                    null
                                "
                                class="mt-1 text-xs font-medium"
                                :class="
                                    forecast.market_edge.edge_probability > 0
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{
                                    formatEdge(
                                        forecast.market_edge.edge_probability,
                                    )
                                }}
                                model difference
                            </div>
                        </td>
                    </tr>
                    <tr v-if="visibleForecasts.length === 0">
                        <td
                            colspan="4"
                            class="px-3 py-10 text-center text-muted-foreground"
                        >
                            No forecasts match these filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </CardContent>
    </Card>
</template>
