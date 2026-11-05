<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { usePublicSportContext } from '@/composables/usePublicSport';
const {
    navLinks,
    formatMetric,
    topPrediction,
    topTeam,
    topPlayer,
    sportLabel,
    latestSeason,
    summary,
} = usePublicSportContext();
</script>
<template>
    <section class="border-b border-white/10">
        <div
            class="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 lg:grid-cols-[1.35fr_0.95fr] lg:px-8 lg:py-20"
        >
            <div>
                <Badge
                    variant="outline"
                    class="border-amber-300/40 bg-amber-300/10 text-amber-200 shadow-[0_0_0_1px_rgba(251,191,36,0.06)]"
                >
                    Public {{ sportLabel }} intelligence
                </Badge>
                <h1
                    class="mt-6 max-w-4xl text-4xl font-semibold tracking-tight text-white sm:text-6xl"
                >
                    Turn live {{ sportLabel }} signal into sharper picks before
                    the market catches up.
                </h1>
                <p class="mt-5 max-w-2xl text-lg leading-8 text-slate-300">
                    This page packages the strongest public hooks from
                    PickSports into a fast read: injuries that move lines, teams
                    rising into the playoff field, player leaders, featured
                    predictions, and player props worth a second look.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <a
                        v-for="link in navLinks"
                        :key="link.label"
                        :href="link.href"
                        class="rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-amber-300/50 hover:bg-white/10"
                    >
                        {{ link.label }}
                    </a>
                </div>

                <div class="mt-10 flex flex-col gap-3 sm:flex-row">
                    <Link v-if="!$page.props.auth.user" :href="register()">
                        <Button
                            size="lg"
                            class="h-12 bg-amber-400 px-7 text-base font-semibold text-slate-950 hover:bg-amber-300"
                        >
                            Unlock Full Board
                        </Button>
                    </Link>
                    <Link v-if="!$page.props.auth.user" :href="login()">
                        <Button
                            size="lg"
                            variant="ghost"
                            class="h-12 border border-white/15 bg-white/5 px-7 text-base text-slate-100 hover:bg-white/10"
                        >
                            Already a member
                        </Button>
                    </Link>
                </div>

                <div class="mt-10 grid gap-4 sm:grid-cols-3">
                    <div
                        class="rounded-3xl border border-white/10 bg-white/[0.06] p-5 shadow-[0_20px_60px_-35px_rgba(0,0,0,0.9)]"
                    >
                        <div
                            class="text-xs tracking-[0.2em] text-amber-300 uppercase"
                        >
                            Lead Story
                        </div>
                        <div class="mt-3 text-lg font-semibold text-white">
                            {{
                                topPrediction?.pick ??
                                topTeam?.name ??
                                `${sportLabel} board`
                            }}
                        </div>
                        <div class="mt-2 text-sm text-slate-300">
                            {{
                                topPrediction?.matchup ??
                                'See the strongest model lean, team momentum, and injury-driven spots.'
                            }}
                        </div>
                    </div>
                    <div
                        class="rounded-3xl border border-white/10 bg-white/[0.05] p-5"
                    >
                        <div
                            class="text-xs tracking-[0.2em] text-sky-300 uppercase"
                        >
                            Top Team
                        </div>
                        <div class="mt-3 text-lg font-semibold text-white">
                            {{ topTeam?.name ?? 'No team data yet' }}
                        </div>
                        <div class="mt-2 text-sm text-slate-300">
                            {{ topTeam?.primary_metric_label ?? 'Metric' }}
                            {{ formatMetric(topTeam?.primary_metric_value) }}
                            <span v-if="topTeam?.record"
                                >· {{ topTeam.record }}</span
                            >
                        </div>
                    </div>
                    <div
                        class="rounded-3xl border border-white/10 bg-white/[0.05] p-5"
                    >
                        <div
                            class="text-xs tracking-[0.2em] text-emerald-300 uppercase"
                        >
                            Top Player
                        </div>
                        <div class="mt-3 text-lg font-semibold text-white">
                            {{ topPlayer?.name ?? 'No player data yet' }}
                        </div>
                        <div class="mt-2 text-sm text-slate-300">
                            {{ topPlayer?.headline_stat_label ?? 'PPG' }}
                            {{ formatMetric(topPlayer?.headline_stat_value) }}
                            <span v-if="topPlayer?.team_abbreviation"
                                >· {{ topPlayer.team_abbreviation }}</span
                            >
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                <div
                    class="overflow-hidden rounded-[2rem] border border-white/10 bg-gradient-to-br from-amber-300/20 via-white/8 to-sky-400/10 p-6 shadow-[0_30px_80px_-40px_rgba(251,191,36,0.45)]"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div
                                class="text-xs tracking-[0.24em] text-slate-300 uppercase"
                            >
                                Coverage
                            </div>
                            <div class="mt-3 text-4xl font-semibold text-white">
                                {{ latestSeason ?? 'Live' }}
                            </div>
                            <div
                                class="mt-2 max-w-xs text-sm text-slate-200/85"
                            >
                                Current season snapshots, market-facing signals,
                                and fast public reads built to convert interest
                                into action.
                            </div>
                        </div>
                        <div
                            class="rounded-full border border-white/15 bg-slate-950/50 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-amber-300 uppercase"
                        >
                            {{ sportLabel }}
                        </div>
                    </div>

                    <div
                        class="mt-6 h-32 rounded-[1.5rem] border border-white/10 bg-[linear-gradient(135deg,rgba(255,255,255,0.12),rgba(255,255,255,0.02))] p-4"
                    >
                        <div class="flex h-full items-end gap-2">
                            <div
                                class="h-[40%] flex-1 rounded-t-2xl bg-amber-300/75"
                            />
                            <div
                                class="h-[68%] flex-1 rounded-t-2xl bg-sky-400/75"
                            />
                            <div
                                class="h-[52%] flex-1 rounded-t-2xl bg-emerald-400/75"
                            />
                            <div
                                class="h-[84%] flex-1 rounded-t-2xl bg-white/80"
                            />
                            <div
                                class="h-[61%] flex-1 rounded-t-2xl bg-rose-300/75"
                            />
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div
                        class="rounded-3xl border border-white/10 bg-white/[0.06] p-5"
                    >
                        <div
                            class="text-xs tracking-[0.2em] text-slate-400 uppercase"
                        >
                            Predictions
                        </div>
                        <div class="mt-2 text-3xl font-semibold text-white">
                            {{ summary.predictionsCount }}
                        </div>
                        <div class="mt-1 text-xs text-slate-400">
                            Featured model spots
                        </div>
                    </div>
                    <div
                        class="rounded-3xl border border-white/10 bg-white/[0.06] p-5"
                    >
                        <div
                            class="text-xs tracking-[0.2em] text-slate-400 uppercase"
                        >
                            Props
                        </div>
                        <div class="mt-2 text-3xl font-semibold text-white">
                            {{ summary.propsCount }}
                        </div>
                        <div class="mt-1 text-xs text-slate-400">
                            High-confidence edges
                        </div>
                    </div>
                    <div
                        class="rounded-3xl border border-white/10 bg-white/[0.06] p-5"
                    >
                        <div
                            class="text-xs tracking-[0.2em] text-slate-400 uppercase"
                        >
                            Teams
                        </div>
                        <div class="mt-2 text-3xl font-semibold text-white">
                            {{ summary.topTeamsCount }}
                        </div>
                        <div class="mt-1 text-xs text-slate-400">
                            Power rankings snapshot
                        </div>
                    </div>
                    <div
                        class="rounded-3xl border border-white/10 bg-white/[0.06] p-5"
                    >
                        <div
                            class="text-xs tracking-[0.2em] text-slate-400 uppercase"
                        >
                            Players
                        </div>
                        <div class="mt-2 text-3xl font-semibold text-white">
                            {{ summary.topPlayersCount }}
                        </div>
                        <div class="mt-1 text-xs text-slate-400">
                            Public scoring leaders
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
