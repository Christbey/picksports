<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useNbaFuturesContext } from '@/composables/useNbaFutures';
const {
    playoffTeamsPerConference,
    formatPct,
    conferenceBreakdown,
    playInFieldSize,
} = useNbaFuturesContext();
</script>
<template>
    <Card>
        <CardHeader>
            <div class="ui-kicker">Conference View</div>
            <CardTitle class="tracking-tight"
                >Conference Playoff Forecast</CardTitle
            >
        </CardHeader>
        <CardContent>
            <div
                role="region"
                tabindex="0"
                aria-label="NBA conference playoff forecast"
                class="ui-table-wrap max-w-full min-w-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <table class="w-full min-w-[600px] text-sm sm:min-w-0">
                    <thead>
                        <tr class="border-b bg-muted/30 text-left">
                            <th class="p-2 font-medium">Conference</th>
                            <th class="p-2 font-medium">Predicted Winner</th>
                            <th class="p-2 text-right font-medium">
                                Teams Tracked
                            </th>
                            <th class="p-2 text-right font-medium">
                                Projected In
                            </th>
                            <th class="p-2 text-right font-medium">
                                Projected Play-In
                            </th>
                            <th class="p-2 text-right font-medium">
                                Division Leaders In
                            </th>
                            <th class="p-2 text-right font-medium">
                                Expected Bids
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in conferenceBreakdown"
                            :key="`conf-${row.conference}`"
                            class="border-b odd:bg-muted/15"
                        >
                            <td class="p-2 font-medium">
                                {{ row.conference }}
                            </td>
                            <td class="p-2">
                                <div class="flex flex-col">
                                    <span class="font-medium">{{
                                        row.predictedWinner
                                    }}</span>
                                    <span class="text-xs text-muted-foreground"
                                        >Finals
                                        {{
                                            formatPct(
                                                row.winnerFinalsProbability,
                                                1,
                                            )
                                        }}</span
                                    >
                                </div>
                            </td>
                            <td class="p-2 text-right">
                                {{ row.teamsTracked }}
                            </td>
                            <td class="p-2 text-right font-semibold">
                                {{ row.projectedIn }} /
                                {{ playoffTeamsPerConference }}
                            </td>
                            <td class="p-2 text-right">
                                {{ row.projectedPlayIn }} /
                                {{ playInFieldSize }}
                            </td>
                            <td class="p-2 text-right">
                                {{ row.divisionLeadersProjectedIn }}
                                / 3
                            </td>
                            <td class="p-2 text-right">
                                {{ row.expectedBids.toFixed(2) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>
