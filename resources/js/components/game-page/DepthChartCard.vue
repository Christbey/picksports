<script setup lang="ts">
import type { GameDepthChartTeam, TeamDepthChartEntry } from '@/types';

defineProps<{
    title?: string;
    awayTeam: GameDepthChartTeam | null;
    homeTeam: GameDepthChartTeam | null;
}>();

const metricKey = (entry: TeamDepthChartEntry, index: number): string =>
    `${entry.position_slot_key}-${entry.depth_rank}-${index}`;
</script>

<template>
    <section
        v-if="awayTeam || homeTeam"
        class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white/95 shadow-sm ring-1 ring-black/5 dark:border-slate-800 dark:bg-slate-950/80"
    >
        <div class="border-b border-slate-200/80 px-6 py-4 dark:border-slate-800">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                {{ title || 'Depth Charts' }}
            </h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Projected starters and current season stat context.
            </p>
        </div>

        <div class="grid gap-6 p-6 lg:grid-cols-2">
            <article
                v-for="team in [awayTeam, homeTeam]"
                :key="team?.team?.id ?? Math.random()"
                class="space-y-4"
            >
                <div v-if="team" class="flex items-center gap-3">
                    <img
                        v-if="team.team.logo"
                        :src="team.team.logo"
                        :alt="team.team.display_name || team.team.abbreviation || 'Team logo'"
                        class="h-10 w-10 rounded-full object-contain"
                    />
                    <div>
                        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                            {{ team.team.display_name || team.team.abbreviation }}
                        </h3>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-400 dark:text-slate-500">
                            {{ team.entries.filter((entry) => entry.is_starter).length }} listed starters
                        </p>
                    </div>
                </div>

                <div v-if="team && team.entries.length" class="space-y-3">
                    <div
                        v-for="entry in team.entries"
                        :key="`${team.team.id}-${entry.position_slot_key}-${entry.depth_rank}-${entry.espn_athlete_id ?? entry.player_id ?? 'unknown'}`"
                        class="rounded-2xl border border-slate-200/80 bg-slate-50/90 p-4 dark:border-slate-800 dark:bg-slate-900/60"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-slate-900 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.18em] text-white dark:bg-slate-100 dark:text-slate-950">
                                        {{ entry.position_code || entry.position_slot_key }}
                                    </span>
                                    <span
                                        v-if="entry.is_starter"
                                        class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.18em] text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300"
                                    >
                                        Starter
                                    </span>
                                </div>
                                <div class="mt-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {{ entry.full_name || `ESPN #${entry.espn_athlete_id ?? 'Unknown'}` }}
                                </div>
                                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    {{ entry.position_name }}
                                    <span v-if="entry.jersey_number"> • #{{ entry.jersey_number }}</span>
                                    <span v-if="entry.stats.games_played"> • {{ entry.stats.games_played }} GP</span>
                                </div>
                            </div>

                            <img
                                v-if="entry.headshot_url"
                                :src="entry.headshot_url"
                                :alt="entry.full_name || 'Player headshot'"
                                class="h-12 w-12 rounded-2xl object-cover ring-1 ring-slate-200 dark:ring-slate-800"
                            />
                        </div>

                        <div
                            v-if="entry.stats.metrics.length"
                            class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4"
                        >
                            <div
                                v-for="(metric, index) in entry.stats.metrics"
                                :key="metricKey(entry, index)"
                                class="rounded-xl bg-white px-3 py-2 text-center ring-1 ring-slate-200/80 dark:bg-slate-950/80 dark:ring-slate-800"
                            >
                                <div class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 dark:text-slate-500">
                                    {{ metric.label }}
                                </div>
                                <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {{ metric.value }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="team"
                    class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400"
                >
                    No depth chart data available.
                </div>
            </article>
        </div>
    </section>
</template>
