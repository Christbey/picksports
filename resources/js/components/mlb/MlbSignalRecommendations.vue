<script setup lang="ts">
import type { SignalRow } from '@/types/mlb-signals';
import {
    formatPercent,
    formatRuns,
    formatNumber,
    formatAmericanOdds,
    formatProbabilityEdge,
    labelize,
    primaryReason,
    riskSummary,
    pickLabel,
    classificationClass,
    scoreBarClass,
} from '@/lib/mlbSignalPresentation';
defineProps<{ bestBets: SignalRow[] }>();
</script>

<template>
    <div
        v-if="bestBets.length > 0"
        class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
    >
        <div
            v-for="row in bestBets.slice(0, 8)"
            :key="`best-bet-${row.type}-${row.game_id}-${row.pick_side}`"
            class="rounded-lg border border-border/80 p-3"
        >
            <div class="mb-2 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold">
                        {{ row.team_name || row.matchup }}
                    </div>
                    <div class="truncate text-xs text-muted-foreground">
                        {{ pickLabel(row) }} | {{ row.matchup }}
                    </div>
                </div>
                <div class="shrink-0 text-right">
                    <div
                        class="rounded-full border px-2 py-1 text-xs font-semibold uppercase"
                        :class="classificationClass(row.classification)"
                    >
                        {{ row.classification }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        {{ row.score ?? 0 }} score
                    </div>
                </div>
            </div>
            <div class="mb-2 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full"
                    :class="scoreBarClass(row.score)"
                    :style="{ width: `${row.score ?? 0}%` }"
                />
            </div>
            <div class="grid gap-2 text-xs sm:grid-cols-3">
                <div class="rounded-md bg-muted/40 px-2 py-1">
                    <div class="text-muted-foreground">Model</div>
                    <div class="font-medium">
                        {{
                            formatPercent(
                                row.model_probability ?? row.win_probability,
                            ) ??
                            formatRuns(row.edge_runs) ??
                            '-'
                        }}
                    </div>
                </div>
                <div class="rounded-md bg-muted/40 px-2 py-1">
                    <div class="text-muted-foreground">Market</div>
                    <div class="font-medium">
                        {{
                            row.market_price != null
                                ? formatAmericanOdds(row.market_price)
                                : formatNumber(row.market_line)
                        }}
                    </div>
                </div>
                <div class="rounded-md bg-muted/40 px-2 py-1">
                    <div class="text-muted-foreground">Edge</div>
                    <div class="font-medium">
                        {{
                            formatProbabilityEdge(row.probability_edge) ??
                            formatRuns(row.edge_runs) ??
                            '-'
                        }}
                    </div>
                </div>
            </div>
            <div
                class="mt-2 flex flex-wrap gap-1.5 text-xs text-muted-foreground"
            >
                <span
                    v-for="reason in row.reason_codes?.slice(0, 3) ?? [
                        primaryReason(row),
                    ]"
                    :key="`${row.game_id}-${row.type}-${reason}`"
                    class="rounded-md border px-2 py-1"
                >
                    {{ labelize(reason) }}
                </span>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
                Risk: {{ riskSummary(row) }}
            </div>
        </div>
    </div>
    <div v-else class="text-sm text-muted-foreground">
        No games passed the selective bet filter for this slate.
    </div>
</template>
