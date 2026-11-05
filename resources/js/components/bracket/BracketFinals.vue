<script setup lang="ts">
import BracketParticipantButton from '@/components/bracket/BracketParticipantButton.vue';
import { useBracketBuilderContext } from '@/composables/useBracketBuilderContext';
const bracket = useBracketBuilderContext();
</script>

<template>
    <article
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
                        Finals
                    </p>
                    <h3
                        class="mt-2 text-xl font-semibold text-foreground sm:text-2xl"
                    >
                        Final Four and Title Game
                    </h3>
                </div>
                <p class="max-w-md text-sm leading-6 text-muted-foreground">
                    Regional winners flow here automatically as you complete
                    each board.
                </p>
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-[1fr_0.92fr]">
                <section>
                    <p
                        class="mb-3 text-xs font-semibold tracking-[0.22em] text-muted-foreground uppercase"
                    >
                        {{ bracket.roundLabels.final_four }}
                    </p>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div
                            v-for="matchup in bracket.standaloneRounds
                                .finalFour"
                            :key="matchup.id"
                            class="rounded-2xl border border-border/70 bg-card/70 p-3 backdrop-blur"
                        >
                            <div class="space-y-2">
                                <BracketParticipantButton
                                    :matchup="matchup"
                                    :participant-slot="matchup.participants[0]"
                                    variant="semifinal"
                                />

                                <BracketParticipantButton
                                    :matchup="matchup"
                                    :participant-slot="matchup.participants[1]"
                                    variant="semifinal"
                                />
                            </div>

                            <p
                                class="mt-3 text-[11px] leading-5 text-muted-foreground"
                            >
                                {{ bracket.formatVenue(matchup.game) }}
                            </p>
                        </div>
                    </div>
                </section>

                <section>
                    <p
                        class="mb-3 text-xs font-semibold tracking-[0.22em] text-muted-foreground uppercase"
                    >
                        {{ bracket.roundLabels.national_championship }}
                    </p>
                    <div
                        class="rounded-[1.8rem] border border-border/70 bg-card/72 p-3.5 backdrop-blur sm:p-4"
                    >
                        <div class="space-y-2">
                            <BracketParticipantButton
                                :matchup="bracket.standaloneRounds.championship"
                                :participant-slot="
                                    bracket.standaloneRounds.championship
                                        .participants[0]
                                "
                                variant="championship"
                            />

                            <BracketParticipantButton
                                :matchup="bracket.standaloneRounds.championship"
                                :participant-slot="
                                    bracket.standaloneRounds.championship
                                        .participants[1]
                                "
                                variant="championship"
                            />
                        </div>

                        <p
                            class="mt-3 text-[11px] leading-5 text-muted-foreground"
                        >
                            {{
                                bracket.formatVenue(
                                    bracket.standaloneRounds.championship.game,
                                )
                            }}
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </article>
</template>
