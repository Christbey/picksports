<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useMlbFuturesContext } from '@/composables/useMlbFutures';
const {
    formatPct,
    formatEdgePoints,
    toPct,
    formatTeam,
    topByMake,
    statusTag,
    statusTagClass,
    meterClass,
} = useMlbFuturesContext();
</script>
<template>
    <Card>
        <CardHeader>
            <div class="ui-kicker">Team Odds</div>
            <CardTitle class="tracking-tight"
                >Top Playoff Probabilities</CardTitle
            >
        </CardHeader>
        <CardContent>
            <div
                role="region"
                tabindex="0"
                aria-label="MLB team playoff probabilities"
                class="ui-table-wrap max-w-full min-w-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <table class="w-full min-w-[600px] text-sm sm:min-w-0">
                    <thead>
                        <tr class="border-b bg-muted/30 text-left">
                            <th class="p-2 font-medium">Team</th>
                            <th class="p-2 font-medium">Status</th>
                            <th class="p-2 text-right font-medium">
                                League Rank
                            </th>
                            <th class="p-2 text-right font-medium">Seed</th>
                            <th class="p-2 font-medium">Playoff Make</th>
                            <th class="p-2 text-right font-medium">Edge</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in topByMake"
                            :key="row.id"
                            class="border-b odd:bg-muted/15"
                        >
                            <td class="p-2 font-medium">
                                {{ formatTeam(row.team, row.team_id) }}
                            </td>
                            <td class="p-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="statusTagClass(row)"
                                    >{{ statusTag(row) }}</span
                                >
                            </td>
                            <td class="p-2 text-right">
                                {{ row.league_rank ?? '-' }}
                            </td>
                            <td class="p-2 text-right">
                                {{ row.projected_seed ?? '-' }}
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
                                                    row.playoff_make_probability,
                                                )
                                            "
                                            :style="{
                                                width: `${toPct(row.playoff_make_probability)}%`,
                                            }"
                                        />
                                    </div>
                                    <span class="text-xs font-semibold">{{
                                        formatPct(
                                            row.playoff_make_probability,
                                            1,
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
