<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { provide } from 'vue';
import {
    usePublicSport,
    publicSportKey,
    type PublicSportProps,
} from '@/composables/usePublicSport';
import PublicSportNavigation from '@/components/PublicSportNavigation.vue';
import PublicSportHero from '@/components/PublicSportHero.vue';
import PublicSportOverview from '@/components/PublicSportOverview.vue';
import PublicSportPredictions from '@/components/PublicSportPredictions.vue';
import PublicSportPropPicks from '@/components/PublicSportPropPicks.vue';
import PublicSportAccess from '@/components/PublicSportAccess.vue';
import PublicSportTopInjuries from '@/components/PublicSportTopInjuries.vue';
import PublicSportRecentInjuries from '@/components/PublicSportRecentInjuries.vue';
import PublicSportPlayoffs from '@/components/PublicSportPlayoffs.vue';
import PublicSportTeams from '@/components/PublicSportTeams.vue';
import PublicSportPlayers from '@/components/PublicSportPlayers.vue';
import PublicSportJoin from '@/components/PublicSportJoin.vue';
const props = defineProps<PublicSportProps>();
const state = usePublicSport(props);
provide(publicSportKey, state);
</script>
<template>
    <Head :title="`${sportLabel} Picks, Injuries, Teams, and Props`">
        <meta
            head-key="description"
            name="description"
            :content="`Public ${sportLabel} hub with top injuries, team rankings, player leaders, predictions, and player props.`"
        />
    </Head>

    <div
        class="min-h-screen overflow-x-hidden bg-[radial-gradient(circle_at_top,_rgba(251,191,36,0.16),_transparent_24%),radial-gradient(circle_at_85%_8%,_rgba(56,189,248,0.18),_transparent_18%),linear-gradient(180deg,_rgba(15,23,42,0.96),_rgba(2,6,23,1))] text-slate-50"
    >
        <PublicSportNavigation />

        <PublicSportHero />

        <main class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <PublicSportOverview />

            <section class="mt-6">
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <div
                            class="text-xs font-semibold tracking-[0.24em] text-sky-300 uppercase"
                        >
                            Best Bets First
                        </div>
                        <h2 class="mt-2 text-2xl font-semibold text-white">
                            Lead with the strongest public reasons to sign up.
                        </h2>
                    </div>
                    <div
                        class="hidden rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs tracking-[0.18em] text-slate-400 uppercase lg:block"
                    >
                        Predictions, props, then context
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <PublicSportPredictions />

                    <PublicSportPropPicks />
                </div>
            </section>

            <PublicSportAccess />

            <div class="grid gap-6 lg:grid-cols-2">
                <PublicSportTopInjuries />

                <PublicSportRecentInjuries />
            </div>

            <div class="mt-6 grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
                <PublicSportPlayoffs />

                <PublicSportTeams />

                <PublicSportPlayers />
            </div>

            <PublicSportJoin />
        </main>
    </div>
</template>
