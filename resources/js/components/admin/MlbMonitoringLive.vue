<script setup lang="ts">
import { TabsContent } from '@/components/ui/tabs';
import { useMlbMonitoringContext } from '@/composables/useMlbMonitoring';
const {
    maximumReasonCount,
    formatPercent,
    formatUnits,
    formatDate,
    labelize,
    summary,
    observations,
    no_bet_reasons,
} = useMlbMonitoringContext();
</script>
<template>
    <TabsContent value="live" class="space-y-5">
        <section v-if="no_bet_reasons.length > 0" class="rounded-md border p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold">No-Bet Reasons</h2>
                <span class="text-xs text-muted-foreground">
                    {{ summary.no_bets }} decisions
                </span>
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div
                    v-for="row in no_bet_reasons"
                    :key="row.reason"
                    class="min-w-0"
                >
                    <div class="mb-1 flex justify-between gap-3 text-xs">
                        <span class="truncate">
                            {{ labelize(row.reason) }}
                        </span>
                        <span class="font-semibold tabular-nums">
                            {{ row.count }}
                        </span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-sm bg-muted">
                        <div
                            class="h-full bg-amber-500"
                            :style="{
                                width: `${(row.count / maximumReasonCount) * 100}%`,
                            }"
                        />
                    </div>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-md border">
            <div
                class="flex items-center justify-between border-b bg-muted/40 px-4 py-3"
            >
                <h2 class="text-sm font-semibold">Recent Shadow Ledger</h2>
                <span class="text-xs text-muted-foreground">
                    Latest {{ observations.length }}
                </span>
            </div>
            <div
                v-if="observations.length === 0"
                class="px-6 py-12 text-center text-sm text-muted-foreground"
            >
                No MLB shadow observations yet.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[920px] text-left text-sm">
                    <thead
                        class="border-b bg-muted/20 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Matchup</th>
                            <th class="px-4 py-3 font-medium">Market</th>
                            <th class="px-4 py-3 font-medium">Picksports</th>
                            <th class="px-4 py-3 font-medium">Challenger</th>
                            <th class="px-4 py-3 font-medium">Decision</th>
                            <th class="px-4 py-3 font-medium">Result</th>
                            <th class="px-4 py-3 font-medium">Generated</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in observations" :key="row.id">
                            <td class="px-4 py-3">
                                <div class="font-medium">
                                    {{ row.matchup }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ row.game_date ?? 'Date pending' }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                {{ row.market_type }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatPercent(row.baseline_output) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatPercent(row.challenger_output) }}
                                <div class="text-xs text-muted-foreground">
                                    {{ formatPercent(row.output_delta) }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="row.decision">
                                    {{ labelize(row.decision.status) }}
                                </span>
                                <span v-else class="text-muted-foreground">
                                    Not recorded
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="row.settlement">
                                    {{ labelize(row.settlement.status) }}
                                    ·
                                    {{
                                        formatUnits(row.settlement.profit_units)
                                    }}
                                </span>
                                <span v-else class="text-muted-foreground">
                                    Pending
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ formatDate(row.generated_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </TabsContent>
</template>
