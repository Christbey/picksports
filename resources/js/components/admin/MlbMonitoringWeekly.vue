<script setup lang="ts">
import { TabsContent } from '@/components/ui/tabs';
import { useMlbMonitoringContext } from '@/composables/useMlbMonitoring';
const { formatPercent, formatNumber, weekly_performance } =
    useMlbMonitoringContext();
</script>
<template>
    <TabsContent value="weekly">
        <section class="overflow-hidden rounded-md border">
            <div class="border-b bg-muted/40 px-4 py-3">
                <h2 class="text-sm font-semibold">
                    Rolling Weekly Performance
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Lower Brier, log loss, and MAE are better.
                </p>
            </div>
            <div
                v-if="weekly_performance.length === 0"
                class="px-6 py-12 text-center text-sm text-muted-foreground"
            >
                Settled shadow games will populate weekly comparisons.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[1180px] text-left text-sm">
                    <thead class="border-b bg-muted/20 text-xs">
                        <tr>
                            <th class="px-4 py-3">Week</th>
                            <th class="px-4 py-3">Games</th>
                            <th class="px-4 py-3">Brier P / C</th>
                            <th class="px-4 py-3">Log loss P / C</th>
                            <th class="px-4 py-3">Accuracy P / C</th>
                            <th class="px-4 py-3">Margin MAE P / C</th>
                            <th class="px-4 py-3">Total MAE P / C</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="week in weekly_performance"
                            :key="week.week_start"
                        >
                            <td class="px-4 py-3 font-medium">
                                {{ week.week_start }}
                            </td>
                            <td class="px-4 py-3">
                                {{ week.games }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatNumber(week.baseline_brier) }}
                                /
                                {{ formatNumber(week.challenger_brier) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatNumber(week.baseline_log_loss) }}
                                /
                                {{ formatNumber(week.challenger_log_loss) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatPercent(week.baseline_accuracy) }}
                                /
                                {{ formatPercent(week.challenger_accuracy) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatNumber(week.baseline_margin_mae, 2) }}
                                /
                                {{
                                    formatNumber(week.challenger_margin_mae, 2)
                                }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatNumber(week.baseline_total_mae, 2) }}
                                /
                                {{ formatNumber(week.challenger_total_mae, 2) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </TabsContent>
</template>
