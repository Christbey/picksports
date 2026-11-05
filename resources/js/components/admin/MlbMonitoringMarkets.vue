<script setup lang="ts">
import { TabsContent } from '@/components/ui/tabs';
import { useMlbMonitoringContext } from '@/composables/useMlbMonitoring';
const { formatPercent, formatNumber, formatUnits, market_performance } =
    useMlbMonitoringContext();
</script>
<template>
    <TabsContent value="markets">
        <section class="overflow-hidden rounded-md border">
            <div class="border-b bg-muted/40 px-4 py-3">
                <h2 class="text-sm font-semibold">Performance by Market</h2>
            </div>
            <div
                v-if="market_performance.length === 0"
                class="px-6 py-12 text-center text-sm text-muted-foreground"
            >
                No artifact-linked MLB decisions yet.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[920px] text-left text-sm">
                    <thead class="border-b bg-muted/20 text-xs">
                        <tr>
                            <th class="px-4 py-3">Market</th>
                            <th class="px-4 py-3">Decisions</th>
                            <th class="px-4 py-3">Settled</th>
                            <th class="px-4 py-3">Bets / No bets</th>
                            <th class="px-4 py-3">Profit</th>
                            <th class="px-4 py-3">ROI</th>
                            <th class="px-4 py-3">CLV</th>
                            <th class="px-4 py-3">Brier P / C</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="market in market_performance"
                            :key="market.market"
                        >
                            <td class="px-4 py-3 font-medium">
                                {{ market.market }}
                            </td>
                            <td class="px-4 py-3">
                                {{ market.decisions }}
                            </td>
                            <td class="px-4 py-3">
                                {{ market.settled }}
                            </td>
                            <td class="px-4 py-3">
                                {{ market.bets }} /
                                {{ market.no_bets }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatUnits(market.profit_units) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatPercent(market.roi) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatNumber(market.average_clv, 3) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatNumber(market.baseline_brier) }}
                                /
                                {{ formatNumber(market.challenger_brier) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </TabsContent>
</template>
