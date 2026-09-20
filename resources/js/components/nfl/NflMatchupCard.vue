<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    kickoffLabel,
    nflBoardPresentation,
    percent,
    teamLabel,
} from '@/lib/nflBoardPresentation';
import type { ApiV2Prediction } from '@/types';

const props = defineProps<{ prediction: ApiV2Prediction }>();
const emit = defineEmits<{ select: [prediction: ApiV2Prediction] }>();
const view = computed(() => nflBoardPresentation(props.prediction));
const game = computed(() => props.prediction.game);
const live = computed(() =>
    /in_progress|live/i.test(
        String(props.prediction.status ?? game.value?.status ?? ''),
    ),
);
</script>

<template>
    <button
        type="button"
        class="group w-full min-w-0 rounded-xl border bg-card p-3 text-left transition hover:border-sky-500/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500 sm:p-4"
        @click="emit('select', prediction)"
    >
        <div class="flex items-center justify-between gap-2">
            <div
                class="flex min-w-0 items-center gap-1.5 text-sm font-semibold"
            >
                <img
                    v-if="game?.away_team?.logo_url"
                    :src="game.away_team.logo_url"
                    alt=""
                    class="h-6 w-6 object-contain"
                />
                <span>{{ teamLabel(game?.away_team) }}</span>
                <span v-if="view.final || live">{{ game?.away_score }}</span>
                <span class="font-normal text-muted-foreground">@</span>
                <img
                    v-if="game?.home_team?.logo_url"
                    :src="game.home_team.logo_url"
                    alt=""
                    class="h-6 w-6 object-contain"
                />
                <span>{{ teamLabel(game?.home_team) }}</span>
                <span v-if="view.final || live">{{ game?.home_score }}</span>
            </div>
            <span class="shrink-0 text-xs text-muted-foreground">{{
                view.final ? 'Final' : live ? 'Live' : kickoffLabel(prediction)
            }}</span>
        </div>
        <div class="mt-3 grid grid-cols-2 gap-3">
            <div>
                <div class="text-xs text-muted-foreground">
                    {{ live || view.final ? 'Pregame winner' : 'Model winner' }}
                </div>
                <div class="mt-0.5 text-base font-semibold">
                    {{ view.winner }}
                    <span
                        class="text-sm font-medium text-sky-600 dark:text-sky-300"
                        >{{ percent(view.winnerProbability) }}</span
                    >
                </div>
            </div>
            <div>
                <div class="text-xs text-muted-foreground">
                    {{
                        view.historicalMarket
                            ? 'Recorded spread lean'
                            : 'Spread lean'
                    }}
                </div>
                <div class="mt-0.5 text-base font-semibold">
                    {{ view.spreadLean }}
                </div>
            </div>
        </div>
        <div class="mt-3 flex items-center justify-between gap-2 text-xs">
            <span
                v-if="view.result"
                :class="
                    prediction.winner_correct === true
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : 'text-muted-foreground'
                "
                >{{ view.result }}</span
            >
            <span
                v-else
                class="inline-flex items-center gap-1.5"
                :class="
                    view.researchStatus === 'reviewed'
                        ? 'text-muted-foreground'
                        : 'text-amber-700 dark:text-amber-300'
                "
            >
                <span
                    aria-hidden="true"
                    class="h-1.5 w-1.5 rounded-full"
                    :class="
                        view.researchStatus === 'reviewed'
                            ? 'bg-emerald-500'
                            : 'bg-amber-500'
                    "
                />{{ view.researchLabel }}
            </span>
            <span class="inline-flex items-center gap-1 text-muted-foreground"
                >Details <ChevronRight class="h-3.5 w-3.5"
            /></span>
        </div>
    </button>
</template>
