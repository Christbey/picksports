<script setup lang="ts">
import BracketParticipantButton from '@/components/bracket/BracketParticipantButton.vue';
import { useBracketBuilderContext } from '@/composables/useBracketBuilderContext';
import type { BracketRegion } from '@/types/bracket';
defineProps<{ region: BracketRegion }>();
const bracket = useBracketBuilderContext();
</script>

<template>
    <article
        :id="`region-${region.id}`"
        class="relative overflow-hidden rounded-[2rem] border border-border/70 bg-card/80 p-4 shadow-[0_20px_60px_-32px_rgba(15,23,42,0.18)] backdrop-blur sm:p-6"
    >
        <div
            class="absolute inset-0 bg-gradient-to-br"
            :class="
                bracket.accentByRegion[region.name] ??
                bracket.accentByRegion.Unassigned
            "
        />
        <div class="relative space-y-5">
            <div
                class="flex flex-col gap-4 xl:grid xl:grid-cols-[220px_minmax(0,1fr)] xl:items-start"
            >
                <div class="space-y-4">
                    <div
                        class="flex items-center justify-between gap-3 xl:block"
                    >
                        <div>
                            <p
                                class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                            >
                                Region
                            </p>
                            <h3 class="text-2xl font-semibold text-foreground">
                                {{ region.name }}
                            </h3>
                        </div>
                        <div
                            class="rounded-full border border-border/70 bg-background/45 px-3 py-1 text-[11px] tracking-[0.22em] text-muted-foreground uppercase xl:mt-4 xl:inline-flex"
                        >
                            {{ bracket.regionCompletion(region).picked }}/{{
                                bracket.regionCompletion(region).total
                            }}
                            picked
                        </div>
                    </div>

                    <div class="hidden space-y-3 xl:block">
                        <div
                            class="rounded-2xl border border-border/70 bg-background/45 p-3"
                        >
                            <p
                                class="text-[11px] font-semibold tracking-[0.2em] text-foreground/80 uppercase"
                            >
                                {{ bracket.regionGuidanceLabel(region) }}
                            </p>
                            <p
                                class="mt-2 text-sm leading-6 text-muted-foreground"
                            >
                                {{ bracket.regionGuidanceDetail(region) }}
                            </p>
                        </div>
                        <div class="h-2 rounded-full bg-accent/70">
                            <div
                                class="h-full rounded-full bg-primary/80 transition-[width]"
                                :style="{
                                    width: `${bracket.regionCompletion(region).pct}%`,
                                }"
                            />
                        </div>
                        <p
                            class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                        >
                            {{ bracket.regionCompletion(region).pct }}% complete
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="grid gap-2 sm:grid-cols-2 lg:hidden">
                        <section
                            v-for="round in region.rounds"
                            :key="`mobile-${region.id}-${round.key}`"
                            class="rounded-[1.35rem] border p-3 sm:p-3.5"
                            :class="
                                bracket.roundSectionClass(region, round.key)
                            "
                        >
                            <div
                                class="mb-3 flex items-center justify-between gap-3 border-b border-border/60 pb-2"
                            >
                                <div>
                                    <div class="flex items-center gap-2">
                                        <p
                                            class="text-[11px] font-semibold tracking-[0.22em] text-muted-foreground uppercase"
                                        >
                                            {{ round.label }}
                                        </p>
                                        <span
                                            class="rounded-full border border-border/70 bg-background/45 px-2 py-0.5 text-[9px] tracking-[0.14em] text-muted-foreground uppercase"
                                        >
                                            {{
                                                bracket.roundHeaderBadge(
                                                    region,
                                                    round.key,
                                                )
                                            }}
                                        </span>
                                    </div>
                                    <p
                                        class="mt-1 text-[10px] tracking-[0.14em] text-muted-foreground uppercase"
                                    >
                                        {{
                                            bracket.roundMeta(region, round.key)
                                                .picked
                                        }}/{{ round.matchups.length }}
                                        picked
                                    </p>
                                    <p
                                        v-if="
                                            bracket.isAuthenticated &&
                                            bracket.currentBracket
                                        "
                                        class="mt-1 text-[10px] tracking-[0.14em] text-muted-foreground uppercase"
                                    >
                                        {{
                                            bracket.roundMeta(region, round.key)
                                                .summaryLabel
                                        }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        {{ round.matchups.length }}
                                    </p>
                                    <p
                                        v-if="
                                            bracket.isAuthenticated &&
                                            bracket.currentBracket
                                        "
                                        class="mt-1 text-[10px] font-semibold text-muted-foreground"
                                    >
                                        {{
                                            bracket.roundMeta(region, round.key)
                                                .summary.pointsEarned
                                        }}/{{
                                            bracket.roundMeta(region, round.key)
                                                .summary.possiblePoints
                                        }}
                                        pts
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <div
                                    v-for="matchup in round.matchups"
                                    :key="`mobile-card-${matchup.id}`"
                                    class="rounded-2xl border border-border/70 bg-card/70 p-2.5 backdrop-blur"
                                >
                                    <div
                                        v-if="
                                            matchup.participants.some(
                                                (slot) => slot.sourceMatchupId,
                                            )
                                        "
                                        class="mb-1.5 inline-flex rounded-full border border-dashed border-border/70 bg-card/60 px-2.5 py-1 text-[9px] tracking-[0.16em] text-foreground/75 uppercase"
                                    >
                                        {{
                                            bracket.isRoundOf64PlayInCard(
                                                matchup,
                                            )
                                                ? 'First Four'
                                                : 'Play-in winner feeds this slot'
                                        }}
                                    </div>
                                    <div
                                        v-if="
                                            bracket.compactMatchupSummary(
                                                matchup,
                                            )
                                        "
                                        class="mb-2 text-[10px] leading-4 text-foreground/70"
                                        :class="
                                            bracket.isRoundOf64PlayInCard(
                                                matchup,
                                            )
                                                ? 'line-clamp-2'
                                                : ''
                                        "
                                    >
                                        {{
                                            bracket.compactMatchupSummary(
                                                matchup,
                                            )
                                        }}
                                    </div>

                                    <div class="space-y-2">
                                        <BracketParticipantButton
                                            :matchup="matchup"
                                            :participant-slot="
                                                matchup.participants[0]
                                            "
                                            variant="regional-mobile"
                                        />

                                        <BracketParticipantButton
                                            :matchup="matchup"
                                            :participant-slot="
                                                matchup.participants[1]
                                            "
                                            variant="regional-mobile"
                                        />
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div
                        class="hidden overflow-x-auto pb-2 [scrollbar-width:thin] lg:block"
                    >
                        <div
                            class="grid min-w-[1060px] grid-cols-4 gap-4 xl:gap-5"
                        >
                            <div
                                v-for="round in region.rounds"
                                :key="`${region.id}-${round.key}`"
                                class="flex flex-col gap-4"
                                :class="
                                    bracket.roundColumnMinHeightClass(round.key)
                                "
                            >
                                <div
                                    class="rounded-2xl border px-3 py-2"
                                    :class="
                                        bracket.roundSectionClass(
                                            region,
                                            round.key,
                                        )
                                    "
                                >
                                    <div
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <div>
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <p
                                                    class="text-[11px] font-semibold tracking-[0.24em] text-muted-foreground uppercase"
                                                >
                                                    {{ round.label }}
                                                </p>
                                                <span
                                                    class="rounded-full border border-border/70 bg-background/45 px-2 py-0.5 text-[9px] tracking-[0.14em] text-muted-foreground uppercase"
                                                >
                                                    {{
                                                        bracket.roundHeaderBadge(
                                                            region,
                                                            round.key,
                                                        )
                                                    }}
                                                </span>
                                            </div>
                                            <p
                                                class="mt-1 text-[10px] tracking-[0.14em] text-muted-foreground uppercase"
                                            >
                                                {{
                                                    bracket.roundMeta(
                                                        region,
                                                        round.key,
                                                    ).picked
                                                }}/{{ round.matchups.length }}
                                                picked
                                            </p>
                                            <p
                                                v-if="
                                                    bracket.isAuthenticated &&
                                                    bracket.currentBracket
                                                "
                                                class="mt-1 text-[10px] tracking-[0.14em] text-muted-foreground uppercase"
                                            >
                                                {{
                                                    bracket.roundMeta(
                                                        region,
                                                        round.key,
                                                    ).summaryLabel
                                                }}
                                            </p>
                                        </div>
                                        <p
                                            v-if="
                                                bracket.isAuthenticated &&
                                                bracket.currentBracket
                                            "
                                            class="text-[10px] font-semibold text-muted-foreground"
                                        >
                                            {{
                                                bracket.roundMeta(
                                                    region,
                                                    round.key,
                                                ).summary.pointsEarned
                                            }}/{{
                                                bracket.roundMeta(
                                                    region,
                                                    round.key,
                                                ).summary.possiblePoints
                                            }}
                                            pts
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="grid flex-1 content-start gap-2.5 overflow-visible xl:gap-3"
                                    :class="
                                        bracket.roundColumnGridClass(round.key)
                                    "
                                >
                                    <div
                                        v-for="(
                                            matchup, matchupIndex
                                        ) in round.matchups"
                                        :key="matchup.id"
                                        class="relative self-start rounded-2xl border border-border/70 bg-card/70 p-2 backdrop-blur xl:p-2.5"
                                        :style="
                                            bracket.roundTrackStyle(
                                                round.key,
                                                matchupIndex,
                                            )
                                        "
                                    >
                                        <div
                                            v-if="
                                                matchup.participants.some(
                                                    (slot) =>
                                                        slot.sourceMatchupId,
                                                )
                                            "
                                            class="mb-1 rounded-xl border border-dashed border-border/70 bg-card/60 px-2 py-1 text-[9px] tracking-[0.16em] text-foreground/75 uppercase"
                                        >
                                            {{
                                                bracket.isRoundOf64PlayInCard(
                                                    matchup,
                                                )
                                                    ? 'First Four'
                                                    : 'Play-in winner feeds this slot'
                                            }}
                                        </div>
                                        <div
                                            v-if="
                                                bracket.compactMatchupSummary(
                                                    matchup,
                                                )
                                            "
                                            class="mb-1 text-[9px] leading-3 text-foreground/70"
                                            :class="
                                                bracket.isRoundOf64PlayInCard(
                                                    matchup,
                                                )
                                                    ? 'line-clamp-1'
                                                    : ''
                                            "
                                        >
                                            {{
                                                bracket.compactMatchupSummary(
                                                    matchup,
                                                )
                                            }}
                                        </div>

                                        <div
                                            :class="
                                                bracket.isRoundOf64PlayInCard(
                                                    matchup,
                                                )
                                                    ? 'space-y-1'
                                                    : 'space-y-1.25'
                                            "
                                        >
                                            <BracketParticipantButton
                                                :matchup="matchup"
                                                :participant-slot="
                                                    matchup.participants[0]
                                                "
                                                variant="regional-desktop"
                                            />

                                            <BracketParticipantButton
                                                :matchup="matchup"
                                                :participant-slot="
                                                    matchup.participants[1]
                                                "
                                                variant="regional-desktop"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </article>
</template>
