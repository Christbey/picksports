<script setup lang="ts">
import { TabsContent } from '@/components/ui/tabs';
import {
    formatNumber,
    formatSigned,
    labelize,
    outcomeTone,
    evaluationSeason,
} from '@/lib/nflMonitoringPresentation';
import { useNflMonitoringContext } from '@/composables/useNflModelMonitoring';
const {
    artifact,
    summary,
    evaluation_windows,
    evaluationSummary,
    promotionMarkets,
    worstEvaluationWindow,
} = useNflMonitoringContext();
</script>

<template>
    <TabsContent v-if="artifact" value="evaluation" class="space-y-5">
        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-md border bg-card p-4">
                <div class="text-xs text-muted-foreground">
                    Held-Out Seasons
                </div>
                <div class="mt-2 text-2xl font-bold">
                    {{
                        evaluationSummary.window_count ??
                        evaluation_windows.length
                    }}
                </div>
            </div>
            <div class="rounded-md border bg-card p-4">
                <div class="text-xs text-muted-foreground">Better Seasons</div>
                <div class="mt-2 text-2xl font-bold">
                    {{ evaluationSummary.challenger_better_window_count ?? 0 }}
                </div>
            </div>
            <div class="rounded-md border bg-card p-4">
                <div class="text-xs text-muted-foreground">
                    Average Brier Delta
                </div>
                <div
                    class="mt-2 text-2xl font-bold"
                    :class="outcomeTone(evaluationSummary.avg_brier_delta)"
                >
                    {{ formatSigned(evaluationSummary.avg_brier_delta, 4) }}
                </div>
                <div class="mt-1 text-xs text-muted-foreground">
                    Positive is better
                </div>
            </div>
            <div class="rounded-md border bg-card p-4">
                <div class="text-xs text-muted-foreground">Worst Season</div>
                <div class="mt-2 text-2xl font-bold">
                    {{
                        worstEvaluationWindow
                            ? evaluationSeason(worstEvaluationWindow)
                            : 'N/A'
                    }}
                </div>
                <div
                    class="mt-1 text-xs tabular-nums"
                    :class="outcomeTone(worstEvaluationWindow?.brier_delta)"
                >
                    {{ formatSigned(worstEvaluationWindow?.brier_delta, 4) }}
                    Brier
                </div>
            </div>
            <div class="rounded-md border bg-card p-4">
                <div class="text-xs text-muted-foreground">
                    Live Brier Delta
                </div>
                <div
                    class="mt-2 text-2xl font-bold"
                    :class="outcomeTone(summary.brier_delta)"
                >
                    {{ formatSigned(summary.brier_delta, 4) }}
                </div>
                <div class="mt-1 text-xs text-muted-foreground">
                    {{ summary.calibration_games }} games
                </div>
            </div>
        </section>

        <section
            v-if="promotionMarkets.length > 0"
            class="rounded-md border bg-card"
        >
            <div
                class="flex items-center justify-between gap-3 border-b px-4 py-3"
            >
                <h2 class="text-sm font-semibold">
                    Market Promotion Decisions
                </h2>
                <span class="text-xs text-muted-foreground">
                    Evaluated independently
                </span>
            </div>
            <div class="grid gap-3 p-4 md:grid-cols-3">
                <div
                    v-for="[market, decision] in promotionMarkets"
                    :key="market"
                    class="rounded-md border p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold">
                            {{ labelize(market) }}
                        </span>
                        <span
                            class="rounded-md border px-2 py-1 text-xs font-semibold"
                            :class="
                                decision.promoted || decision.promotion_ready
                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                    : decision.eligible
                                      ? 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                      : 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'
                            "
                        >
                            {{
                                decision.promoted
                                    ? 'Promoted'
                                    : decision.promotion_ready
                                      ? 'Ready'
                                      : decision.eligible
                                        ? 'Offline Eligible'
                                        : decision.available
                                          ? 'Blocked'
                                          : 'No Data'
                            }}
                        </span>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                        <div>
                            <dt class="text-muted-foreground">
                                Better Seasons
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{ decision.challenger_better_window_count }}
                                /
                                {{ decision.window_count }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Avg Improvement
                            </dt>
                            <dd
                                class="mt-1 font-semibold tabular-nums"
                                :class="
                                    outcomeTone(
                                        decision.average_primary_improvement,
                                    )
                                "
                            >
                                {{
                                    formatSigned(
                                        decision.average_primary_improvement,
                                        4,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-muted-foreground">
                                Worst Regression
                            </dt>
                            <dd
                                class="mt-1 font-semibold tabular-nums"
                                :class="
                                    (decision.worst_primary_window_regression ??
                                        0) > 0
                                        ? 'text-rose-700 dark:text-rose-300'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{
                                    formatNumber(
                                        decision.worst_primary_window_regression,
                                        4,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-md border">
            <div class="border-b bg-muted/40 px-4 py-3">
                <h2 class="text-sm font-semibold">Chronological Evaluation</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead
                        class="border-b bg-muted/20 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Season</th>
                            <th class="px-4 py-3 font-medium">Games</th>
                            <th class="px-4 py-3 font-medium">
                                Baseline Brier
                            </th>
                            <th class="px-4 py-3 font-medium">
                                Challenger Brier
                            </th>
                            <th class="px-4 py-3 font-medium">Brier Delta</th>
                            <th class="px-4 py-3 font-medium">
                                Log Loss Delta
                            </th>
                            <th class="px-4 py-3 font-medium">
                                Spread MAE Delta
                            </th>
                            <th class="px-4 py-3 font-medium">
                                Total MAE Delta
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="window in evaluation_windows"
                            :key="evaluationSeason(window)"
                        >
                            <td class="px-4 py-3 font-semibold">
                                {{ evaluationSeason(window) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ window.games }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatNumber(window.baseline_brier, 4) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatNumber(window.challenger_brier, 4) }}
                            </td>
                            <td
                                class="px-4 py-3 font-semibold tabular-nums"
                                :class="outcomeTone(window.brier_delta)"
                            >
                                {{ formatSigned(window.brier_delta, 4) }}
                            </td>
                            <td
                                class="px-4 py-3 tabular-nums"
                                :class="outcomeTone(window.log_loss_delta)"
                            >
                                {{ formatSigned(window.log_loss_delta, 4) }}
                            </td>
                            <td
                                class="px-4 py-3 tabular-nums"
                                :class="outcomeTone(window.spread_mae_delta)"
                            >
                                {{ formatSigned(window.spread_mae_delta, 3) }}
                            </td>
                            <td
                                class="px-4 py-3 tabular-nums"
                                :class="outcomeTone(window.total_mae_delta)"
                            >
                                {{ formatSigned(window.total_mae_delta, 3) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </TabsContent>
</template>
