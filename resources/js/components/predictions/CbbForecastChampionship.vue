<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

import { useCbbForecastContext } from '@/composables/useCbbForecast';
const { formatPct, formatAmericanOdds, formatTeam, titleContenders } =
    useCbbForecastContext();
</script>
<template>
    <Card>
        <CardHeader>
            <CardTitle>Championship Outlook</CardTitle>
        </CardHeader>
        <CardContent>
            <div
                role="region"
                tabindex="0"
                aria-label="CBB forecast Championship"
                class="max-w-full min-w-0 overflow-x-auto rounded-md border focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <table class="w-full min-w-[600px] text-sm sm:min-w-0">
                    <thead>
                        <tr class="border-b bg-muted/30 text-left">
                            <th class="p-2 font-medium">Team</th>
                            <th class="p-2 font-medium">
                                {{ ' Region / Seed ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Final Four ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Title Game ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Champion ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Market ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Edge ' }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in titleContenders"
                            :key="`title-${row.id}`"
                            class="border-b"
                        >
                            <td class="p-2 font-medium">
                                {{ formatTeam(row.team, row.team_id) }}
                            </td>
                            <td class="p-2 text-muted-foreground">
                                {{ row.actual_region ?? 'TBD' }} · No.
                                {{ row.actual_seed ?? '-' }}
                            </td>
                            <td class="p-2 text-right">
                                {{ formatPct(row.final_four_probability, 2) }}
                            </td>
                            <td class="p-2 text-right">
                                {{ formatPct(row.title_game_probability, 2) }}
                            </td>
                            <td class="p-2 text-right font-semibold">
                                {{ formatPct(row.champion_probability, 2) }}
                            </td>
                            <td class="p-2 text-right">
                                {{ formatAmericanOdds(row.market_odds?.price) }}
                            </td>
                            <td
                                class="p-2 text-right"
                                :class="
                                    (row.market_edge?.edge_probability ?? 0) > 0
                                        ? 'text-emerald-600'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{
                                    (row.market_edge?.edge_probability ?? 0) > 0
                                        ? `+${(row.market_edge?.edge_percent_points ?? 0).toFixed(1)}pp`
                                        : '-'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>
