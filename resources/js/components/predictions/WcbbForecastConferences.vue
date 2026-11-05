<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

import { useWcbbForecastContext } from '@/composables/useWcbbForecast';
const { formatPct, conferenceBreakdown } = useWcbbForecastContext();
</script>
<template>
    <Card>
        <CardHeader>
            <CardTitle>Conference Bid Forecast</CardTitle>
        </CardHeader>
        <CardContent>
            <div
                role="region"
                tabindex="0"
                aria-label="WCBB forecast Conferences"
                class="max-w-full min-w-0 overflow-x-auto rounded-md border focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <table class="w-full min-w-[600px] text-sm sm:min-w-0">
                    <thead>
                        <tr class="border-b bg-muted/30 text-left">
                            <th class="p-2 font-medium">
                                {{ ' Conference ' }}
                            </th>
                            <th class="p-2 font-medium">
                                {{ ' Predicted Winner ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Teams Tracked ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Projected In ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Expected Bids ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Avg Make% ' }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in conferenceBreakdown"
                            :key="`conf-${row.conference}`"
                            class="border-b"
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
                                        >AQ
                                        {{
                                            formatPct(
                                                row.predictedWinnerAutoBidProbability,
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
                                {{ row.projectedIn }}
                            </td>
                            <td class="p-2 text-right">
                                {{ row.expectedBids.toFixed(2) }}
                            </td>
                            <td class="p-2 text-right">
                                {{ formatPct(row.avgMakeProbability) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>
