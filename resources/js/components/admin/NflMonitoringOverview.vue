<script setup lang="ts">
import {
    Activity,
    Ban,
    CheckCircle2,
    CircleDollarSign,
    Clock3,
    GitCompare,
    ShieldCheck,
} from 'lucide-vue-next';
import {
    formatPercent,
    labelize,
    artifactStatusClass,
} from '@/lib/nflMonitoringPresentation';
import { useNflMonitoringContext } from '@/composables/useNflModelMonitoring';
const { artifact, summary } = useNflMonitoringContext();
</script>

<template>
    <template v-if="artifact">
        <section
            class="flex flex-col gap-4 rounded-md border bg-card p-4 md:flex-row md:items-center md:justify-between"
        >
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="rounded-md border px-2 py-1 text-xs font-semibold"
                        :class="artifactStatusClass(artifact.status)"
                    >
                        {{ labelize(artifact.status) }}
                    </span>
                    <span
                        class="rounded-md border border-violet-500/30 bg-violet-500/10 px-2 py-1 text-xs font-semibold text-violet-700 dark:text-violet-300"
                    >
                        Tracking only
                    </span>
                </div>
                <p class="mt-2 truncate text-base font-semibold">
                    {{ artifact.model_version }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ labelize(artifact.model_type) }} ·
                    {{ labelize(artifact.market_type) }}
                </p>
            </div>

            <div
                class="flex items-center gap-3 rounded-md border px-3 py-2"
                :class="
                    artifact.public_output_changed
                        ? 'border-amber-500/30 bg-amber-500/10'
                        : 'border-emerald-500/30 bg-emerald-500/10'
                "
            >
                <ShieldCheck
                    class="h-5 w-5 shrink-0"
                    :class="
                        artifact.public_output_changed
                            ? 'text-amber-700 dark:text-amber-300'
                            : 'text-emerald-700 dark:text-emerald-300'
                    "
                />
                <div>
                    <div
                        class="text-xs font-semibold"
                        :class="
                            artifact.public_output_changed
                                ? 'text-amber-800 dark:text-amber-200'
                                : 'text-emerald-800 dark:text-emerald-200'
                        "
                    >
                        Public Output
                    </div>
                    <div
                        class="text-sm"
                        :class="
                            artifact.public_output_changed
                                ? 'text-amber-700 dark:text-amber-300'
                                : 'text-emerald-700 dark:text-emerald-300'
                        "
                    >
                        {{
                            artifact.public_output_changed
                                ? 'Model output active'
                                : 'Baseline unchanged'
                        }}
                    </div>
                </div>
            </div>
        </section>

        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4 xl:grid-cols-6">
            <div class="rounded-md border bg-card p-3">
                <Activity class="h-4 w-4 text-sky-600" />
                <div class="mt-3 text-2xl font-bold">
                    {{ summary.shadow_observations }}
                </div>
                <div class="text-xs text-muted-foreground">
                    Shadow observations
                </div>
            </div>
            <div class="rounded-md border bg-card p-3">
                <GitCompare class="h-4 w-4 text-violet-600" />
                <div class="mt-3 text-2xl font-bold">
                    {{ summary.decisions }}
                </div>
                <div class="text-xs text-muted-foreground">Decisions</div>
            </div>
            <div class="rounded-md border bg-card p-3">
                <CircleDollarSign class="h-4 w-4 text-emerald-600" />
                <div class="mt-3 text-2xl font-bold">
                    {{ summary.tracking_bets }}
                </div>
                <div class="text-xs text-muted-foreground">Tracking bets</div>
            </div>
            <div class="rounded-md border bg-card p-3">
                <Ban class="h-4 w-4 text-amber-600" />
                <div class="mt-3 text-2xl font-bold">
                    {{ summary.no_bets }}
                </div>
                <div class="text-xs text-muted-foreground">
                    No-bet decisions
                </div>
            </div>
            <div class="rounded-md border bg-card p-3">
                <Clock3 class="h-4 w-4 text-slate-600" />
                <div class="mt-3 text-2xl font-bold">
                    {{ summary.pending_decisions }}
                </div>
                <div class="text-xs text-muted-foreground">
                    Pending settlement
                </div>
            </div>
            <div class="rounded-md border bg-card p-3">
                <CheckCircle2 class="h-4 w-4 text-teal-600" />
                <div class="mt-3 text-2xl font-bold">
                    {{ formatPercent(summary.actual_roi) }}
                </div>
                <div class="text-xs text-muted-foreground">Settled ROI</div>
            </div>
        </section>
    </template>
</template>
