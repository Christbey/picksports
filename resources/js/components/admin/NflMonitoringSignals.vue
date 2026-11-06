<script setup lang="ts">
import { ListChecks } from 'lucide-vue-next';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    formatPercent,
    formatSigned,
    labelize,
    improvementTone,
} from '@/lib/nflMonitoringPresentation';
import { useNflMonitoringContext } from '@/composables/useNflModelMonitoring';
const {
    artifact,
    signal_grades,
    signal_report_generated_at,
    activeSignalType,
    selectedSignalKeys,
    signalGradesLoading,
    activeSignalCategory,
    activeSignalWindows,
    selectSignal,
    signalIsSelected,
} = useNflMonitoringContext();
</script>

<template>
    <TabsContent v-if="artifact" value="signals" class="space-y-5">
        <p class="text-sm text-muted-foreground">
            {{
                signal_report_generated_at
                    ? `Signal report generated: ${signal_report_generated_at}`
                    : 'Signal report is being prepared by the scheduled refresh.'
            }}
        </p>
        <p v-if="signalGradesLoading" class="text-sm text-muted-foreground">
            Loading signal grades…
        </p>
        <p class="text-sm text-muted-foreground">
            Outcome rates use the latest graded pregame observation per game and
            signal. Repeated model runs do not add independent games. All
            recorded decision settlements remain in profit tracking.
        </p>
        <section class="overflow-hidden rounded-md border">
            <div
                class="flex flex-col gap-3 border-b bg-muted/40 px-4 py-3 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex items-center gap-2">
                    <ListChecks class="h-4 w-4 text-teal-600" />
                    <h2 class="text-sm font-semibold">
                        Settlement-Backed Signal Grades
                    </h2>
                </div>
                <Tabs v-model="activeSignalType" class="w-full lg:w-auto">
                    <TabsList
                        aria-label="Signal type"
                        class="grid h-auto w-full grid-cols-2 lg:grid-cols-5"
                    >
                        <TabsTrigger
                            v-for="category in signal_grades"
                            :key="category.signal_type"
                            :value="category.signal_type"
                            class="text-xs"
                        >
                            {{ category.label }}
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
            </div>

            <div
                v-if="
                    !activeSignalCategory ||
                    activeSignalCategory.signals.length === 0
                "
                class="px-6 py-12 text-center text-sm text-muted-foreground"
            >
                No settled signal grades yet.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[1260px] text-left text-sm">
                    <thead
                        class="border-b bg-muted/20 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Signal</th>
                            <th class="px-4 py-3 font-medium">Sample</th>
                            <th class="px-4 py-3 font-medium">Winner</th>
                            <th class="px-4 py-3 font-medium">ATS</th>
                            <th class="px-4 py-3 font-medium">Total</th>
                            <th class="px-4 py-3 font-medium">ROI</th>
                            <th class="px-4 py-3 font-medium">CLV</th>
                            <th class="px-4 py-3 font-medium">
                                Calibration Lift
                            </th>
                            <th class="px-4 py-3 font-medium">Error Lift</th>
                            <th class="px-4 py-3 font-medium">
                                Window Stability
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in activeSignalCategory.signals"
                            :key="`${row.signal_type}:${row.signal_key}`"
                            class="cursor-pointer align-top transition-colors hover:bg-muted/20"
                            :class="{
                                'bg-muted/40': signalIsSelected(row),
                            }"
                            @click="selectSignal(row)"
                        >
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    class="max-w-64 text-left font-semibold"
                                    @click.stop="selectSignal(row)"
                                >
                                    {{ labelize(row.signal_key) }}
                                </button>
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ row.unique_game_count }}
                                games
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium tabular-nums">
                                    {{ formatPercent(row.winner_accuracy) }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    n={{ row.winner_sample }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium tabular-nums">
                                    {{ formatPercent(row.ats_hit_rate) }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    n={{ row.ats_sample }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium tabular-nums">
                                    {{ formatPercent(row.total_hit_rate) }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    n={{ row.total_sample }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="font-medium tabular-nums"
                                    :class="improvementTone(row.roi)"
                                >
                                    {{ formatPercent(row.roi) }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    Shadow
                                    {{ formatPercent(row.shadow_roi) }}
                                </div>
                            </td>
                            <td
                                class="px-4 py-3 font-medium tabular-nums"
                                :class="improvementTone(row.avg_clv)"
                            >
                                {{ formatSigned(row.avg_clv, 4) }}
                            </td>
                            <td
                                class="px-4 py-3 font-medium tabular-nums"
                                :class="
                                    improvementTone(row.avg_calibration_lift)
                                "
                            >
                                {{ formatSigned(row.avg_calibration_lift, 4) }}
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="tabular-nums"
                                    :class="
                                        improvementTone(
                                            row.avg_spread_error_lift,
                                        )
                                    "
                                >
                                    Spread
                                    {{
                                        formatSigned(
                                            row.avg_spread_error_lift,
                                            2,
                                        )
                                    }}
                                </div>
                                <div
                                    class="mt-1 tabular-nums"
                                    :class="
                                        improvementTone(
                                            row.avg_total_error_lift,
                                        )
                                    "
                                >
                                    Total
                                    {{
                                        formatSigned(
                                            row.avg_total_error_lift,
                                            2,
                                        )
                                    }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium tabular-nums">
                                    {{ row.positive_roi_windows ?? 0 }}/{{
                                        row.roi_window_count ?? 0
                                    }}
                                    positive ROI
                                </div>
                                <div
                                    class="mt-1 text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ row.window_count ?? 0 }}
                                    seasons · range
                                    {{
                                        formatPercent(
                                            row.winner_accuracy_range ?? null,
                                        )
                                    }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="overflow-hidden rounded-md border">
            <div
                class="flex items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3"
            >
                <h2 class="text-sm font-semibold">Chronological Windows</h2>
                <span class="truncate text-xs text-muted-foreground">
                    {{ labelize(selectedSignalKeys[activeSignalType]) }}
                </span>
            </div>
            <div
                v-if="activeSignalWindows.length === 0"
                class="px-6 py-10 text-center text-sm text-muted-foreground"
            >
                No completed season windows.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[1040px] text-left text-sm">
                    <thead
                        class="border-b bg-muted/20 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Season</th>
                            <th class="px-4 py-3 font-medium">Sample</th>
                            <th class="px-4 py-3 font-medium">
                                Winner / ATS / Total
                            </th>
                            <th class="px-4 py-3 font-medium">ROI / CLV</th>
                            <th class="px-4 py-3 font-medium">
                                Calibration Lift
                            </th>
                            <th class="px-4 py-3 font-medium">
                                Spread / Total Error Lift
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="window in activeSignalWindows"
                            :key="`${window.signal_type}:${window.signal_key}:${window.season}`"
                        >
                            <td class="px-4 py-3 font-semibold">
                                {{ window.season }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ window.unique_game_count }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatPercent(window.winner_accuracy) }}
                                /
                                {{ formatPercent(window.ats_hit_rate) }}
                                /
                                {{ formatPercent(window.total_hit_rate) }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="font-medium tabular-nums"
                                    :class="improvementTone(window.roi)"
                                >
                                    {{ formatPercent(window.roi) }}
                                </span>
                                <span class="text-muted-foreground"> / </span>
                                <span
                                    class="font-medium tabular-nums"
                                    :class="improvementTone(window.avg_clv)"
                                >
                                    {{ formatSigned(window.avg_clv, 4) }}
                                </span>
                            </td>
                            <td
                                class="px-4 py-3 font-medium tabular-nums"
                                :class="
                                    improvementTone(window.avg_calibration_lift)
                                "
                            >
                                {{
                                    formatSigned(window.avg_calibration_lift, 4)
                                }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="tabular-nums"
                                    :class="
                                        improvementTone(
                                            window.avg_spread_error_lift,
                                        )
                                    "
                                >
                                    {{
                                        formatSigned(
                                            window.avg_spread_error_lift,
                                            2,
                                        )
                                    }}
                                </span>
                                <span class="text-muted-foreground"> / </span>
                                <span
                                    class="tabular-nums"
                                    :class="
                                        improvementTone(
                                            window.avg_total_error_lift,
                                        )
                                    "
                                >
                                    {{
                                        formatSigned(
                                            window.avg_total_error_lift,
                                            2,
                                        )
                                    }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </TabsContent>
</template>
