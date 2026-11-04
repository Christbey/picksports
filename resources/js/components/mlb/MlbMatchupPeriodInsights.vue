<script setup lang="ts">
import type { ApiV2Prediction } from '@/types';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';
import { labelizeMlbCode } from '@/lib/mlbRecommendationLabels';
import {
    teamAbbreviation,
    formatPercent,
    periodInsights,
    periodStateLabel,
    periodStateClass,
    periodRecord,
    periodLeanLabel,
    signedNumber,
    periodSignalLabel,
    periodRiskLabel,
} from '@/lib/mlbMatchupPresentation';
defineProps<{ prediction: ApiV2Prediction; candidate?: MlbDailyPick | null }>();
</script>

<template>
    <section
        v-if="periodInsights(prediction).length"
        class="rounded-2xl border p-4"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm font-semibold">F3/F5 Insights</div>
            <span class="text-xs text-muted-foreground">
                Pregame point-in-time form
            </span>
        </div>

        <div
            v-for="insight in periodInsights(prediction)"
            :key="insight.market_type"
            class="mt-4 border-t pt-4 first:mt-3"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="font-semibold">{{ insight.label }}</span>
                    <span class="text-sm text-muted-foreground">
                        {{ periodLeanLabel(insight) }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                        :class="periodStateClass(insight.state)"
                    >
                        {{ periodStateLabel(insight.state) }}
                    </span>
                    <span
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                    >
                        {{ labelizeMlbCode(insight.confidence.level) }}
                        ·
                        {{ insight.confidence.sample_games }} games
                    </span>
                </div>
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div class="border-l-2 border-sky-500/50 pl-3">
                    <div class="text-xs text-muted-foreground">
                        {{ teamAbbreviation(prediction.game?.away_team) }}
                        away
                    </div>
                    <div class="mt-1 font-semibold">
                        {{ periodRecord(insight.away) }}
                        ·
                        {{ formatPercent(insight.away.win_probability) }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        L10
                        {{
                            formatPercent(insight.away.last_10.win_probability)
                        }}
                        · RD/G
                        {{ signedNumber(insight.away.run_difference_per_game) }}
                        · Tie
                        {{ formatPercent(insight.away.tie_rate) }}
                    </div>
                </div>
                <div class="border-l-2 border-emerald-500/50 pl-3">
                    <div class="text-xs text-muted-foreground">
                        {{ teamAbbreviation(prediction.game?.home_team) }}
                        home
                    </div>
                    <div class="mt-1 font-semibold">
                        {{ periodRecord(insight.home) }}
                        ·
                        {{ formatPercent(insight.home.win_probability) }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        L10
                        {{
                            formatPercent(insight.home.last_10.win_probability)
                        }}
                        · RD/G
                        {{ signedNumber(insight.home.run_difference_per_game) }}
                        · Tie
                        {{ formatPercent(insight.home.tie_rate) }}
                    </div>
                </div>
            </div>

            <div
                class="mt-3 grid grid-cols-2 gap-3 border-t pt-3 text-sm sm:grid-cols-4"
            >
                <div>
                    <div class="text-xs text-muted-foreground">
                        Home Elo lean
                    </div>
                    <div class="font-semibold">
                        {{
                            formatPercent(insight.lean.two_way_home_probability)
                        }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-muted-foreground">
                        Period Elo diff
                    </div>
                    <div class="font-semibold">
                        {{
                            signedNumber(insight.lean.period_elo_difference, 1)
                        }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-muted-foreground">
                        Starter diff
                    </div>
                    <div class="font-semibold">
                        {{
                            signedNumber(
                                insight.starter_context.rating_difference,
                                1,
                            )
                        }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-muted-foreground">Market</div>
                    <div class="font-semibold">
                        {{ insight.market_available ? 'Captured' : 'Missing' }}
                    </div>
                </div>
            </div>

            <div
                v-if="insight.signals.length || insight.risk_flags.length"
                class="mt-3 flex flex-wrap gap-2"
            >
                <span
                    v-for="signal in insight.signals"
                    :key="signal"
                    class="rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300"
                >
                    {{ periodSignalLabel(signal) }}
                </span>
                <span
                    v-for="risk in insight.risk_flags"
                    :key="risk"
                    class="rounded-full border border-amber-500/25 bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-700 dark:text-amber-300"
                >
                    {{ periodRiskLabel(risk) }}
                </span>
            </div>
        </div>
    </section>
</template>
