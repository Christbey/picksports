<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

import { useWcbbForecastContext } from '@/composables/useWcbbForecast';
const { formatPct, formatTeam, bubbleRisk, statusTag, statusTagClass } =
    useWcbbForecastContext();
</script>
<template>
    <Card class="border-amber-200/60 dark:border-amber-900/40">
        <CardHeader>
            <CardTitle>Bubble Watch / Bid-Thief Risk</CardTitle>
        </CardHeader>
        <CardContent>
            <div
                class="overflow-x-auto rounded-md border border-amber-200/50 dark:border-amber-900/40"
            >
                <table class="w-full min-w-[600px] text-sm sm:min-w-0">
                    <thead>
                        <tr
                            class="border-b bg-amber-50/60 text-left dark:bg-amber-950/20"
                        >
                            <th class="p-2 font-medium">Team</th>
                            <th class="p-2 font-medium">Status</th>
                            <th class="p-2 text-right font-medium">
                                {{ ' Bid Thief% ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' First 4 (AQ)% ' }}
                            </th>
                            <th class="p-2 text-right font-medium">
                                {{ ' First 4 (AL)% ' }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in bubbleRisk"
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
                            <td class="p-2 text-right">
                                {{ formatPct(row.bid_thief_probability, 2) }}
                            </td>
                            <td class="p-2 text-right">
                                {{
                                    formatPct(
                                        row.first_four_auto_probability,
                                        2,
                                    )
                                }}
                            </td>
                            <td class="p-2 text-right">
                                {{
                                    formatPct(
                                        row.first_four_at_large_probability,
                                        2,
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>
