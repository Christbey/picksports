<script setup lang="ts">
import BracketParticipantButton from '@/components/bracket/BracketParticipantButton.vue';
import { useBracketBuilderContext } from '@/composables/useBracketBuilderContext';
const bracket = useBracketBuilderContext();
</script>

<template>
    <article
        v-if="bracket.standaloneRounds.firstFour.length"
        class="relative overflow-hidden rounded-[2rem] border border-border/70 bg-card/80 p-4 shadow-[0_20px_60px_-32px_rgba(15,23,42,0.18)] backdrop-blur sm:p-6"
    >
        <div
            class="absolute inset-0 bg-gradient-to-br from-foreground/[0.04] via-transparent to-transparent dark:from-foreground/[0.06]"
        />
        <div class="relative">
            <div
                class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between"
            >
                <div>
                    <p
                        class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                    >
                        Opening Round
                    </p>
                    <h3
                        class="mt-2 text-xl font-semibold text-foreground sm:text-2xl"
                    >
                        {{ bracket.roundLabels.first_four }}
                    </h3>
                </div>
            </div>

            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <div
                    v-for="matchup in bracket.standaloneRounds.firstFour"
                    :key="matchup.id"
                    class="rounded-2xl border border-border/70 bg-card/70 p-3 backdrop-blur"
                >
                    <div
                        class="mb-3 inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] font-semibold tracking-[0.22em] text-foreground/75 uppercase"
                    >
                        Winner to
                        {{ bracket.firstFourDestinationLabel(matchup) }}
                    </div>

                    <div class="space-y-2">
                        <BracketParticipantButton
                            :matchup="matchup"
                            :participant-slot="matchup.participants[0]"
                            variant="first-four"
                        />

                        <BracketParticipantButton
                            :matchup="matchup"
                            :participant-slot="matchup.participants[1]"
                            variant="first-four"
                        />
                    </div>

                    <p class="mt-3 text-[11px] leading-5 text-muted-foreground">
                        {{ bracket.formatVenue(matchup.game) }}
                    </p>
                </div>
            </div>
        </div>
    </article>
</template>
