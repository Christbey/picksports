<script setup lang="ts">
import { computed } from 'vue';
import { useBracketBuilderContext } from '@/composables/useBracketBuilderContext';
import type { BracketMatchup, BracketSlot } from '@/types/bracket';

const props = defineProps<{
    matchup: BracketMatchup;
    participantSlot: BracketSlot;
    variant:
        | 'regional-mobile'
        | 'regional-desktop'
        | 'semifinal'
        | 'championship'
        | 'first-four';
}>();
const bracket = useBracketBuilderContext();
const regional = computed(() => props.variant.startsWith('regional-'));
const desktop = computed(() => props.variant === 'regional-desktop');
const championship = computed(() => props.variant === 'championship');
const placeholder = computed(() =>
    bracket.slotIsPlaceholder(props.participantSlot),
);
const fallbackLabel = computed(() =>
    props.variant === 'semifinal'
        ? 'Regional winner advances here'
        : championship.value
          ? 'Semifinal winner advances here'
          : props.variant === 'first-four'
            ? 'TBD'
            : 'Winner advances here',
);
const buttonClass = computed(() => {
    const tone = bracket.resultToneClass(props.matchup, props.participantSlot);
    if (desktop.value)
        return [
            bracket.isRoundOf64PlayInCard(props.matchup)
                ? 'px-2 py-1'
                : 'px-2.5 py-1',
            tone,
            placeholder.value
                ? 'border-border/60 bg-card/55 text-muted-foreground hover:border-border/80 hover:bg-card/70'
                : '',
        ];
    if (regional.value)
        return [
            'px-2.5 py-2',
            bracket.selectedSlotResultStatus(
                props.matchup,
                props.participantSlot,
            )
                ? tone
                : placeholder.value
                  ? 'border-border/60 bg-card/55 text-muted-foreground hover:border-border/80 hover:bg-card/70'
                  : 'border-border/70 bg-card/75 hover:border-border hover:bg-accent/60',
        ];
    return [championship.value ? 'px-3 py-2.5' : 'px-3 py-2', tone];
});
const seedClass = computed(() =>
    desktop.value
        ? 'inline-flex min-w-5 justify-center rounded-full border border-border/70 bg-card/80 px-1 py-0.5 text-[9px] leading-none font-semibold text-muted-foreground'
        : regional.value
          ? 'inline-flex min-w-6 justify-center rounded-full border border-border/70 bg-card/80 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-muted-foreground'
          : [
                'inline-flex min-w-7 justify-center rounded-full border border-border/70 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-muted-foreground',
                props.variant === 'first-four'
                    ? 'bg-card/80'
                    : 'bg-background/70',
            ],
);
const titleClass = computed(() =>
    desktop.value
        ? [
              'font-semibold',
              bracket.isRoundOf64PlayInCard(props.matchup)
                  ? 'text-[13px] leading-4'
                  : 'text-sm leading-4',
              placeholder.value ? 'text-muted-foreground' : 'text-foreground',
          ]
        : [
              championship.value
                  ? 'text-base leading-5 font-semibold'
                  : 'text-sm leading-5 font-semibold',
              regional.value && placeholder.value
                  ? 'text-muted-foreground'
                  : 'text-foreground',
          ],
);
</script>

<template>
    <button
        type="button"
        class="w-full rounded-xl border text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
        :class="buttonClass"
        :disabled="
            bracket.isBracketLocked ||
            !bracket.resolveSlotParticipant(participantSlot)
        "
        @click="bracket.selectWinner(matchup, participantSlot)"
    >
        <div class="flex items-center" :class="desktop ? 'gap-2' : 'gap-2.5'">
            <img
                v-if="bracket.participantLogo(participantSlot)"
                :src="bracket.participantLogo(participantSlot)!"
                :alt="
                    bracket.participantButtonLabel(
                        participantSlot,
                        fallbackLabel,
                    )
                "
                class="rounded-full bg-background/70 object-contain p-1"
                :class="desktop ? 'size-6' : 'size-8'"
            />
            <div class="min-w-0">
                <p
                    v-if="!regional || !placeholder"
                    :class="
                        regional
                            ? 'text-[10px] tracking-[0.16em] text-muted-foreground uppercase'
                            : 'text-[11px] tracking-[0.18em] text-muted-foreground uppercase'
                    "
                >
                    {{ bracket.participantButtonAbbr(participantSlot, 'TBD') }}
                </p>
                <div
                    class="mt-0.5 flex items-start"
                    :class="desktop ? 'gap-1.5' : 'gap-2'"
                >
                    <span
                        v-if="bracket.participantSeed(participantSlot)"
                        :class="seedClass"
                        >{{ bracket.participantSeed(participantSlot) }}</span
                    >
                    <div class="min-w-0">
                        <p :class="titleClass">
                            {{
                                bracket.participantButtonLabel(
                                    participantSlot,
                                    fallbackLabel,
                                )
                            }}
                        </p>
                        <p
                            v-if="
                                bracket.slotWinProbability(
                                    matchup,
                                    participantSlot,
                                ) !== null
                            "
                            :class="[
                                desktop ? 'text-[10px]' : 'text-[11px]',
                                bracket.slotWinProbabilityClass(
                                    matchup,
                                    participantSlot,
                                ),
                            ]"
                        >
                            {{
                                bracket.slotWinProbability(
                                    matchup,
                                    participantSlot,
                                )
                            }}% ML
                        </p>
                        <p
                            v-if="
                                (variant === 'semifinal' || championship) &&
                                bracket.resultBadgeLabel(
                                    matchup,
                                    participantSlot,
                                )
                            "
                            class="mt-1 inline-flex rounded-full border px-2 py-0.5 text-[10px] tracking-[0.14em] uppercase"
                            :class="
                                bracket.resultBadgeClass(
                                    matchup,
                                    participantSlot,
                                )
                            "
                        >
                            {{
                                bracket.resultBadgeLabel(
                                    matchup,
                                    participantSlot,
                                )
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </button>
</template>
