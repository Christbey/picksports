<script setup lang="ts">
import { LockKeyhole } from 'lucide-vue-next';
import { TabsContent } from '@/components/ui/tabs';
import {
    formatPercent,
    formatNumber,
    formatSigned,
    formatDate,
    formatGameDate,
    labelize,
    shortHash,
    decisionStatusClass,
    decisionLabel,
    decisionReason,
} from '@/lib/nflMonitoringPresentation';
import { useNflMonitoringContext } from '@/composables/useNflModelMonitoring';
const { artifact, summary, observations, no_bet_reasons, maximumReasonCount } =
    useNflMonitoringContext();
</script>

<template>
    <TabsContent v-if="artifact" value="observations" class="space-y-5">
        <section
            v-if="no_bet_reasons.length > 0"
            class="rounded-md border bg-card p-4"
        >
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold">No-Bet Reasons</h2>
                <span class="text-xs text-muted-foreground">
                    {{ summary.no_bets }} decisions
                </span>
            </div>
            <div class="mt-4 space-y-3">
                <div
                    v-for="row in no_bet_reasons"
                    :key="row.reason"
                    class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3"
                >
                    <div class="min-w-0">
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
                No shadow observations yet.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[1040px] text-left text-sm">
                    <thead
                        class="border-b bg-muted/20 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Matchup</th>
                            <th class="px-4 py-3 font-medium">
                                Win Probability
                            </th>
                            <th class="px-4 py-3 font-medium">Projection</th>
                            <th class="px-4 py-3 font-medium">Decision</th>
                            <th class="px-4 py-3 font-medium">Market</th>
                            <th class="px-4 py-3 font-medium">Provenance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in observations"
                            :key="row.id"
                            class="align-top hover:bg-muted/20"
                        >
                            <td class="px-4 py-4">
                                <div class="font-semibold">
                                    {{ row.matchup }}
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    {{ formatGameDate(row.game_date) }}
                                    ·
                                    {{ labelize(row.game_status) }}
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div
                                    class="flex items-baseline gap-2 tabular-nums"
                                >
                                    <span>
                                        {{ formatPercent(row.baseline_output) }}
                                    </span>
                                    <span class="text-muted-foreground">
                                        →
                                    </span>
                                    <span class="font-semibold">
                                        {{
                                            formatPercent(row.challenger_output)
                                        }}
                                    </span>
                                </div>
                                <div
                                    class="mt-1 text-xs tabular-nums"
                                    :class="
                                        row.output_delta === 0
                                            ? 'text-muted-foreground'
                                            : 'text-sky-700 dark:text-sky-300'
                                    "
                                >
                                    {{ formatSigned(row.output_delta, 3) }}
                                    delta
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div
                                    class="grid grid-cols-[auto_auto_auto] gap-x-3 gap-y-1 text-xs tabular-nums"
                                >
                                    <span class="text-muted-foreground" />
                                    <span class="text-muted-foreground">
                                        Base
                                    </span>
                                    <span class="text-muted-foreground">
                                        Shadow
                                    </span>
                                    <span>Spread</span>
                                    <span>
                                        {{
                                            formatSigned(
                                                row.baseline_outputs
                                                    .predicted_spread,
                                                1,
                                            )
                                        }}
                                    </span>
                                    <span>
                                        {{
                                            formatSigned(
                                                row.challenger_outputs
                                                    .predicted_spread,
                                                1,
                                            )
                                        }}
                                    </span>
                                    <span>Total</span>
                                    <span>
                                        {{
                                            formatNumber(
                                                row.baseline_outputs
                                                    .predicted_total,
                                                1,
                                            )
                                        }}
                                    </span>
                                    <span>
                                        {{
                                            formatNumber(
                                                row.challenger_outputs
                                                    .predicted_total,
                                                1,
                                            )
                                        }}
                                    </span>
                                </div>
                                <div
                                    class="mt-2 text-xs text-muted-foreground tabular-nums"
                                >
                                    Uncertainty
                                    <span class="font-semibold text-foreground">
                                        {{
                                            formatPercent(
                                                row.challenger_outputs
                                                    .uncertainty,
                                            )
                                        }}
                                    </span>
                                    <template
                                        v-if="
                                            row.decision
                                                ?.uncertainty_gate_enabled
                                        "
                                    >
                                        · max
                                        {{
                                            formatPercent(
                                                row.decision
                                                    .maximum_model_uncertainty,
                                            )
                                        }}
                                    </template>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="inline-flex rounded-md border px-2 py-1 text-xs font-semibold"
                                    :class="decisionStatusClass(row)"
                                >
                                    {{ decisionLabel(row) }}
                                </span>
                                <div
                                    class="mt-2 max-w-56 text-xs text-muted-foreground"
                                >
                                    {{ decisionReason(row) }}
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <template v-if="row.decision">
                                    <div class="font-medium">
                                        {{ labelize(row.decision.side) }}
                                        <span
                                            v-if="row.decision.price"
                                            class="tabular-nums"
                                        >
                                            {{
                                                row.decision.price > 0
                                                    ? '+'
                                                    : ''
                                            }}{{ row.decision.price }}
                                        </span>
                                    </div>
                                    <div
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Edge
                                        {{ formatPercent(row.decision.edge) }}
                                    </div>
                                </template>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                >
                                    Awaiting quote
                                </span>
                            </td>
                            <td class="px-4 py-4">
                                <div
                                    class="flex items-center gap-1.5 text-xs"
                                    :class="
                                        row.snapshot.pregame_safe
                                            ? 'text-emerald-700 dark:text-emerald-300'
                                            : 'text-rose-700 dark:text-rose-300'
                                    "
                                >
                                    <LockKeyhole class="h-3.5 w-3.5" />
                                    {{
                                        row.snapshot.pregame_safe
                                            ? 'Pregame safe'
                                            : 'Unsafe'
                                    }}
                                </div>
                                <div
                                    class="mt-2 font-mono text-[11px] text-muted-foreground"
                                    :title="row.snapshot.feature_hash ?? ''"
                                >
                                    {{ shortHash(row.snapshot.feature_hash) }}
                                </div>
                                <div
                                    class="mt-1 text-[11px] text-muted-foreground"
                                >
                                    {{ formatDate(row.generated_at) }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </TabsContent>
</template>
