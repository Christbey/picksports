<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

import { useWcbbForecastContext } from '@/composables/useWcbbForecast';
const {
    formatPct,
    formatEdgePoints,
    toPct,
    formatTeam,
    topByMake,
    statusTag,
    statusTagClass,
    meterClass,
} = useWcbbForecastContext();
</script>
<template>
    <Card>
        <CardHeader>
            <CardTitle>Top Tournament Make Probabilities</CardTitle>
        </CardHeader>
        <CardContent>
            <div
                role="region"
                tabindex="0"
                aria-label="WCBB forecast Teams"
                class="max-w-full min-w-0 overflow-x-auto rounded-md border focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <table class="w-full min-w-[600px] text-sm sm:min-w-0">
                    <thead>
                        <tr class="border-b bg-muted/30 text-left">
                            <th class="p-2 font-medium">Team</th>
                            <th class="p-2 font-medium">Status</th>
                            <th class="p-2 font-medium">Conf</th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Seed ' }}
                            </th>
                            <th class="p-2 font-medium">
                                {{ ' AQ / AL / First 4 ' }}
                            </th>
                            <th class="p-2 font-medium">
                                {{ ' Make Probability ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Edge ' }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in topByMake"
                            :key="row.id"
                            class="border-b"
                        >
                            <td class="p-2 font-medium">
                                {{ formatTeam(row.team, row.team_id) }}
                            </td>
                            <td class="p-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="statusTagClass(row)"
                                >
                                    {{ statusTag(row) }}
                                </span>
                            </td>
                            <td class="p-2 text-muted-foreground">
                                {{ row.team?.conference ?? '-' }}
                            </td>
                            <td class="p-2 text-right">
                                {{ row.projected_seed ?? '-' }}
                            </td>
                            <td class="p-2">
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        class="rounded bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-200"
                                        >AQ
                                        {{
                                            formatPct(row.auto_bid_probability)
                                        }}</span
                                    >
                                    <span
                                        class="rounded bg-indigo-100 px-1.5 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-200"
                                        >AL
                                        {{
                                            formatPct(row.at_large_probability)
                                        }}</span
                                    >
                                    <span
                                        class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-200"
                                        >F4
                                        {{
                                            formatPct(
                                                row.first_four_probability,
                                            )
                                        }}</span
                                    >
                                </div>
                            </td>
                            <td class="p-2">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="h-2.5 w-28 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            class="h-full rounded-full transition-all"
                                            :class="
                                                meterClass(
                                                    row.tournament_make_probability,
                                                )
                                            "
                                            :style="{
                                                width: `${toPct(row.tournament_make_probability)}%`,
                                            }"
                                        />
                                    </div>
                                    <span class="text-xs font-semibold">{{
                                        formatPct(
                                            row.tournament_make_probability,
                                        )
                                    }}</span>
                                </div>
                            </td>
                            <td
                                class="p-2 text-right text-xs font-semibold"
                                :class="
                                    (row.market_edge?.edge_probability ?? 0) >=
                                    0
                                        ? 'text-emerald-600'
                                        : 'text-rose-600'
                                "
                            >
                                <div class="flex items-center justify-end">
                                    <span
                                        class="inline-flex rounded-full px-1.5 py-0.5 text-[10px] font-semibold"
                                        :class="
                                            (row.market_edge?.has_edge ?? false)
                                                ? (row.market_edge
                                                      ?.edge_probability ?? 0) >
                                                  0
                                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200'
                                                    : 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-200'
                                                : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                                        "
                                    >
                                        {{
                                            !(
                                                row.market_edge?.has_edge ??
                                                false
                                            )
                                                ? 'No Edge'
                                                : `${(row.market_edge?.edge_probability ?? 0) > 0 ? '+EV' : '-EV'} ${formatEdgePoints(row.market_edge?.edge_percent_points)}`
                                        }}
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>
