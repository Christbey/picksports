<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { usePublicSportContext } from '@/composables/usePublicSport';
const { formatProbability, sport, conferencePlayoffTeams } =
    usePublicSportContext();
</script>
<template>
    <Card
        v-if="sport === 'nba' && conferencePlayoffTeams"
        class="border-white/10 bg-white/5 text-slate-50 xl:col-span-2"
    >
        <CardHeader>
            <CardTitle>Current Playoff Teams By Conference</CardTitle>
            <CardDescription class="text-slate-400"
                >Latest projected East and West playoff field from the NBA
                forecast table.</CardDescription
            >
        </CardHeader>
        <CardContent class="grid gap-6 lg:grid-cols-2">
            <div>
                <div
                    class="mb-3 text-sm font-semibold tracking-[0.2em] text-amber-300 uppercase"
                >
                    Eastern Conference
                </div>
                <div class="space-y-3">
                    <div
                        v-if="conferencePlayoffTeams.east.length === 0"
                        class="text-sm text-slate-400"
                    >
                        No Eastern Conference playoff data available.
                    </div>
                    <div
                        v-for="team in conferencePlayoffTeams.east"
                        :key="`east-${team.team_id}`"
                        class="flex items-center gap-3 rounded-2xl border border-white/10 bg-slate-950/40 p-3"
                    >
                        <div
                            class="flex size-10 items-center justify-center rounded-full bg-amber-400/15 text-sm font-semibold text-amber-200"
                        >
                            {{ team.seed }}
                        </div>
                        <img
                            v-if="team.logo"
                            :src="team.logo"
                            :alt="team.name"
                            class="size-10 rounded-full bg-white/5 object-contain p-1"
                        />
                        <div
                            v-else
                            class="flex size-10 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-slate-300"
                        >
                            {{
                                team.abbreviation ||
                                team.name.slice(0, 3).toUpperCase()
                            }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-white">
                                {{ team.name }}
                            </div>
                            <div class="mt-1 text-xs text-slate-400">
                                Direct
                                {{
                                    formatProbability(
                                        team.direct_playoff_probability,
                                    )
                                }}
                                · Make
                                {{
                                    formatProbability(
                                        team.playoff_make_probability,
                                    )
                                }}
                            </div>
                            <div
                                class="mt-3 h-2 overflow-hidden rounded-full bg-white/10"
                            >
                                <div
                                    class="h-full rounded-full bg-gradient-to-r from-amber-300 to-emerald-400"
                                    :style="{
                                        width:
                                            formatProbability(
                                                team.direct_playoff_probability,
                                            ) !== 'N/A'
                                                ? `${(team.direct_playoff_probability ?? 0) * 100}%`
                                                : '0%',
                                    }"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div
                    class="mb-3 text-sm font-semibold tracking-[0.2em] text-amber-300 uppercase"
                >
                    Western Conference
                </div>
                <div class="space-y-3">
                    <div
                        v-if="conferencePlayoffTeams.west.length === 0"
                        class="text-sm text-slate-400"
                    >
                        No Western Conference playoff data available.
                    </div>
                    <div
                        v-for="team in conferencePlayoffTeams.west"
                        :key="`west-${team.team_id}`"
                        class="flex items-center gap-3 rounded-2xl border border-white/10 bg-slate-950/40 p-3"
                    >
                        <div
                            class="flex size-10 items-center justify-center rounded-full bg-amber-400/15 text-sm font-semibold text-amber-200"
                        >
                            {{ team.seed }}
                        </div>
                        <img
                            v-if="team.logo"
                            :src="team.logo"
                            :alt="team.name"
                            class="size-10 rounded-full bg-white/5 object-contain p-1"
                        />
                        <div
                            v-else
                            class="flex size-10 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-slate-300"
                        >
                            {{
                                team.abbreviation ||
                                team.name.slice(0, 3).toUpperCase()
                            }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-white">
                                {{ team.name }}
                            </div>
                            <div class="mt-1 text-xs text-slate-400">
                                Direct
                                {{
                                    formatProbability(
                                        team.direct_playoff_probability,
                                    )
                                }}
                                · Make
                                {{
                                    formatProbability(
                                        team.playoff_make_probability,
                                    )
                                }}
                            </div>
                            <div
                                class="mt-3 h-2 overflow-hidden rounded-full bg-white/10"
                            >
                                <div
                                    class="h-full rounded-full bg-gradient-to-r from-sky-300 to-cyan-400"
                                    :style="{
                                        width:
                                            formatProbability(
                                                team.direct_playoff_probability,
                                            ) !== 'N/A'
                                                ? `${(team.direct_playoff_probability ?? 0) * 100}%`
                                                : '0%',
                                    }"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
