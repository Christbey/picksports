<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useAdminExportsContext } from '@/composables/useAdminExports';
const {
    signalForPrediction,
    getConfidenceColor,
    getSignalBand,
    formatStatNumber,
    buildPredictionRows,
    exportPrediction,
    props,
} = useAdminExportsContext();
</script>

<template>
    <div
        v-if="props.predictions.length === 0"
        class="rounded-xl border border-sidebar-border bg-background p-10 text-center text-muted-foreground dark:bg-sidebar"
    >
        No game predictions found for the selected filters.
    </div>

    <div
        v-else
        class="overflow-x-auto rounded-xl border border-sidebar-border bg-background dark:bg-sidebar"
    >
        <table class="w-full min-w-[1120px] table-auto">
            <thead class="sticky top-0 z-10 bg-muted">
                <tr>
                    <th
                        class="w-[220px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Matchup
                    </th>
                    <th
                        class="w-[170px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Model Pick
                    </th>
                    <th
                        class="w-[180px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Spread / Total
                    </th>
                    <th
                        class="w-[170px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Signal
                    </th>
                    <th
                        class="min-w-[360px] px-4 py-3 text-left text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Insights
                    </th>
                    <th
                        class="w-[110px] px-4 py-3 text-right text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Export
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(prediction, index) in props.predictions"
                    :key="prediction.id"
                    class="border-t border-border align-top hover:bg-muted/40"
                >
                    <td class="px-4 py-4">
                        <p class="text-base font-semibold text-foreground">
                            {{ prediction.away_team }} @
                            {{ prediction.home_team }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ prediction.game.date ?? 'N/A' }} •
                            {{ prediction.game.time ?? 'TBD' }}
                        </p>
                    </td>
                    <td class="px-4 py-4">
                        <p class="text-sm font-semibold text-foreground">
                            {{ prediction.pick_side }} •
                            {{ prediction.pick_team }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Win Prob
                            {{ formatStatNumber(prediction.win_probability) }}%
                        </p>
                    </td>
                    <td class="px-4 py-4">
                        <p class="text-sm font-semibold text-foreground">
                            Spread
                            {{ formatStatNumber(prediction.predicted_spread) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Total
                            {{ formatStatNumber(prediction.predicted_total) }}
                        </p>
                    </td>
                    <td class="px-4 py-4">
                        <div class="space-y-1">
                            <div
                                class="flex items-center justify-between text-xs text-muted-foreground"
                            >
                                <span>Signal</span>
                                <span class="font-semibold text-foreground"
                                    >{{
                                        signalForPrediction(prediction)
                                    }}%</span
                                >
                            </div>
                            <div
                                class="h-2.5 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full"
                                    :class="
                                        getConfidenceColor(
                                            signalForPrediction(prediction),
                                        )
                                    "
                                    :style="{
                                        width: `${signalForPrediction(prediction)}%`,
                                    }"
                                />
                            </div>
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{
                                    getSignalBand(
                                        signalForPrediction(prediction),
                                    )
                                }}
                            </p>
                        </div>
                    </td>
                    <td class="px-4 py-4">
                        <div class="grid grid-cols-2 gap-1.5">
                            <div
                                v-for="row in buildPredictionRows(
                                    prediction,
                                ).slice(0, 6)"
                                :key="`${prediction.id}-${row.key}`"
                                class="rounded-md border border-border bg-muted/40 px-2 py-1.5"
                            >
                                <p
                                    class="text-[10px] font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ row.label }}
                                </p>
                                <p
                                    class="truncate text-xs font-semibold text-foreground"
                                >
                                    {{ row.value }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-4 text-right">
                        <Button
                            size="sm"
                            @click="exportPrediction(prediction, index)"
                            >Export PNG</Button
                        >
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
