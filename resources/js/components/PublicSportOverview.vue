<script setup lang="ts">
import {} from '@/routes';
import { usePublicSportContext } from '@/composables/usePublicSport';
const { sportLabel, links, summary } = usePublicSportContext();
</script>
<template>
    <section
        class="mb-6 overflow-hidden rounded-[2rem] border border-white/10 bg-[linear-gradient(135deg,rgba(251,191,36,0.12),rgba(255,255,255,0.04),rgba(56,189,248,0.1))] p-6 shadow-[0_24px_80px_-45px_rgba(255,255,255,0.3)]"
    >
        <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
            <div>
                <div
                    class="text-xs font-semibold tracking-[0.24em] text-amber-300 uppercase"
                >
                    Today On The Board
                </div>
                <h2 class="mt-3 text-2xl font-semibold text-white sm:text-3xl">
                    The fastest way to understand what matters in
                    {{ sportLabel }} right now.
                </h2>
                <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-300">
                    Start with the market-facing pieces first: featured model
                    picks, prop spots, playoff movement, and injury context. The
                    rest of the platform goes deeper once a visitor is ready to
                    register.
                </p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a
                        :href="links.predictions"
                        class="rounded-full border border-sky-400/25 bg-sky-400/10 px-4 py-2 text-sm font-medium text-sky-100 transition hover:bg-sky-400/15"
                    >
                        Explore predictions
                    </a>
                    <a
                        v-if="links.playerProps"
                        :href="links.playerProps"
                        class="rounded-full border border-emerald-400/25 bg-emerald-400/10 px-4 py-2 text-sm font-medium text-emerald-100 transition hover:bg-emerald-400/15"
                    >
                        Check player props
                    </a>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    class="rounded-2xl border border-white/10 bg-slate-950/45 p-4"
                >
                    <div
                        class="text-xs tracking-[0.18em] text-slate-500 uppercase"
                    >
                        Featured Plays
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-white">
                        {{ summary.predictionsCount + summary.propsCount }}
                    </div>
                    <div class="mt-1 text-sm text-slate-300">
                        Public picks and prop hooks above the fold.
                    </div>
                </div>
                <div
                    class="rounded-2xl border border-white/10 bg-slate-950/45 p-4"
                >
                    <div
                        class="text-xs tracking-[0.18em] text-slate-500 uppercase"
                    >
                        Injury Feed
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-white">
                        {{
                            summary.topInjuriesCount +
                            summary.recentInjuriesCount
                        }}
                    </div>
                    <div class="mt-1 text-sm text-slate-300">
                        Impactful absences and recent updates.
                    </div>
                </div>
                <div
                    class="rounded-2xl border border-white/10 bg-slate-950/45 p-4"
                >
                    <div
                        class="text-xs tracking-[0.18em] text-slate-500 uppercase"
                    >
                        Top Teams
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-white">
                        {{ summary.topTeamsCount }}
                    </div>
                    <div class="mt-1 text-sm text-slate-300">
                        Power rankings snapshot for quick scanning.
                    </div>
                </div>
                <div
                    class="rounded-2xl border border-white/10 bg-slate-950/45 p-4"
                >
                    <div
                        class="text-xs tracking-[0.18em] text-slate-500 uppercase"
                    >
                        Top Players
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-white">
                        {{ summary.topPlayersCount }}
                    </div>
                    <div class="mt-1 text-sm text-slate-300">
                        Scoring leaders pulled from player stats.
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
